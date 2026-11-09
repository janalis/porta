<?php

declare(strict_types=1);

namespace Porta\Plugin\Lifecycle;

use Porta\Config\PortaHome;
use Porta\Config\WorkspaceTrust;
use Porta\I18n\Translator;
use Porta\Plugin\Runtime\PluginLoader;
use Porta\Plugin\Runtime\PluginManifest;
use Porta\Support\AsyncProcess;
use Porta\Support\Files;
use Porta\Support\JsonFile;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\ExecutableFinder;

/**
 * Installs and removes plugin PACKAGES under `<project>/.porta/plugins` and
 * `<globalBase>/.porta/plugins` — every source kind (a Packagist `vendor/name`,
 * a git URL, or a local directory) is resolved through a Porta-owned Composer
 * project at `<scopeBase>/.porta/composer.json` ({@see PluginComposerProject}),
 * and `.porta/plugins/<name>` ends up a SYMLINK to wherever composer actually
 * installed the package (its own default location,
 * `<scopeBase>/.porta/vendor/<vendor>/<project>`). Activation of the installed
 * plugin is the caller's concern — this class only manages the directories.
 *
 * There is deliberately no `composer/installers`-style custom install-path
 * plugin in play: `composer/installers`' own top-level installer only
 * recognizes a fixed, hardcoded list of package types, and does not let a
 * project register a new one — the only package that adds that
 * (`composer-installers-extender`) is unmaintained and pinned to a
 * `composer/installers` major Porta doesn't want to depend on. Composer's
 * plain default install location plus a symlink gets the same result (a real,
 * versioned package on disk, addressable by directory name everywhere the
 * rest of the plugin system expects) with no such dependency at all.
 *
 * A plugin that ships a `composer.json` gets ITS OWN dependencies installed,
 * once, in ITS OWN directory — never in the user's project, where
 * {@see ToolInstaller} runs and where a plugin's libraries have no business.
 * That is also why this cannot reuse ToolInstaller: same command, different
 * tree, opposite meaning. This inner mechanism ({@see installDependencies()},
 * {@see removeDependencies()}) is unrelated to, and unaffected by, how the
 * plugin's own directory came to exist.
 */
#[Autoconfigure(constructor: 'create')]
final class PluginInstaller
{
    /**
     * Written inside a vendor tree Porta's own composer run created, and the
     * only thing that authorises deleting one again.
     *
     * Without it, disabling a plugin that ships or commits its `vendor/` would
     * delete tracked files on a single keypress — and a plugin directory is
     * the user's, not ours.
     */
    public const string MARKER = 'vendor/.porta-installed';

    /** Composer's own package-name shape: `vendor/project`, lowercase. */
    private const string PACKAGE_NAME_PATTERN = '#^[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9]([_.-]?[a-z0-9]+)*$#';

    /** Single-flight: the git/composer subprocess in progress, if any. */
    private ?AsyncProcess $running = null;

    /**
     * @param (\Closure(): ?string)|null $gitBinary      looked up at each use, as is $composerBinary: one installed
     *                                                  mid-session (from the composer plugin's Tools row, say) works with no restart
     * @param (\Closure(): ?string)|null $composerBinary
     */
    public function __construct(
        private readonly string $rootDir,
        private readonly ?string $globalBase,
        private readonly Translator $translator,
        private readonly ?\Closure $gitBinary,
        private readonly ?string $embeddedDir = null,
        private readonly ?\Closure $composerBinary = null,
        private readonly PluginComposerProject $project = new PluginComposerProject(),
        private readonly Filesystem $fs = new Filesystem(),
        /** @var (\Closure(): bool)|null whether the project is trusted; null asks {@see WorkspaceTrust} */
        private readonly ?\Closure $trust = null,
    ) {}

    /**
     * Whether composer may run in the project's own `.porta/`. It runs the
     * `composer.json` there — its scripts, the composer plugins it allows —
     * and in a project not trusted that file is the clone's: installing a
     * plugin "in this project" would run whatever it names.
     */
    private function mayRunComposerIn(InstallScope $scope): bool
    {
        return InstallScope::Project !== $scope || ($this->trust ?? fn (): bool => WorkspaceTrust::fromEnvironment()->isTrusted($this->rootDir))();
    }

    private function composerRefused(): string
    {
        return $this->translator->trans('%tool% runs code of the project\'s own: trust the project to use it', ['%tool%' => 'composer']);
    }

    /** The production wiring: plugin dirs and the git/composer binaries resolved from the environment. */
    public static function create(string $rootDir, Translator $translator): self
    {
        $finder = new ExecutableFinder();

        return new self($rootDir, PortaHome::base(), $translator, static fn (): ?string => $finder->find('git'), PluginLoader::embeddedDir(), static fn (): ?string => $finder->find('composer'));
    }

