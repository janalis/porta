<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Plugin\Lifecycle;

use PHPUnit\Framework\TestCase;
use Porta\I18n\Translator;
use Porta\Plugin\Lifecycle\DependencyInstall;
use Porta\Plugin\Lifecycle\DependencyRemoval;
use Porta\Plugin\Lifecycle\InstallScope;
use Porta\Plugin\Lifecycle\PluginComposerProject;
use Porta\Plugin\Lifecycle\PluginInstaller;
use Porta\Tests\Support\FakeComposer;
use Porta\Tests\Support\GitBinary;
use Porta\Tests\Support\Presence;
use Porta\Tests\Support\PumpsLoop;
use Porta\Tests\Support\TempProject;

final class PluginInstallerTest extends TestCase
{
    use GitBinary;
    use PumpsLoop;

    private TempProject $project;
    private TempProject $global;

    protected function setUp(): void
    {
        $this->project = new TempProject();
        $this->global = new TempProject();
    }

    private function installer(?string $globalBase = 'own', ?string $git = null, ?string $composer = null): PluginInstaller
    {
        return new PluginInstaller($this->project->dir, 'own' === $globalBase ? $this->global->dir : $globalBase, new Translator('en'), $git, null, $composer);
    }

    /**
     * @param array<string, array{plugin?: string, composerJson?: string}> $packages
     */
    private function fakeComposer(int $exitCode = 0, array $packages = []): string
    {
        return FakeComposer::write($this->project->dir.'/fakebin-'.\bin2hex(\random_bytes(3)), $exitCode, $packages);
    }

    /** Runs install() and pumps the loop until the callback fires; returns [error, file]. */
    private function install(PluginInstaller $installer, string $source, InstallScope $scope = InstallScope::Project): array
    {
        $result = null;
        $installer->install($source, $scope, static function (?string $error, ?string $file) use (&$result): void {
            $result = [$error, $file];
        });
        // PumpsLoop's await, not a hand-rolled copy of it: twice the budget,
        // and a named timeout instead of "null is not null" when a subprocess
        // loses a scheduling race on a loaded machine.
        $this->await(static function () use (&$result): bool {
            return null !== $result;
        }, what: 'the install callback');
        $this->assertNotNull($result);

        return $result;
    }

    /** Runs remove() and pumps the loop until the callback fires; returns the translated error, or null on success. */
    private function remove(PluginInstaller $installer, string $name, InstallScope $scope = InstallScope::Project): ?string
    {
        $done = false;
        $result = null;
        $installer->remove($name, $scope, static function (?string $error) use (&$done, &$result): void {
            $done = true;
            $result = $error;
        });
        $this->await(static function () use (&$done): bool {
            return $done;
        }, what: 'the remove callback');

        return $result;
    }

    /**
     * A plugin source dir outside any scope base: `plugin.php`, and — unless
     * opted out — a `composer.json` declaring package `test/$name` with
     * `type: "porta-plugin"`, so the installed directory name derives to
     * `$name` exactly like the old copy-by-directory-basename behaviour did.
     */
    private function sourceDir(string $name, Presence $withPluginPhp = Presence::With, Presence $withManifest = Presence::With): string
    {
        $dir = $this->project->dir.'/src-plugins/'.$name;
        \mkdir($dir, permissions: 0o777, recursive: true);
        if (Presence::With === $withPluginPhp) {
            \file_put_contents($dir.'/plugin.php', "<?php\n// {$name}\n");
        }
        if (Presence::With === $withManifest) {
            \file_put_contents($dir.'/composer.json', (string) \json_encode(['name' => 'test/'.$name, 'type' => 'porta-plugin']));
        }

        return $dir;
    }

    private function git(): string
    {
        $this->skipWithoutGit();

        return (string) $this->whichGit();
    }

    /** A local git repo, with a valid plugin composer.json, usable as a clone URL. */
    private function repo(Presence $withPluginPhp): string
    {
        $dir = $this->sourceDir('repo-plugin', $withPluginPhp);
        $q = \escapeshellarg($dir);
        \exec("git -C {$q} init -q 2>/dev/null && git -C {$q} config user.email t@t && git -C {$q} config user.name t && git -C {$q} add -A && git -C {$q} commit -qm init --allow-empty 2>/dev/null");

        return $dir;
    }

    // --- install by local directory path ------------------------------------------------------

    public function testPathInstallLandsInTheChosenScopeAsASymlink(): void
    {
        $source = $this->sourceDir('demo');
        [$error, $file] = $this->install($this->installer(composer: $this->fakeComposer()), $source);
        $this->assertNull($error);
        $this->assertSame($this->project->dir.'/.porta/plugins/demo/plugin.php', $file);
        $this->assertFileExists((string) $file);
        $this->assertTrue(\is_link($this->project->dir.'/.porta/plugins/demo'), 'a composer-managed install is a symlink');

        [$error, $file] = $this->install($this->installer(composer: $this->fakeComposer()), $source, InstallScope::Global);
        $this->assertNull($error);
        $this->assertSame($this->global->dir.'/.porta/plugins/demo/plugin.php', $file);
    }

