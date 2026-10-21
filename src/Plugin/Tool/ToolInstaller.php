<?php

declare(strict_types=1);

namespace Porta\Plugin\Tool;

use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\Process\ExecutableFinder;

/**
 * Installs and updates one declared tool, through the source that fits.
 *
 * Install takes the FIRST usable source in the declaration's order — a
 * manager whose executable is on the PATH, composer only in a trusted project
 * (a vendor binary it installed would not be run anywhere else), a GitHub
 * release only where the repository builds one for this host. Update goes
 * through the source that MANAGES the installed copy ({@see ToolLocator::sourceOf()}),
 * never a fresh guess: `brew upgrade` on a tool cargo installed would fight it.
 *
 * Mechanism only. Queueing, toasts and the relaunch afterwards belong to
 * {@see ToolJobs}; one run at a time is its rule, not this class's.
 */
#[Autoconfigure(constructor: 'create')]
final readonly class ToolInstaller
{
    /** A package manager's output is read once it exits; nothing is watched as it arrives. */
    private const null NO_CHUNKS = null;

    /** @var \Closure(list<string>, string, \Closure(int, string): void, (\Closure(string): void)|null): void */
    private \Closure $run;

    /**
     * @param array<string, string|null> $executables a manager's executable name => its path (null when absent)
     * @param (\Closure(list<string>, string, \Closure(int, string): void, (\Closure(string): void)|null): void)|null $run
     *        how anything is spawned — {@see ProcessRunner::default()}; inject a fake to test without processes
     * @param string $token a GitHub token for the API fallback, or '' — never logged, never toasted
     */
    public function __construct(
        private string $rootDir,
        private array $executables,
        private ToolLocator $locator,
        private ToolVersions $versions,
        ?\Closure $run = null,
        #[\SensitiveParameter]
        private string $token = '',
        private string $osFamily = \PHP_OS_FAMILY,
        private string $machine = '',
    ) {
        $this->run = $run ?? ProcessRunner::default();
    }

    /**
     * Production wiring: every manager resolved from the PATH, the token from
     * the environment, and what runs kept in $runs, so quitting stops it.
     */
    public static function create(string $rootDir, ToolLocator $locator, ToolVersions $versions, RunningProcesses $runs): self
    {
        $finder = new ExecutableFinder();
        $executables = [];
        foreach (ToolManager::cases() as $manager) {
            $executable = $manager->executable();
            if (null !== $executable) {
                $executables[$executable] = $finder->find($executable);
            }
        }

        return new self($rootDir, $executables, $locator, $versions, ProcessRunner::default(runs: $runs), GithubInstaller::tokenFromEnvironment(), machine: \php_uname('m'));
    }

    /** The source an install of $tool would use, or null when none is usable here. */
    public function installSource(ToolRequirement $tool): ?ToolSource
    {
        return \array_find($tool->sources, $this->usable(...));
    }

    /**
     * Install $tool from $source. Exactly one $onDone, once.
     *
     * @param \Closure(InstallStep, int): void $onProgress where a download has got to
     * @param \Closure(ToolResult): void        $onDone
     */
    public function install(ToolRequirement $tool, ToolSource $source, \Closure $onProgress, \Closure $onDone): void
    {
        ToolManager::Github === $source->manager
            ? $this->download($tool, $source, null, '', $onProgress, $onDone)
            : $this->manage($source, $source->manager->installArgs($source->package), ToolOutcome::Installed, $onDone);
    }

    /**
     * Bring the copy at $path up to date through $source, the source that
     * manages it.
     *
     * A GitHub tool asks which release is the latest BEFORE it downloads, and
     * stops there when the binary already is it: symfony-lsp's Update used to
     * fetch ~9MB whatever the answer.
     *
     * @param \Closure(InstallStep, int): void $onProgress
     * @param \Closure(ToolResult): void        $onDone
     */
    public function update(ToolRequirement $tool, ToolSource $source, string $path, \Closure $onProgress, \Closure $onDone): void
    {
        if (ToolManager::Github !== $source->manager) {
            $this->manage($source, $source->manager->updateArgs($source->package), ToolOutcome::Updated, $onDone);

            return;
        }
        // Through a local: a literal second argument to a closure reads as an
        // unnamed flag to mago, and a closure has no parameter name to name.
        $noPercent = 0;
        $onProgress(InstallStep::Resolving, $noPercent);
        $this->github($tool, $source, $onProgress)->latestTag(function (?string $tag, string $why) use ($tool, $source, $path, $onProgress, $onDone): void {
            $release = $this->release($source);
            $latest = null === $tag ? null : $release->versionOfTag($tag);
            if (null === $tag || null === $latest) {
                $onDone(new ToolResult(ToolOutcome::Failed, why: $why));

                return;
            }
            // Ask the BINARY before committing to a download, rather than
            // trusting a cache filled at boot: a copy replaced from another
            // window (or by hand) would otherwise make "1.0 → 1.0" out of a
            // download that changed nothing.
            $this->versions->forget($path);
            $this->versions->probe($path, $tool->versionArgs, function () use ($tool, $source, $path, $tag, $latest, $onProgress, $onDone): void {
                $was = $this->versions->known($path);
                // An unknown installed version cannot be compared, so it downloads.
                $was === $latest
                    ? $onDone(new ToolResult(ToolOutcome::UpToDate, $was))
                    : $this->download($tool, $source, $tag, $was, $onProgress, $onDone);
            });
        });
    }

    /** Whether $source can be used on this machine, for this project, right now. */
    private function usable(ToolSource $source): bool
    {
        $executable = $source->manager->executable();

        return match ($source->manager) {
            ToolManager::Plugin => true,
            ToolManager::Github => null !== $this->locator->binDir && null !== $source->asset?->assetFor('v0', $this->osFamily, $this->machine),
            ToolManager::Composer => null !== ($this->executables[(string) $executable] ?? null) && $this->locator->trusted(),
            default => null !== ($this->executables[(string) $executable] ?? null),
        };
    }

    /**
     * Run a package manager. composer runs in the project, which it edits;
     * the rest are machine-wide and run there too, because a cwd has to exist.
     *
     * @param list<string>               $args
     * @param \Closure(ToolResult): void $onDone
     */
    private function manage(ToolSource $source, array $args, ToolOutcome $success, \Closure $onDone): void
    {
        $executable = $this->executables[(string) $source->manager->executable()] ?? null;
        if (null === $executable) {
            $onDone(new ToolResult(ToolOutcome::Failed, why: (string) $source->manager->executable().' is not on the PATH'));

            return;
        }
        $label = $source->manager->value;
        ($this->run)(
            [$executable, ...$args],
            $this->rootDir,
            static function (int $code, string $output) use ($label, $success, $onDone): void {
                $onDone(0 === $code ? new ToolResult($success) : new ToolResult(ToolOutcome::Failed, why: self::lastLine($output) ?? $label.' exited with code '.$code));
            },
            self::NO_CHUNKS,
        );
    }

    /**
     * @param string|null                      $tag a release already resolved, or null to resolve it
     * @param string                           $was the version being replaced, '' on a first install
     * @param \Closure(InstallStep, int): void $onProgress
     * @param \Closure(ToolResult): void       $onDone
     */
    private function download(ToolRequirement $tool, ToolSource $source, ?string $tag, string $was, \Closure $onProgress, \Closure $onDone): void
    {
        $this->github($tool, $source, $onProgress)->install(
            $this->osFamily,
            $this->machine,
            function (string $path, string $version) use ($was, $onDone): void {
                // The smoke test already asked the binary what it is.
                $this->versions->remember($path, $version);
                $onDone(new ToolResult('' === $was ? ToolOutcome::Installed : ToolOutcome::Updated, $version));
            },
            static function (string $why) use ($onDone): void {
                $onDone(new ToolResult(ToolOutcome::Failed, why: $why));
            },
            $tag,
        );
    }

    /**
     * An installer for one tool's release. The scratch directory is keyed on
     * the tool and the pid, so two windows — or two tools — cannot collide,
     * and named with the installer's own prefix so it can sweep what an
     * interrupted install left behind. Under the user-owned ~/.porta, not the
     * shared /tmp: a predictable world-writable path invites a symlink swap
     * between the checksum-verified extract and the copy into bin.
     *
     * @param \Closure(InstallStep, int): void $onProgress
     */
    private function github(ToolRequirement $tool, ToolSource $source, \Closure $onProgress): GithubInstaller
    {
        $binDir = (string) $this->locator->binDir;

        return new GithubInstaller(
            $this->release($source),
            $tool->binary,
            $binDir,
            \dirname($binDir).'/tmp/'.GithubInstaller::SCRATCH_PREFIX.$tool->binary.'-'.(int) \getmypid(),
            $this->run,
            $this->token,
            onProgress: $onProgress,
            versionArgs: $tool->versionArgs,
        );
    }

    private function release(ToolSource $source): GithubRelease
    {
        // usable() and sourceOf() only ever hand a github source that has its rules.
        return new GithubRelease($source->package, $source->asset ?? new GithubAsset('', [], []));
    }

    /** The last non-empty line a failed manager printed — usually the one that says why. */
    private static function lastLine(string $output): ?string
    {
        $lines = \array_filter(\array_map(\trim(...), \explode("\n", $output)), static fn (string $line): bool => '' !== $line);

        return [] === $lines ? null : \array_last($lines);
    }
}