    private function git(): ?string
    {
        return null === $this->gitBinary ? null : ($this->gitBinary)();
    }

    private function composer(): ?string
    {
        return null === $this->composerBinary ? null : ($this->composerBinary)();
    }

    /**
     * Install a plugin PACKAGE from a directory path, a git URL, or a
     * `vendor/name` package, into the scope's plugin dir. Exactly one $onDone
     * call: a translated error, or the installed porta.php path.
     *
     * @param \Closure(?string, ?string): void $onDone  (?error, ?pluginFile)
     * @param string|null                     $version a `vendor/name` package's exact release — the one the
     *        store checked runs here; null lets composer pick the newest
     */
    public function install(string $source, InstallScope $scope, \Closure $onDone, ?string $version = null): void
    {
        $root = null === $this->running ? $this->rootFor($scope) : null;
        if (null === $root || !$this->mayRunComposerIn($scope)) {
            $this->fail($onDone, match (true) {
                null !== $this->running => $this->translator->trans('Another install is already running'),
                null === $root => $this->translator->trans('Cannot install globally (no home directory)'),
                default => $this->composerRefused(),
            });

            return;
        }
        $base = $root.'/.porta/plugins';

        // An explicit URL always clones — is_dir() alone would misroute
        // file:// URLs into the path branch via PHP's stream wrapper.
        $isUrl = 1 === \preg_match('#^(?:[a-z][a-z0-9+.-]*://|git@)#i', $source);
        $isDir = !$isUrl && \is_dir($source);
        $isPackageName = !$isUrl && !$isDir && 1 === \preg_match(self::PACKAGE_NAME_PATTERN, $source);

        // A path or a git URL has no releases to pick from: only a package name takes $version.
        $require = match (true) {
            $isDir => $this->requireByPath(...),
            $isPackageName => fn (string $source, string $base, string $root, \Closure $onDone) => $this->requireByName($source, $base, $root, $onDone, $version),
            $isUrl => $this->requireByGitUrl(...),
            default => null,
        };
        if (null === $require) {
            $this->fail($onDone, $this->translator->trans('"%source%" is not a git URL, a local directory, or a vendor/name package', ['%source%' => $source]));

            return;
        }
        $require($source, $base, $root, $onDone);
    }

    /**
     * Report a failed install: invoke $onDone with the error message and no
     * plugin file. Extracted so the null second argument stays a variable
     * (named arguments are not allowed on closure calls).
     *
     * @param \Closure(?string, ?string): void $onDone
     */
    private function fail(\Closure $onDone, string $message): void
    {
        $noFile = null;
        $onDone($message, $noFile);
    }

    /**
     * Report that {@see PluginComposerProject}'s composer.json could not be
     * written (permissions, disk full) — before any composer/git subprocess
     * runs, so the user sees the actual cause instead of a confusing
     * downstream composer failure.
     *
     * @param \Closure(?string, ?string): void $onDone
     */
    private function failScaffoldWrite(\Closure $onDone, string $root): void
    {
        $this->fail($onDone, $this->translator->trans('Could not write %file%', ['%file%' => $this->project->composerJsonPath($root)]));
    }

    /**
     * A local directory declaring its own `composer.json` (`name` +
     * `type: "porta-plugin"`): registered as a `path` repository (composer
     * symlinks it, so a plugin under active local development is picked up
     * live) and required with `:@dev`, since an unversioned path repository
     * has no other resolvable constraint.
     *
     * @param \Closure(?string, ?string): void $onDone
     */
    private function requireByPath(string $source, string $base, string $root, \Closure $onDone): void
    {
        $packageName = $this->pathPackage($source, $onDone);
        $dest = null === $packageName ? null : $this->destination($base, $packageName, $onDone);
        if (null === $packageName || null === $dest) {
            return;
        }

        $composerDir = $this->project->ensureScaffold($root);
        if (null === $composerDir || !$this->project->addPathRepository($root, $packageName, (string) \realpath($source))) {
            $this->failScaffoldWrite($onDone, $root);

            return;
        }
        $this->fs->mkdir($base);
        $ok = $this->startComposer($composerDir, ['require', $packageName.':@dev', '--no-interaction', '--no-progress', '--no-audit'], function (int $code) use (
            $root,
            $packageName,
            $dest,
            $onDone,
        ): void {
            if (0 !== $code) {
                $this->fail($onDone, $this->translator->trans('composer require failed (exit code %code%)', ['%code%' => $code]));

                return;
            }
            $this->linkAndFinish($root, $packageName, $dest, $onDone);
        });
        if (!$ok) {
            $this->fail($onDone, $this->translator->trans('composer is not available'));
        }
    }