    public function testPathInstallGuards(): void
    {
        [$error] = $this->install($this->installer(composer: $this->fakeComposer()), $this->sourceDir('empty', withPluginPhp: Presence::Without));
        $this->assertStringContainsString('No plugin.php found in', (string) $error);

        [$error] = $this->install($this->installer(composer: $this->fakeComposer()), $this->sourceDir('nomanifest', withManifest: Presence::Without));
        $this->assertStringContainsString('No installable plugin package found in', (string) $error);

        $source = $this->sourceDir('demo');
        $this->install($this->installer(composer: $this->fakeComposer()), $source);
        [$error] = $this->install($this->installer(composer: $this->fakeComposer()), $source);
        $this->assertSame('Plugin "demo" is already installed', $error);

        [$error] = $this->install($this->installer(composer: $this->fakeComposer()), $this->sourceDir('.hidden'));
        $this->assertSame('".hidden" is not a valid plugin name', $error);

        [$error] = $this->install($this->installer(globalBase: null, composer: $this->fakeComposer()), $source, InstallScope::Global);
        $this->assertSame('Cannot install globally (no home directory)', $error);
    }

    public function testPathInstallWithoutComposerFails(): void
    {
        [$error] = $this->install($this->installer(), $this->sourceDir('demo'));
        $this->assertSame('composer is not available', $error);
    }

    public function testPathInstallReportsAComposerFailure(): void
    {
        [$error] = $this->install($this->installer(composer: $this->fakeComposer(3)), $this->sourceDir('demo'));
        $this->assertStringContainsString('composer require failed (exit code 3)', (string) $error);
    }

    public function testPathInstallFailsClearlyWhenTheScaffoldCannotBeWritten(): void
    {
        $source = $this->sourceDir('demo');
        $composer = $this->fakeComposer();
        \chmod($this->project->dir, permissions: 0o555);
        try {
            [$error] = $this->install($this->installer(composer: $composer), $source);
        } finally {
            \chmod($this->project->dir, permissions: 0o755);
        }
        $this->assertStringContainsString('Could not write', (string) $error);
        $this->assertStringContainsString('composer.json', (string) $error);
    }

    public function testPathInstallFailsClearlyWhenTheRepositoryCannotBeWritten(): void
    {
        $source = $this->sourceDir('demo');
        $composer = $this->fakeComposer();
        // Pre-create the scaffold (a real, prior ensureScaffold()) so THIS
        // call's ensureScaffold() finds the file already there and succeeds —
        // isolating the second write (addPathRepository) as the one that fails.
        new PluginComposerProject()->ensureScaffold($this->project->dir);
        \chmod($this->project->dir.'/.porta', permissions: 0o555);
        try {
            [$error] = $this->install($this->installer(composer: $composer), $source);
        } finally {
            \chmod($this->project->dir.'/.porta', permissions: 0o755);
        }
        $this->assertStringContainsString('Could not write', (string) $error);
        $this->assertStringContainsString('composer.json', (string) $error);
    }

    // --- install by vendor/name package --------------------------------------------------------

    public function testNameInstallLandsAsASymlink(): void
    {
        [$error, $file] = $this->install($this->installer(composer: $this->fakeComposer()), 'acme/mylint');
        $this->assertNull($error);
        $this->assertSame($this->project->dir.'/.porta/plugins/mylint/plugin.php', $file);
        $this->assertFileExists((string) $file);
        $this->assertTrue(\is_link($this->project->dir.'/.porta/plugins/mylint'));
    }

    public function testNameInstallGuards(): void
    {
        $installer = $this->installer(composer: $this->fakeComposer());
        $this->install($installer, 'acme/mylint');
        [$error] = $this->install($installer, 'acme/mylint');
        $this->assertSame('Plugin "mylint" is already installed', $error);

        // A dotted project segment passes the vendor/name classification but
        // fails the plugin directory-name pattern, which forbids dots.
        [$error] = $this->install($this->installer(composer: $this->fakeComposer()), 'acme/a.b');
        $this->assertSame('"a.b" is not a valid plugin name', $error);
    }

    public function testNameInstallRollsBackAPackageThatIsNotAPortaPlugin(): void
    {
        $composer = $this->fakeComposer(packages: ['acme/wrong' => ['composerJson' => (string) \json_encode(['name' => 'acme/wrong', 'type' => 'library'])]]);
        [$error, $file] = $this->install($this->installer(composer: $composer), 'acme/wrong');

        $this->assertSame('"acme/wrong" is not a Porta plugin package', $error);
        $this->assertNull($file);
        $this->assertFileDoesNotExist($this->project->dir.'/.porta/plugins/wrong', 'nothing was ever symlinked');
        // The rollback `composer remove` is fire-and-forget — $onDone fires
        // before it necessarily finishes — so await its effect rather than
        // asserting on it immediately.
        $this->await(fn (): bool => !\is_dir($this->project->dir.'/.porta/vendor/acme/wrong'), what: 'the best-effort rollback removing what composer landed');
    }

    public function testNameInstallReportsAComposerFailure(): void
    {
        [$error] = $this->install($this->installer(composer: $this->fakeComposer(3)), 'acme/mylint');
        $this->assertStringContainsString('composer require failed (exit code 3)', (string) $error);
    }