    /**
     * The package name a local plugin directory declares — null (with $onDone
     * already called) when it has no porta.php or no Porta plugin manifest.
     *
     * @param \Closure(?string, ?string): void $onDone
     */
    private function pathPackage(string $source, \Closure $onDone): ?string
    {
        $hasPluginFile = \is_file($source.'/porta.php');
        [$packageName, $type] = $hasPluginFile ? $this->packageManifest($source) : [null, null];
        $refusal = match (true) {
            !$hasPluginFile => $this->translator->trans('No porta.php found in %source%', ['%source%' => $source]),
            null === $packageName || 'porta-plugin' !== $type
                => $this->translator->trans('No installable plugin package found in %source% (needs a composer.json with "name" and "type": "porta-plugin")', ['%source%' => $source]),
            default => null,
        };
        if (null !== $refusal) {
            $this->fail($onDone, $refusal);
        }

        return null === $refusal ? $packageName : null;
    }

    /**
     * A plain Packagist `vendor/name`: no repository entry needed, composer
     * resolves it normally. There is no way to check the landed package IS a
     * Porta plugin before the network round-trip, so this is the one flow
     * that verifies AFTER requiring — and rolls back with a best-effort
     * `composer remove` on a mismatch, since that is a "wrong package" user
     * error, not the transient-network case {@see finishInstall()} protects.
     *
     * @param \Closure(?string, ?string): void $onDone
     */
    private function requireByName(string $source, string $base, string $root, \Closure $onDone, ?string $version = null): void
    {
        $dest = $this->destination($base, $source, $onDone);
        if (null === $dest) {
            return;
        }

        $composerDir = $this->project->ensureScaffold($root);
        if (null === $composerDir) {
            $this->failScaffoldWrite($onDone, $root);

            return;
        }
        $this->fs->mkdir($base);
        $packageName = $source;
        $constraint = null === $version ? $packageName : $packageName.':'.\ltrim($version, characters: 'v');
        $ok = $this->startComposer($composerDir, ['require', $constraint, '--no-interaction', '--no-progress', '--no-audit'], function (int $code) use (
            $composerDir,
            $root,
            $packageName,
            $dest,
            $onDone,
        ): void {
            if (0 !== $code) {
                $this->fail($onDone, $this->translator->trans('composer require failed (exit code %code%)', ['%code%' => $code]));

                return;
            }
            $landed = $this->project->packageDir($root, $packageName);
            [, $type] = $this->packageManifest($landed);
            if ('porta-plugin' !== $type || !\is_file($landed.'/porta.php')) {
                $this->startComposer($composerDir, ['remove', $packageName, '--no-interaction'], static function (int $exitCode): void {});
                $this->fail($onDone, $this->translator->trans('"%source%" is not a Porta plugin package', ['%source%' => $packageName]));

                return;
            }
            $this->linkAndFinish($root, $packageName, $dest, $onDone);
        });
        if (!$ok) {
            $this->fail($onDone, $this->translator->trans('composer is not available'));
        }
    }

    /**
     * A git URL: composer cannot "require whatever is at this URL" without
     * knowing the package name first, so this shallow-clones to a SCRATCH
     * directory (outside `.porta`, discarded once read) purely to learn the
     * `composer.json` `name`/`type` and the checked-out branch, then registers
     * a `vcs` repository and requires that exact branch — parity with "clone
     * whatever HEAD is today", not a new "let composer pick a release" ability.
     *
     * @param \Closure(?string, ?string): void $onDone
     */
    private function requireByGitUrl(string $source, string $base, string $root, \Closure $onDone): void
    {
        $git = $this->git();
        if (null === $git) {
            $this->fail($onDone, $this->translator->trans('git is not available'));

            return;
        }
        $scratch = \sys_get_temp_dir().'/porta-plugin-peek-'.\bin2hex(\random_bytes(8));
        $this->running = new AsyncProcess(
            [$git, 'clone', '--depth', '1', '--', $source, $scratch],
            $this->rootDir,
            env: ['GIT_TERMINAL_PROMPT' => '0'] + \getenv(),
            timeout: AsyncProcess::EXTERNAL_TOOL_TIMEOUT,
        );
        $started = $this->running->start(function (int $code) use ($git, $scratch, $base, $root, $source, $onDone): void {
            $this->running = null;
            if (0 !== $code) {
                $this->fs->remove($scratch);
                $this->fail($onDone, $this->translator->trans('git clone failed (exit code %code%)', ['%code%' => $code]));

                return;
            }
            if (!\is_file($scratch.'/porta.php')) {
                $this->fs->remove($scratch);
                $this->fail($onDone, $this->translator->trans('The repository contains no porta.php'));

                return;
            }
            [$packageName, $type] = $this->packageManifest($scratch);
            if (null === $packageName || 'porta-plugin' !== $type) {
                $this->fs->remove($scratch);
                $this->fail($onDone, $this->translator->trans('No installable plugin package found in %source% (needs a composer.json with "name" and "type": "porta-plugin")', [
                    '%source%' => $source,
                ]));

                return;
            }
            $this->readBranch($git, $scratch, function (?string $branch) use ($scratch, $base, $root, $packageName, $source, $onDone): void {
                $this->fs->remove($scratch);
                if (null === $branch) {
                    // A remote whose HEAD is genuinely unresolvable to a
                    // branch (no refs at all, or an exotic detached default)
                    // — git's own default-branch heuristic resolves a normal
                    // repository's clone to a branch even from a detached
                    // source checkout, so this has no practical local
                    // reproduction.
                    // @codeCoverageIgnoreStart
                    $this->fail($onDone, $this->translator->trans('Cannot determine the branch to install from %source%', ['%source%' => $source]));

                    return;

                    // @codeCoverageIgnoreEnd
                }
                $dest = $this->destination($base, $packageName, $onDone);
                if (null === $dest) {
                    return;
                }

                $composerDir = $this->project->ensureScaffold($root);
                if (null === $composerDir || !$this->project->addVcsRepository($root, $packageName, $source)) {
                    $this->failScaffoldWrite($onDone, $root);

                    return;
                }
                $this->fs->mkdir($base);
                $ok = $this->startComposer($composerDir, ['require', $packageName.':dev-'.$branch, '--no-interaction', '--no-progress', '--no-audit'], function (int $code) use (
                    $root,
                    $packageName,
                    $dest,
                    $onDone,
                ): void {
                    if (0 !== $code) {
                        $this->fail($onDone, $this->translator->trans('composer require failed (exit code %code%)', ['%code%' => $code]));

                        return;
                    }
                    $this->linkAndFinish($root, $packageName, $dest, $onDone);
                });
                if (!$ok) {
                    $this->fail($onDone, $this->translator->trans('composer is not available'));
                }
            });
        });
        if (!$started) {
            // proc_open failure is environmental (fd exhaustion)
            // @codeCoverageIgnoreStart
            $this->running = null;
            $this->fail($onDone, $this->translator->trans('git is not available'));

            // @codeCoverageIgnoreEnd
        }
    }

    /**
     * The checked-out branch name of a scratch clone, or null when none could
     * be determined (a detached/tag-pointing HEAD reports `HEAD` literally,
     * which is not a usable `dev-<branch>` constraint).
     *
     * @param \Closure(?string): void $onBranch
     */
    private function readBranch(string $git, string $scratch, \Closure $onBranch): void
    {
        $this->running = new AsyncProcess([$git, 'rev-parse', '--abbrev-ref', 'HEAD'], $scratch);
        $started = $this->running->start(function (int $code, string $stdout) use ($onBranch): void {
            $this->running = null;
            $branch = \trim($stdout);
            $onBranch(0 === $code && '' !== $branch && 'HEAD' !== $branch ? $branch : null);
        });
        if (!$started) {
            // proc_open failure is environmental (fd exhaustion)
            // @codeCoverageIgnoreStart
            $this->running = null;
            $onBranch(null);

            // @codeCoverageIgnoreEnd
        }
    }

    /** Symlink `$dest` to wherever composer landed `$packageName`, then run the install's shared tail. */
    private function linkAndFinish(string $root, string $packageName, string $dest, \Closure $onDone): void
    {
        $landed = $this->project->packageDir($root, $packageName);
        $this->fs->symlink($landed, $dest);
        $this->finishInstall($dest, $onDone);
    }