    public function testNameInstallFailsClearlyWhenTheScaffoldCannotBeWritten(): void
    {
        $composer = $this->fakeComposer();
        \chmod($this->project->dir, permissions: 0o555);
        try {
            [$error] = $this->install($this->installer(composer: $composer), 'acme/mylint');
        } finally {
            \chmod($this->project->dir, permissions: 0o755);
        }
        $this->assertStringContainsString('Could not write', (string) $error);
        $this->assertStringContainsString('composer.json', (string) $error);
    }

    public function testNameInstallWithoutComposerFails(): void
    {
        [$error] = $this->install($this->installer(), 'acme/mylint');
        $this->assertSame('composer is not available', $error);
    }

    // --- source classification -------------------------------------------------------------

    public function testAnUnrecognizedSourceIsRefusedWithoutTouchingComposer(): void
    {
        [$error] = $this->install($this->installer(), 'not a valid source at all');
        $this->assertSame('"not a valid source at all" is not a git URL, a local directory, or a vendor/name package', $error);
    }

    // --- install by git URL --------------------------------------------------------------------

    public function testGitUrlInstallPeeksThenRequiresTheCheckedOutBranch(): void
    {
        $git = $this->git();
        $repo = $this->repo(withPluginPhp: Presence::With);
        $branch = \trim((string) \shell_exec('git -C '.\escapeshellarg($repo).' rev-parse --abbrev-ref HEAD'));

        $composer = $this->fakeComposer();
        [$error, $file] = $this->install($this->installer(git: $git, composer: $composer), 'file://'.$repo);

        $this->assertNull($error);
        $this->assertSame($this->project->dir.'/.porta/plugins/repo-plugin/plugin.php', $file);
        $this->assertTrue(\is_link($this->project->dir.'/.porta/plugins/repo-plugin'));
        $composerJson = \json_decode((string) \file_get_contents($this->project->dir.'/.porta/composer.json'), associative: true);
        $this->assertSame('dev-'.$branch, $composerJson['require']['test/repo-plugin'] ?? null, 'requires the exact peeked branch, not a released version');
    }

    public function testGitUrlInstallGuards(): void
    {
        $git = $this->git();

        [$error] = $this->install($this->installer(git: $git, composer: $this->fakeComposer()), 'file:///nonexistent/nowhere');
        $this->assertStringContainsString('git clone failed (exit code', (string) $error);

        $repoNoPlugin = $this->repo(withPluginPhp: Presence::Without);
        [$error] = $this->install($this->installer(git: $git, composer: $this->fakeComposer()), 'file://'.$repoNoPlugin);
        $this->assertSame('The repository contains no plugin.php', $error);
    }

    /** A local git repo whose composer.json is $manifestJson, usable as a clone URL. */
    private function repoWithManifest(string $manifestJson): string
    {
        $dir = $this->project->dir.'/src-plugins/manifest-'.\bin2hex(\random_bytes(3));
        \mkdir($dir, permissions: 0o777, recursive: true);
        \file_put_contents($dir.'/plugin.php', data: "<?php\n");
        \file_put_contents($dir.'/composer.json', $manifestJson);
        $q = \escapeshellarg($dir);
        \exec("git -C {$q} init -q 2>/dev/null && git -C {$q} config user.email t@t && git -C {$q} config user.name t && git -C {$q} add -A && git -C {$q} commit -qm init --allow-empty 2>/dev/null");

        return $dir;
    }

    public function testGitUrlInstallRejectsAnInvalidNameOrAnAlreadyInstalledOne(): void
    {
        $git = $this->git();

        $badName = $this->repoWithManifest((string) \json_encode(['name' => 'acme/a.b', 'type' => 'porta-plugin']));
        [$error] = $this->install($this->installer(git: $git, composer: $this->fakeComposer()), 'file://'.$badName);
        $this->assertSame('"a.b" is not a valid plugin name', $error);

        $dup = $this->repoWithManifest((string) \json_encode(['name' => 'acme/demo', 'type' => 'porta-plugin']));
        $installer = $this->installer(git: $git, composer: $this->fakeComposer());
        $this->install($installer, 'file://'.$dup);
        [$error] = $this->install($installer, 'file://'.$dup);
        $this->assertSame('Plugin "demo" is already installed', $error);
    }

    public function testGitUrlInstallRefusesARepositoryWithNoValidManifest(): void
    {
        $git = $this->git();
        $dir = $this->project->dir.'/src-plugins/bad-manifest';
        \mkdir($dir, permissions: 0o777, recursive: true);
        \file_put_contents($dir.'/plugin.php', data: "<?php\n");
        $q = \escapeshellarg($dir);
        \exec("git -C {$q} init -q 2>/dev/null && git -C {$q} config user.email t@t && git -C {$q} config user.name t && git -C {$q} add -A && git -C {$q} commit -qm init --allow-empty 2>/dev/null");

        [$error] = $this->install($this->installer(git: $git, composer: $this->fakeComposer()), 'file://'.$dir);
        $this->assertStringContainsString('No installable plugin package found in', (string) $error);
    }

    public function testUrlSourcesRequireGit(): void
    {
        [$error] = $this->install($this->installer(), 'https://example.com/x/demo.git');
        $this->assertSame('git is not available', $error);
    }