    /**
     * The install's tail: its dependencies, then the one $onDone.
     *
     * A composer failure does NOT undo the install — the directory is exactly
     * what the user asked for, and the next boot installs it or declines it by
     * name ({@see \Porta\Plugin\Runtime\PluginManifest::needsInstall}).
     * Removing it would throw away a good install over a transient network error.
     *
     * @param \Closure(?string, ?string): void $onDone
     */
    private function finishInstall(string $dest, \Closure $onDone): void
    {
        $pluginFile = $dest.'/porta.php';
        $outcome = $this->installDependencies($dest, function (int $code) use ($pluginFile, $onDone): void {
            if (0 !== $code) {
                $this->fail($onDone, $this->translator->trans('Installed, but composer install failed (exit code %code%)', ['%code%' => $code]));

                return;
            }
            $onDone(null, $pluginFile);
        });
        if (DependencyInstall::Started === $outcome) {
            return;
        }
        if (DependencyInstall::NotNeeded === $outcome) {
            $onDone(null, $pluginFile);

            return;
        }
        // Busy and NoComposer are both unreachable here: every require*()
        // method already ran a composer subcommand successfully (clearing the
        // slot) before ever calling finishInstall, so $this->composerBinary
        // is guaranteed non-null and the slot guaranteed free.
        // @codeCoverageIgnoreStart
        $this->fail($onDone, $this->translator->trans('Installed, but composer is not available to install its dependencies'));

        // @codeCoverageIgnoreEnd
    }

    /**
     * `composer install --no-dev` in a plugin's OWN directory, when its manifest
     * says it owes one.
     *
     * The result comes back synchronously because the caller has to know
     * whether to wait: only {@see DependencyInstall::Started} is followed by
     * $onExit, and only then exactly once. The MESSAGE is the caller's, not
     * this method's — "installed, but…" and "enabled, but…" are different
     * sentences about the same exit code.
     *
     * The guard order is deliberate. `NotNeeded` is answered FIRST, so a plugin
     * that owes nothing is never refused because someone else's install holds
     * the slot — which is what lets a whole requirement chain of
     * dependency-free plugins enable during an install.
     *
     * @param \Closure(int): void $onExit composer's exit code
     */
    public function installDependencies(string $pluginDir, \Closure $onExit): DependencyInstall
    {
        // The SAME predicate the loader declines a plugin with, so the two can
        // never disagree about whether an install is owed: a composer.json
        // whose only requirement is `php` asks for nothing, and shelling out
        // for it would be a subprocess to answer no.
        // composer is looked up in the last arm only: NotNeeded and Busy answer without a PATH walk.
        return match (true) {
            !PluginManifest::beside($pluginDir.'/porta.php')->needsInstall() => DependencyInstall::NotNeeded,
            null !== $this->running => DependencyInstall::Busy,
            default => $this->runComposerInstall($this->composer(), $pluginDir, $onExit),
        };
    }

    /**
     * Start `composer install` in $pluginDir, taking the single process slot.
     *
     * @param \Closure(int): void $onExit composer's exit code
     */
    private function runComposerInstall(?string $composerBinary, string $pluginDir, \Closure $onExit): DependencyInstall
    {
        if (null === $composerBinary) {
            return DependencyInstall::NoComposer;
        }
        $this->running = new AsyncProcess([$composerBinary, 'install', '--no-dev', '--no-interaction'], $pluginDir, timeout: AsyncProcess::EXTERNAL_TOOL_TIMEOUT);
        // The slot is cleared BEFORE the callback runs, with no loop turn in
        // between, so a caller draining a queue can start the next install from
        // inside it and never see Busy.
        $started = $this->running->start(function (int $code) use ($pluginDir, $onExit): void {
            $this->running = null;
            // This tree is now ours to delete again. Written only on success:
            // a half-installed vendor is not one we should later claim we
            // made. An unwritable directory just means we never claim it —
            // the plugin works either way, and the next disable leaves the
            // tree standing rather than deleting what it cannot prove is ours.
            if (0 === $code && \is_dir($pluginDir.'/vendor')) {
                $this->fs->dumpFile($pluginDir.'/'.self::MARKER, "Porta installed this directory; disabling the plugin removes it.\n");
            }
            $onExit($code);
        });
        if (!$started) {
            // proc_open failure is environmental (fd exhaustion)
            // @codeCoverageIgnoreStart
            $this->running = null;

            // @codeCoverageIgnoreEnd
        }

        return $started ? DependencyInstall::Started : DependencyInstall::NoComposer;
    }

    /**
     * Delete the vendor tree Porta installed in $pluginDir.
     *
     * Only ours: the marker {@see MARKER} is what distinguishes a tree this
     * class created from one the plugin shipped, committed, or built by hand.
     * `composer.lock` is deliberately left behind — Porta did not write it, a
     * committed one would show up as a dirty `git status` after a checkbox,
     * and keeping it makes the next install resolve to the same versions.
     *
     * Synchronous, like the whole-directory {@see remove()} on the same page:
     * a deferred delete would race a re-enable, and ordering the two is worth
     * more than the stutter.
     */
    public function removeDependencies(string $pluginDir): DependencyRemoval
    {
        $outcome = match (true) {
            !\is_file($pluginDir.'/'.self::MARKER) => DependencyRemoval::NotOurs,
            // Deleting a tree composer is writing into leaves a half-vendor
            // that needsInstall() would then call complete.
            null !== $this->running => DependencyRemoval::Busy,
            default => DependencyRemoval::Removed,
        };
        if (DependencyRemoval::Removed === $outcome) {
            try {
                $this->fs->remove($pluginDir.'/vendor');
            } catch (IOExceptionInterface) {
                $outcome = DependencyRemoval::Failed;
            }
        }

        return $outcome;
    }