    public function testGitUrlInstallReportsAComposerFailure(): void
    {
        $git = $this->git();
        $repo = $this->repo(withPluginPhp: Presence::With);

        [$error] = $this->install($this->installer(git: $git, composer: $this->fakeComposer(3)), 'file://'.$repo);
        $this->assertStringContainsString('composer require failed (exit code 3)', (string) $error);
    }

    public function testGitUrlInstallWithoutComposerFails(): void
    {
        $git = $this->git();
        $repo = $this->repo(withPluginPhp: Presence::With);

        [$error] = $this->install($this->installer(git: $git), 'file://'.$repo);
        $this->assertSame('composer is not available', $error);
    }

    public function testGitUrlInstallFailsClearlyWhenTheScaffoldCannotBeWritten(): void
    {
        $git = $this->git();
        $repo = $this->repo(withPluginPhp: Presence::With);
        $composer = $this->fakeComposer();
        \chmod($this->project->dir, permissions: 0o555);
        try {
            [$error] = $this->install($this->installer(git: $git, composer: $composer), 'file://'.$repo);
        } finally {
            \chmod($this->project->dir, permissions: 0o755);
        }
        $this->assertStringContainsString('Could not write', (string) $error);
        $this->assertStringContainsString('composer.json', (string) $error);
    }

    public function testGitUrlInstallFailsClearlyWhenTheRepositoryCannotBeWritten(): void
    {
        $git = $this->git();
        $repo = $this->repo(withPluginPhp: Presence::With);
        $composer = $this->fakeComposer();
        new PluginComposerProject()->ensureScaffold($this->project->dir);
        \chmod($this->project->dir.'/.porta', permissions: 0o555);
        try {
            [$error] = $this->install($this->installer(git: $git, composer: $composer), 'file://'.$repo);
        } finally {
            \chmod($this->project->dir.'/.porta', permissions: 0o755);
        }
        $this->assertStringContainsString('Could not write', (string) $error);
        $this->assertStringContainsString('composer.json', (string) $error);
    }

    public function testConcurrentInstallsAreRejected(): void
    {
        $git = $this->git();
        $repo = $this->repo(withPluginPhp: Presence::With);

        $installer = $this->installer(git: $git, composer: $this->fakeComposer());
        $first = null;
        $installer->install('file://'.$repo, InstallScope::Project, static function (?string $error, ?string $file) use (&$first): void {
            $first = [$error, $file];
        });
        $second = null;
        $installer->install('file://'.$repo, InstallScope::Project, static function (?string $error) use (&$second): void {
            $second = $error;
        });
        $this->assertSame('Another install is already running', $second, 'single-flight while the clone/require runs');

        $this->await(static function () use (&$first): bool {
            return null !== $first;
        }, what: 'the first install to finish');
        $this->assertSame([null, $this->project->dir.'/.porta/plugins/repo-plugin/plugin.php'], $first);
    }

    // --- remove ------------------------------------------------------------------------------

    public function testRemovingAComposerManagedPluginRunsComposerRemoveAndUnlinksOnlyTheSymlink(): void
    {
        $source = $this->sourceDir('demo');
        $installer = $this->installer(composer: $this->fakeComposer());
        $this->install($installer, $source);
        $landed = $this->project->dir.'/.porta/vendor/test/demo';
        $this->assertFileExists($landed.'/plugin.php', 'sanity: composer "landed" the package');

        $error = $this->remove($installer, 'demo', InstallScope::Project);

        $this->assertNull($error);
        $this->assertFileDoesNotExist($this->project->dir.'/.porta/plugins/demo', 'the symlink is gone');
        $this->assertFileExists($source.'/plugin.php', 'removal never touches the original source — only the symlink chain composer built');
    }

    public function testRemovingAComposerManagedPluginReportsAComposerFailure(): void
    {
        $source = $this->sourceDir('demo');
        $installer = $this->installer(composer: $this->fakeComposer());
        $this->install($installer, $source);

        // A fresh installer pointed at a composer stub that now fails.
        $installer2 = $this->installer(composer: $this->fakeComposer(3));
        $error = $this->remove($installer2, 'demo', InstallScope::Project);

        $this->assertSame('Cannot remove "demo"', $error);
        $this->assertDirectoryExists($this->project->dir.'/.porta/plugins/demo', 'a failed composer remove leaves the plugin standing');
    }

    public function testRemovingASymlinkWithNoReadableTargetManifestFails(): void
    {
        // A symlink whose target has no readable composer.json "name" — should
        // not happen through this class's own install() path, but remove()
        // must fail safely rather than guess a package to `composer remove`.
        $target = $this->project->dir.'/elsewhere/broken';
        \mkdir($target, permissions: 0o777, recursive: true);
        \file_put_contents($target.'/plugin.php', data: "<?php\n");
        $dest = $this->project->dir.'/.porta/plugins/broken';
        \mkdir(\dirname($dest), permissions: 0o777, recursive: true);
        \symlink($target, $dest);

        $error = $this->remove($this->installer(), 'broken', InstallScope::Project);

        $this->assertSame('Cannot remove "broken"', $error);
        $this->assertTrue(\is_link($dest), 'left standing — nothing safe to do');
    }

    public function testRemovingASymlinkedPluginWithoutComposerFails(): void
    {
        $source = $this->sourceDir('demo');
        $installer = $this->installer(composer: $this->fakeComposer());
        $this->install($installer, $source);

        $error = $this->remove($this->installer(), 'demo', InstallScope::Project);

        $this->assertSame('Cannot remove "demo"', $error);
        $this->assertDirectoryExists($this->project->dir.'/.porta/plugins/demo');
    }

    public function testRemovingALegacyDirectoryInstallDeletesItDirectlyWithoutComposer(): void
    {
        // A pre-existing git-clone/copy install from before composer-managed
        // installs: a real directory, not a symlink.
        $dir = $this->project->dir.'/.porta/plugins/legacy';
        \mkdir($dir, permissions: 0o777, recursive: true);
        \file_put_contents($dir.'/plugin.php', data: "<?php\n");

        $error = $this->remove($this->installer(), 'legacy', InstallScope::Project);

        $this->assertNull($error);
        $this->assertDirectoryDoesNotExist($dir);
    }

    public function testARemovalThatWillNotDeleteIsReportedNotSwallowed(): void
    {
        if (0 === \posix_geteuid()) {
            $this->markTestSkipped('root deletes anything');
        }
        $dir = $this->project->dir.'/.porta/plugins/stuck';
        \mkdir($dir, permissions: 0o777, recursive: true);
        \file_put_contents($dir.'/plugin.php', data: "<?php\n");
        // No write bit on the PARENT: the entry itself cannot be unlinked
        // however writable the tree below it is.
        \chmod($this->project->dir.'/.porta/plugins', permissions: 0o555);

        try {
            $error = $this->remove($this->installer(), 'stuck', InstallScope::Project);
            $this->assertSame('Cannot remove "stuck"', $error);
            $this->assertDirectoryExists($dir);
        } finally {
            \chmod($this->project->dir.'/.porta/plugins', permissions: 0o777);
        }
    }

    public function testRemoveAndItsErrors(): void
    {
        $this->assertNull($this->remove($this->installer(), 'gone', InstallScope::Project), 'removing a missing dir is a quiet success');
        $this->assertSame('Cannot remove "x"', $this->remove($this->installer(globalBase: null), 'x', InstallScope::Global));
    }

    public function testRemoveFailsClearlyWhenTheScaffoldCannotBeRewritten(): void
    {
        $source = $this->sourceDir('demo');
        $installer = $this->installer(composer: $this->fakeComposer());
        $this->install($installer, $source);

        // Force ensureScaffold() to attempt a rewrite (it otherwise finds the
        // file already there and succeeds regardless of directory
        // permissions): delete it, then block the directory it would
        // recreate it in.
        \unlink($this->project->dir.'/.porta/composer.json');
        \chmod($this->project->dir.'/.porta', permissions: 0o555);
        try {
            $error = $this->remove($installer, 'demo', InstallScope::Project);
        } finally {
            \chmod($this->project->dir.'/.porta', permissions: 0o755);
        }
        $this->assertSame('Cannot remove "demo"', $error);
    }

    public function testEmbeddedPluginsListNonRemovableAndRefuseRemoval(): void
    {
        $embedded = new TempProject(['bundled/plugin.php' => "<?php\n"]);
        $installer = new PluginInstaller($this->project->dir, $this->global->dir, new Translator('en'), null, $embedded->dir);
        $source = $this->sourceDir('mine');
        $withComposer = new PluginInstaller($this->project->dir, $this->global->dir, new Translator('en'), null, $embedded->dir, $this->fakeComposer());
        $this->install($withComposer, $source);

        $this->assertSame(
            [
                ['name' => 'mine', 'scope' => InstallScope::Project, 'dir' => $this->project->dir.'/.porta/plugins/mine'],
                ['name' => 'bundled', 'scope' => InstallScope::Embedded, 'dir' => $embedded->dir.'/bundled'],
            ],
            $installer->installed(),
        );

        $this->assertSame('Built-in plugins cannot be removed', $this->remove($installer, 'bundled', InstallScope::Embedded));
        $this->assertDirectoryExists($embedded->dir.'/bundled');
    }

    public function testACoincidingEmbeddedDirClassifiesItsPluginsAsEmbedded(): void
    {
        // PORTA_EMBEDDED_PLUGINS pointed at the project's own plugin dir: the
        // realpath-equality guard keeps those entries non-removable.
        \mkdir($this->project->dir.'/.porta/plugins/bundled', permissions: 0o777, recursive: true);
        \file_put_contents($this->project->dir.'/.porta/plugins/bundled/plugin.php', data: "<?php\n");
        $installer = new PluginInstaller($this->project->dir, null, new Translator('en'), null, $this->project->dir.'/.porta/plugins');

        $this->assertSame(
            [['name' => 'bundled', 'scope' => InstallScope::Embedded, 'dir' => $this->project->dir.'/.porta/plugins/bundled']],
            $installer->installed(),
            'listed once, as embedded — not as a removable project plugin',
        );
    }