    /**
     * Remove an installed plugin. A composer-managed entry (`.porta/plugins/<name>`
     * is a SYMLINK) runs `composer remove` in the driving project first, then
     * unlinks the symlink — the real `.porta/vendor/...` target is untouched,
     * since {@see Filesystem::remove()} unlinks a symlink rather than
     * recursing into what it points at. A pre-existing git-clone/copy install
     * (not a symlink) keeps today's exact behavior: a synchronous whole-directory
     * delete, nothing forces migrating it.
     *
     * @param \Closure(?string): void $onDone the translated error, or null on success
     */
    public function remove(string $name, InstallScope $scope, \Closure $onDone): void
    {
        $root = InstallScope::Embedded === $scope ? null : $this->rootFor($scope);
        if (null === $root || !$this->mayRunComposerIn($scope)) {
            $onDone(match (true) {
                InstallScope::Embedded === $scope => $this->translator->trans('Built-in plugins cannot be removed'),
                null === $root => $this->translator->trans('Cannot remove "%name%"', ['%name%' => $name]),
                default => $this->composerRefused(),
            });

            return;
        }
        $dest = $root.'/.porta/plugins/'.$name;
        if (!\is_link($dest)) {
            $this->finishRemoval($dest, $name, $onDone);

            return;
        }
        $this->removeByComposer($root, $dest, $name, $onDone);
    }

    /**
     * `composer remove` the package a symlinked plugin entry points at, then
     * unlink the entry.
     *
     * @param \Closure(?string): void $onDone
     */
    private function removeByComposer(string $root, string $dest, string $name, \Closure $onDone): void
    {
        [$packageName] = $this->packageManifest($dest);
        $composerDir = null === $packageName ? null : $this->project->ensureScaffold($root);
        if (null === $packageName || null === $composerDir) {
            $onDone($this->translator->trans('Cannot remove "%name%"', ['%name%' => $name]));

            return;
        }
        $ok = $this->startComposer($composerDir, ['remove', $packageName, '--no-interaction'], function (int $code) use ($dest, $name, $onDone): void {
            if (0 !== $code) {
                $onDone($this->translator->trans('Cannot remove "%name%"', ['%name%' => $name]));

                return;
            }
            $this->finishRemoval($dest, $name, $onDone);
        });
        if (!$ok) {
            $onDone($this->translator->trans('Cannot remove "%name%"', ['%name%' => $name]));
        }
    }

    /** @param \Closure(?string): void $onDone */
    private function finishRemoval(string $dest, string $name, \Closure $onDone): void
    {
        try {
            $this->fs->remove($dest);
        } catch (IOExceptionInterface) {
            $onDone($this->translator->trans('Cannot remove "%name%"', ['%name%' => $name]));

            return;
        }
        $onDone(null);
    }

    /**
     * Every installed plugin directory in precedence order — project, global,
     * then the plugins bundled with Porta (scope {@see InstallScope::Embedded}, never removable).
     * A name present in several scopes is listed once per copy (the loader's
     * shadowing only affects which one activates). When a scanned dir COINCIDES
     * with the embedded dir (e.g. PORTA_EMBEDDED_PLUGINS pointed at a project's
     * own plugin dir — the bundled source at `<package>/plugins` no longer
     * overlaps by default), those entries classify as embedded so the bundled
     * plugins stay non-removable there too.
     *
     * @return list<array{name: string, scope: InstallScope, dir: string}>
     */
    public function installed(): array
    {
        $embeddedReal = null;
        if (null !== $this->embeddedDir) {
            $resolvedEmbedded = \realpath($this->embeddedDir);
            $embeddedReal = $resolvedEmbedded ? $resolvedEmbedded : null;
        }

        $plugins = [];
        $embeddedSeen = [];
        foreach ([[InstallScope::Project, $this->rootDir.'/.porta/plugins'], [InstallScope::Global, null !== $this->globalBase ? $this->globalBase.'/.porta/plugins' : null]] as [
            $scope,
            $pluginsDir,
        ]) {
            if (null === $pluginsDir) {
                continue;
            }
            $isEmbedded = null !== $embeddedReal && \realpath($pluginsDir) === $embeddedReal;
            foreach (Files::childFiles($pluginsDir, 'porta.php') as $file) {
                $dir = \dirname($file);
                $name = \basename($dir);
                $plugins[] = ['name' => $name, 'scope' => $isEmbedded ? InstallScope::Embedded : $scope, 'dir' => $dir];
                if ($isEmbedded) {
                    $embeddedSeen[$name] = true;
                }
            }
        }
        if (null !== $this->embeddedDir) {
            foreach (Files::childFiles($this->embeddedDir, 'porta.php') as $file) {
                $dir = \dirname($file);
                $name = \basename($dir);
                if (!\array_key_exists($name, $embeddedSeen)) {
                    $plugins[] = ['name' => $name, 'scope' => InstallScope::Embedded, 'dir' => $dir];
                }
            }
        }

        return $plugins;
    }