    /**
     * Every installed copy's docs are a root the help browser may enter —
     * shadowed ones included, since Porta's own pages link to the bundled
     * copy — but the front page a plugin's Settings row opens is the copy
     * that loads. A plugin without docs offers neither.
     */
    public function testDocsRootsCoverEveryCopyAndTheIndexIsTheCopyThatLoads(): void
    {
        $embedded = new TempProject([
            'shadowed/plugin.php' => "<?php\n",
            'shadowed/docs/README.md' => "# Bundled copy\n",
            'bare/plugin.php' => "<?php\n",
            'notes/plugin.php' => "<?php\n",
            'notes/docs/usage.md' => "# A page, but no front page\n",
        ]);
        \mkdir($this->project->dir.'/.porta/plugins/shadowed/docs', permissions: 0o777, recursive: true);
        \file_put_contents($this->project->dir.'/.porta/plugins/shadowed/plugin.php', data: "<?php\n");
        \file_put_contents($this->project->dir.'/.porta/plugins/shadowed/docs/README.md', data: "# Project copy\n");
        $installer = new PluginInstaller($this->project->dir, $this->global->dir, new Translator('en'), null, $embedded->dir);

        $this->assertSame(
            [
                (string) \realpath($this->project->dir.'/.porta/plugins/shadowed/docs') => 'shadowed',
                (string) \realpath($embedded->dir.'/notes/docs') => 'notes',
                (string) \realpath($embedded->dir.'/shadowed/docs') => 'shadowed',
            ],
            $installer->docsRoots(),
        );
        $this->assertSame($this->project->dir.'/.porta/plugins/shadowed/docs/README.md', $installer->docsIndex('shadowed'), 'the copy that loads');
        $this->assertNull($installer->docsIndex('bare'), 'no docs/ at all');
        $this->assertNull($installer->docsIndex('notes'), 'docs/ without a front page offers no row');
        $this->assertNull($installer->docsIndex('absent'));
    }

    public function testInstalledListsBothScopesProjectFirst(): void
    {
        $installer = $this->installer();
        $this->assertSame([], $installer->installed());

        $source = $this->sourceDir('both');
        $this->install($this->installer(composer: $this->fakeComposer()), $source);
        $this->install($this->installer(composer: $this->fakeComposer()), $source, InstallScope::Global);
        $this->install($this->installer(composer: $this->fakeComposer()), $this->sourceDir('worldwide'), InstallScope::Global);

        $this->assertSame(
            [
                ['name' => 'both', 'scope' => InstallScope::Project, 'dir' => $this->project->dir.'/.porta/plugins/both'],
                ['name' => 'both', 'scope' => InstallScope::Global, 'dir' => $this->global->dir.'/.porta/plugins/both'],
                ['name' => 'worldwide', 'scope' => InstallScope::Global, 'dir' => $this->global->dir.'/.porta/plugins/worldwide'],
            ],
            $installer->installed(),
        );

        $this->assertSame(
            [['name' => 'both', 'scope' => InstallScope::Project, 'dir' => $this->project->dir.'/.porta/plugins/both']],
            $this->installer(globalBase: null)->installed(),
            'a null global base lists only the project scope',
        );
    }

    // --- a plugin's OWN dependencies (installDependencies/removeDependencies) — unaffected by the
    // outer install mechanism, and driven directly against a directory rather than through install().

    public function testInstallingAPluginInstallsItsComposerDependencies(): void
    {
        // In the PLUGIN's directory, never the user's project — that tree is
        // ToolInstaller's, and a plugin's libraries have no business in it.
        $source = $this->sourceDir('needy');
        \file_put_contents($source.'/composer.json', data: '{"name":"test/needy","type":"porta-plugin","require":{"vend/lib":"^1.0"}}');

        [$error, $file] = $this->install($this->installer(composer: $this->fakeComposer()), $source);

        $this->assertNull($error);
        $this->assertSame($this->project->dir.'/.porta/plugins/needy/plugin.php', $file);
        $this->assertFileExists($this->project->dir.'/.porta/plugins/needy/vendor/composer/autoload_psr4.php');
    }