    /**
     * Every plugin installed as a package — `.porta/plugins/<name>` a symlink
     * into the scope's composer project — with the package it is, the version
     * composer installed, and whether that came from Packagist (the only kind
     * a newer release updates). A hand-placed directory is no package.
     *
     * @return list<array{name: string, scope: InstallScope, dir: string, package: string, version: string, packagist: bool}>
     */
    public function packages(): array
    {
        $packages = [];
        $versions = [];
        foreach ($this->installed() as $copy) {
            $root = InstallScope::Embedded === $copy['scope'] || !\is_link($copy['dir']) ? null : $this->rootFor($copy['scope']);
            [$package] = null === $root ? [null] : $this->packageManifest($copy['dir']);
            if (null === $root || null === $package) {
                continue;
            }
            $versions[$copy['scope']->value] ??= $this->project->installedVersions($root);
            $packages[] = [
                'name' => $copy['name'],
                'scope' => $copy['scope'],
                'dir' => $copy['dir'],
                'package' => $package,
                'version' => $versions[$copy['scope']->value][$package] ?? '',
                'packagist' => $this->project->fromPackagist($root, $package),
            ];
        }

        return $packages;
    }

    /**
     * Update the package-installed plugin $name in $scope: to exactly
     * $version and whatever that release needs when one is named (a new
     * constraint, so a new major is reachable — the store offers the newest
     * release that runs here, and composer must not pick a newer one that
     * does not), or as far as its constraint goes.
     * Its own dependencies are installed again after, as on install:
     * composer replaced its directory.
     *
     * The plugin's directory stays where it was, so nothing needs relinking.
     * Swapping a RUNNING plugin for the new code is the caller's
     * ({@see \Porta\Plugin\Lifecycle\PluginLifecycle::activateInstalled()}).
     *
     * @param \Closure(?string): void $onDone the translated error, or null on success
     */
    public function update(string $name, InstallScope $scope, ?string $version, \Closure $onDone): void
    {
        $root = InstallScope::Embedded === $scope ? null : $this->rootFor($scope);
        $dest = null === $root ? null : $root.'/.porta/plugins/'.$name;
        [$package] = null === $dest || !\is_link($dest) ? [null] : $this->packageManifest($dest);
        $refusal = match (true) {
            null !== $this->running => $this->translator->trans('Another install is already running'),
            InstallScope::Embedded === $scope => $this->translator->trans('Built-in plugins update with Porta'),
            !$this->mayRunComposerIn($scope) => $this->composerRefused(),
            null === $package => $this->translator->trans('"%name%" was not installed from a package, so Porta cannot update it', ['%name%' => $name]),
            default => null,
        };
        $composerDir = null === $refusal && null !== $root ? $this->project->ensureScaffold($root) : null;
        if (null !== $refusal || null === $composerDir || null === $dest || null === $package) {
            $onDone($refusal ?? $this->translator->trans('Cannot update "%name%"', ['%name%' => $name]));

            return;
        }
        $args = null === $version
            ? ['update', $package, '--with-dependencies']
            : ['require', $package.':'.\ltrim($version, characters: 'v'), '--update-with-dependencies'];
        $ok = $this->startComposer($composerDir, [...$args, '--no-interaction', '--no-progress', '--no-audit'], function (int $code) use ($dest, $name, $onDone): void {
            if (0 !== $code) {
                $onDone($this->translator->trans('Cannot update "%name%"', ['%name%' => $name]));

                return;
            }
            $this->finishInstall($dest, static function (?string $error) use ($onDone): void {
                $onDone($error);
            });
        });
        if (!$ok) {
            $onDone($this->translator->trans('composer is not available'));
        }
    }