    public function testAFailedDependencyInstallKeepsThePluginAndSaysSo(): void
    {
        // The directory is what the user asked for; the next boot declines the
        // plugin by name with the command to run. Removing a good install over a
        // transient network error would be the worse trade.
        //
        // The require itself must succeed (exit 0) so the plugin lands; only the
        // INNER `composer install --no-dev` inside the plugin directory fails.
        // A single fake composer binary answers both, so this exercises the
        // ordinary case: a composer that can `require` but chokes on the
        // plugin's OWN dependency install.
        $source = $this->sourceDir('flaky');
        \file_put_contents($source.'/composer.json', data: '{"name":"test/flaky","type":"porta-plugin","require":{"vend/lib":"^1.0"}}');

        $composer = $this->project->dir.'/fakebin-inner/composer';
        \mkdir(\dirname($composer), permissions: 0o777, recursive: true);
        \file_put_contents($this->project->dir.'/fakebin-inner/composer-recipe.json', data: '[]');
        \file_put_contents($composer, '#!'.\PHP_BINARY.' -n'."\n<?php\n".<<<'PHP'
            $cmd = $argv[1] ?? '';
            if ('install' === $cmd) {
                exit(3);
            }
            $spec = $argv[2] ?? '';
            $pkg = strtok($spec, ':');
            $composerJsonPath = getcwd().'/composer.json';
            $composerJson = json_decode((string) file_get_contents($composerJsonPath), associative: true) ?: [];
            $repo = $composerJson['repositories'][$pkg] ?? null;
            $dest = getcwd().'/vendor/'.$pkg;
            @mkdir(\dirname($dest), 0o777, true);
            if ('require' === $cmd) {
                if (\is_array($repo) && 'path' === ($repo['type'] ?? null)) {
                    symlink((string) $repo['url'], $dest);
                }
                $composerJson['require'][$pkg] = explode(':', $spec, limit: 2)[1] ?? '*';
                file_put_contents($composerJsonPath, json_encode($composerJson, \JSON_PRETTY_PRINT));
            }
            exit(0);
            PHP);
        \chmod($composer, permissions: 0o755);

        [$error, $file] = $this->install($this->installer(composer: $composer), $source);

        $this->assertNotNull($error);
        $this->assertStringContainsString('composer install failed (exit code 3)', (string) $error);
        $this->assertNull($file);
        $this->assertFileExists($this->project->dir.'/.porta/plugins/flaky/plugin.php', 'the install itself stands');
    }

    public function testWithoutComposerADependentPluginInstallsButSaysWhatIsMissing(): void
    {
        // No composer binary at all is caught before any require ever runs.
        $source = $this->sourceDir('needy');
        \file_put_contents($source.'/composer.json', data: '{"name":"test/needy","type":"porta-plugin","require":{"vend/lib":"^1.0"}}');

        [$error, $file] = $this->install($this->installer(), $source);

        $this->assertSame('composer is not available', $error);
        $this->assertNull($file);
    }

    /** A plugin directory with $json as its composer.json — for exercising installDependencies()/removeDependencies() directly. */
    private function pluginDir(string $name, string $json): string
    {
        $dir = $this->project->dir.'/.porta/plugins/'.$name;
        \mkdir($dir, permissions: 0o777, recursive: true);
        \file_put_contents($dir.'/plugin.php', data: "<?php\n");
        \file_put_contents($dir.'/composer.json', data: $json);

        return $dir;
    }

    public function testInstallingDependenciesAnswersSynchronouslyWhatItDid(): void
    {
        // The caller has to know whether to wait BEFORE the subprocess could
        // possibly finish — that is what the return value is for.
        $installer = $this->installer(composer: $this->fakeComposer());
        $never = static function (int $code): never {
            self::fail('composer ran for a plugin that owes nothing');
        };

        $this->assertSame(DependencyInstall::NotNeeded, $installer->installDependencies($this->pluginDir('nodeps', '{"require":{"php":">=8.4"}}'), $never));
        $this->assertSame(DependencyInstall::NotNeeded, $installer->installDependencies($this->pluginDir('nomanifest', '{}'), $never));
        $this->assertSame(
            DependencyInstall::NoComposer,
            $this->installer()->installDependencies($this->pluginDir('needy', '{"require":{"vend/lib":"^1.0"}}'), $never),
            'no composer on this machine',
        );
    }

    public function testInstallingDependenciesRunsComposerAndReportsItsExitCode(): void
    {
        $installer = $this->installer(composer: $this->fakeComposer());
        $dir = $this->pluginDir('needy', '{"require":{"vend/lib":"^1.0"}}');

        $code = null;
        $this->assertSame(DependencyInstall::Started, $installer->installDependencies($dir, static function (int $exit) use (&$code): void {
            $code = $exit;
        }));
        $this->await(static function () use (&$code): bool {
            return null !== $code;
        }, what: "composer's exit");

        $this->assertSame(0, $code);
        $this->assertFileExists($dir.'/vendor/composer/autoload_psr4.php', 'composer ran in the PLUGIN directory');

        // A second call now answers NotNeeded — the vendor tree is there.
        $this->assertSame(DependencyInstall::NotNeeded, $installer->installDependencies($dir, static fn (int $exit): null => null));
    }

    public function testAFailingComposerReportsItsCodeToTheCaller(): void
    {
        $installer = $this->installer(composer: $this->fakeComposer(3));

        $code = null;
        $installer->installDependencies($this->pluginDir('flaky', '{"require":{"vend/lib":"^1.0"}}'), static function (int $exit) use (&$code): void {
            $code = $exit;
        });
        $this->await(static function () use (&$code): bool {
            return null !== $code;
        }, what: "composer's exit");

        $this->assertSame(3, $code, 'the wording is the caller\'s; the code is ours');
    }