    /**
     * Every installed plugin's own documentation folder (`<plugin>/docs`),
     * resolved, keyed to the plugin's name — shadowed copies included, since
     * Porta's docs link to the bundled copy whichever one loads.
     *
     * @return array<string, string> docs dir => plugin name
     */
    public function docsRoots(): array
    {
        $roots = [];
        foreach ($this->installed() as $plugin) {
            $docs = $plugin['dir'].'/docs';
            if (!\is_dir($docs)) {
                continue;
            }
            $roots[Files::canonical($docs)] ??= $plugin['name'];
        }

        return $roots;
    }

    /** The front page of $name's documentation — in the copy that loads — or null when it ships none. */
    public function docsIndex(string $name): ?string
    {
        $plugin = \array_find($this->installed(), static fn (array $plugin): bool => $name === $plugin['name']);
        if (null === $plugin || !\is_file($plugin['dir'].'/docs/README.md')) {
            return null;
        }

        return $plugin['dir'].'/docs/README.md';
    }

    /** The scope's root directory (project root or global base), or null when the global base is unknown. */
    private function rootFor(InstallScope $scope): ?string
    {
        return InstallScope::Global === $scope ? $this->globalBase : $this->rootDir;
    }

    /**
     * The `name`/`type` fields of $dir's composer.json, both null when absent
     * or not a Porta plugin manifest.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function packageManifest(string $dir): array
    {
        $manifest = JsonFile::read($dir.'/composer.json');
        $name = \is_string($manifest['name'] ?? null) ? $manifest['name'] : null;
        $type = \is_string($manifest['type'] ?? null) ? $manifest['type'] : null;

        return [$name, $type];
    }

    /**
     * The plugin directory NAME half of a composer `vendor/project` package
     * name, less a leading `porta-`: Packagist names are global, so a plugin
     * package says what it is for (`janalis/porta-git`), and it still lands
     * as `git` — the name its settings, `requires` and a shadowed bundled
     * copy all go by.
     */
    private function nameFromPackage(string $packageName): string
    {
        $slash = \strpos($packageName, needle: '/');
        $project = false === $slash ? $packageName : \substr($packageName, $slash + 1);

        return \str_starts_with($project, 'porta-') ? \substr($project, \strlen('porta-')) : $project;
    }

    /**
     * $packageName's directory-name half, validated against
     * {@see PluginLoader::NAME_PATTERN} — null (with $onDone already called)
     * when it fails.
     *
     * @param \Closure(?string, ?string): void $onDone
     */
    private function validatedName(string $packageName, \Closure $onDone): ?string
    {
        $name = $this->nameFromPackage($packageName);
        if (1 !== \preg_match(PluginLoader::NAME_PATTERN, $name)) {
            $this->fail($onDone, $this->translator->trans('"%name%" is not a valid plugin name', ['%name%' => $name]));

            return null;
        }

        return $name;
    }

    /**
     * Where $packageName installs under $base — null (with $onDone already
     * called) when its name is not a valid plugin name or it is already there.
     *
     * @param \Closure(?string, ?string): void $onDone
     */
    private function destination(string $base, string $packageName, \Closure $onDone): ?string
    {
        $name = $this->validatedName($packageName, $onDone);

        return null === $name ? null : $this->destFor($base, $name, $onDone);
    }

    /**
     * `$base/$name`, refused (with $onDone already called) when it already
     * exists.
     *
     * @param \Closure(?string, ?string): void $onDone
     */
    private function destFor(string $base, string $name, \Closure $onDone): ?string
    {
        $dest = $base.'/'.$name;
        if (\is_dir($dest)) {
            $this->fail($onDone, $this->translator->trans('Plugin "%name%" is already installed', ['%name%' => $name]));

            return null;
        }

        return $dest;
    }

    /**
     * Run a composer subcommand in $cwd, taking the single process slot.
     * False means it never started — no composer binary, or (untestable)
     * proc_open failed — and $this->running is guaranteed null either way.
     *
     * @param list<string>        $args
     * @param \Closure(int): void $onExit
     */
    private function startComposer(string $cwd, array $args, \Closure $onExit): bool
    {
        $composer = $this->composer();
        if (null === $composer) {
            return false;
        }
        $this->running = new AsyncProcess([$composer, ...$args], $cwd, timeout: AsyncProcess::EXTERNAL_TOOL_TIMEOUT);
        $started = $this->running->start(function (int $code) use ($onExit): void {
            $this->running = null;
            $onExit($code);
        });
        if (!$started) {
            // proc_open failure is environmental (fd exhaustion)
            // @codeCoverageIgnoreStart
            $this->running = null;

            // @codeCoverageIgnoreEnd
        }

        return $started;
    }
}