    public function testInstallingDependenciesIsRefusedWhileAnotherInstallRuns(): void
    {
        // One slot for the whole class, shared with every git/composer
        // subprocess — a caller that wants several installs has to serialize
        // them itself.
        $installer = $this->installer(git: $this->git(), composer: $this->fakeComposer());
        $repo = $this->repo(Presence::With);
        $done = false;
        $installer->install('file://'.$repo, InstallScope::Project, static function (?string $error, ?string $file) use (&$done): void {
            $done = true;
        });

        $this->assertSame(
            DependencyInstall::Busy,
            $installer->installDependencies($this->pluginDir('needy', '{"require":{"vend/lib":"^1.0"}}'), static fn (int $exit): null => null),
        );

        // …and a plugin that owes nothing is NOT refused, because NotNeeded is
        // answered before the slot is ever consulted.
        $this->assertSame(
            DependencyInstall::NotNeeded,
            $installer->installDependencies($this->pluginDir('nodeps', '{}'), static fn (int $exit): null => null),
        );

        // Let the install finish rather than ending on an armed stream watcher.
        $this->await(static function () use (&$done): bool {
            return $done;
        }, what: 'the install that held the slot');
    }

    public function testASuccessfulInstallMarksTheTreeAsOurs(): void
    {
        // The marker is the only thing that authorises deleting a vendor tree
        // later, so it is written on success and only on success: a
        // half-installed tree is not one we should claim we made.
        $installer = $this->installer(composer: $this->fakeComposer());
        $dir = $this->pluginDir('needy', '{"require":{"vend/lib":"^1.0"}}');

        $code = null;
        $installer->installDependencies($dir, static function (int $exit) use (&$code): void {
            $code = $exit;
        });
        $this->await(static function () use (&$code): bool {
            return null !== $code;
        }, what: "composer's exit");

        $this->assertFileExists($dir.'/'.PluginInstaller::MARKER);
        $this->assertSame(DependencyRemoval::Removed, $installer->removeDependencies($dir));
        $this->assertDirectoryDoesNotExist($dir.'/vendor');
    }

    public function testAFailedInstallLeavesNoClaimOnTheTree(): void
    {
        $installer = $this->installer(composer: $this->fakeComposer(3));
        $dir = $this->pluginDir('flaky', '{"require":{"vend/lib":"^1.0"}}');

        $code = null;
        $installer->installDependencies($dir, static function (int $exit) use (&$code): void {
            $code = $exit;
        });
        $this->await(static function () use (&$code): bool {
            return null !== $code;
        }, what: "composer's exit");

        $this->assertFileDoesNotExist($dir.'/'.PluginInstaller::MARKER);
        $this->assertSame(DependencyRemoval::NotOurs, $installer->removeDependencies($dir), 'we did not finish it, so we do not delete it');
        $this->assertDirectoryExists($dir.'/vendor', 'and what composer did write stays');
    }

    public function testAVendorTreeThePluginShippedIsNeverRemoved(): void
    {
        // The case the marker exists for: a plugin that commits its vendor/
        // must not lose tracked files to a checkbox.
        $installer = $this->installer();
        $dir = $this->pluginDir('shipped', '{"require":{"vend/lib":"^1.0"}}');
        \mkdir($dir.'/vendor/vend', permissions: 0o777, recursive: true);
        \file_put_contents($dir.'/vendor/vend/Thing.php', data: "<?php\n");

        $this->assertSame(DependencyRemoval::NotOurs, $installer->removeDependencies($dir));
        $this->assertFileExists($dir.'/vendor/vend/Thing.php');

        $this->assertSame(
            DependencyRemoval::NotOurs,
            $installer->removeDependencies($this->pluginDir('bare', '{}')),
            'and no vendor at all is not a failure either',
        );
    }

    public function testRemovingDependenciesIsRefusedWhileComposerRuns(): void
    {
        // Deleting a tree composer is writing into leaves a half-vendor that
        // needsInstall() would then call complete.
        $installer = $this->installer(git: $this->git(), composer: $this->fakeComposer());
        $dir = $this->pluginDir('marked', '{"require":{"vend/lib":"^1.0"}}');
        \mkdir($dir.'/vendor', permissions: 0o777, recursive: true);
        \file_put_contents($dir.'/'.PluginInstaller::MARKER, data: "x\n");

        $done = false;
        $installer->install('file://'.$this->repo(Presence::With), InstallScope::Project, static function (?string $error, ?string $file) use (&$done): void {
            $done = true;
        });

        $this->assertSame(DependencyRemoval::Busy, $installer->removeDependencies($dir));
        $this->assertDirectoryExists($dir.'/vendor');

        $this->await(static function () use (&$done): bool {
            return $done;
        }, what: 'the install that held the slot');
    }

    public function testAVendorTreeThatWillNotDeleteIsReportedNotSwallowed(): void
    {
        if (0 === \posix_geteuid()) {
            $this->markTestSkipped('root deletes anything');
        }
        $installer = $this->installer();
        $dir = $this->pluginDir('stuck', '{"require":{"vend/lib":"^1.0"}}');
        \mkdir($dir.'/vendor', permissions: 0o777, recursive: true);
        \file_put_contents($dir.'/'.PluginInstaller::MARKER, data: "x\n");
        // No write bit on the PLUGIN directory, so its `vendor` entry cannot be
        // unlinked however writable the tree below it is.
        \chmod($dir, permissions: 0o555);

        try {
            $this->assertSame(DependencyRemoval::Failed, $installer->removeDependencies($dir));
            $this->assertDirectoryExists($dir.'/vendor');
        } finally {
            \chmod($dir, permissions: 0o777);
        }
    }
}
