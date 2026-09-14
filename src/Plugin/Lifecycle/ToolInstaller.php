<?php

declare(strict_types=1);

namespace Porta\Plugin\Lifecycle;

use Porta\Support\AsyncProcess;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\Process\ExecutableFinder;

/**
 * Installs a plugin's missing tool binary on the shared event loop, trying an
 * ordered list of package-manager candidates and running the first whose
 * manager is on the PATH. A composer-backed tool declares one candidate
 * (`composer require --dev <pkg>`, project-local, landing in `vendor/bin`); a
 * native tool like phpantom declares several (`brew install <pkg>` first, then
 * `cargo install <pkg> --locked`). Single-flight; the caller handles the
 * outcome flash and any follow-up (re-render, LSP reload).
 */
#[Autoconfigure(constructor: 'create')]
final class ToolInstaller
{
    /**
     * Per-manager argv around the package name: the fixed words before the
     * package, then any after it. Keyed by the manager name a plugin declares.
     *
     * @var array<string, array{before: list<string>, after: list<string>}>
     */
    private const MANAGERS = [
        'composer' => ['before' => ['require', '--dev'], 'after' => []],
        'brew' => ['before' => ['install'], 'after' => []],
        'cargo' => ['before' => ['install'], 'after' => ['--locked']],
    ];

    /** The install in progress, if any (single-flight). */
    private ?AsyncProcess $running = null;

    /**
     * @param array<string, string|null> $managerBinaries manager name => resolved binary path (null when absent)
     */
    public function __construct(
        private readonly string $rootDir,
        private readonly array $managerBinaries,
    ) {}

    /** Production wiring: every known manager resolved from the PATH. */
    public static function create(string $rootDir): self
    {
        $finder = new ExecutableFinder();
        $binaries = [];
        foreach (\array_keys(self::MANAGERS) as $manager) {
            $binaries[$manager] = $finder->find($manager);
        }

        return new self($rootDir, $binaries);
    }

    /**
     * Install the tool via the first available candidate. Returns immediately:
     * {@see InstallOutcome::Busy} when one is already running,
     * {@see InstallOutcome::NoManager} when none of the declared managers is on
     * the PATH, else {@see InstallOutcome::Started} — and then $onDone fires
     * exactly once with whether it succeeded (a zero exit).
     *
     * @param list<array{0: string, 1: string}> $via   ordered [manager, package] candidates
     * @param \Closure(bool): void               $onDone
     */
    public function install(array $via, \Closure $onDone): InstallOutcome
    {
        if (null !== $this->running) {
            return InstallOutcome::Busy;
        }
        foreach ($via as [$manager, $package]) {
            $binary = $this->managerBinaries[$manager] ?? null;
            if (null === $binary) {
                continue;
            }
            $this->spawn($binary, $manager, $package, $onDone);

            return InstallOutcome::Started;
        }

        return InstallOutcome::NoManager;
    }

    /** @param \Closure(bool): void $onDone */
    private function spawn(string $binary, string $manager, string $package, \Closure $onDone): void
    {
        $template = self::MANAGERS[$manager];
        $command = [$binary, ...$template['before'], $package, ...$template['after']];
        $this->running = new AsyncProcess($command, $this->rootDir, timeout: AsyncProcess::EXTERNAL_TOOL_TIMEOUT);
        $started = $this->running->start(function (int $code) use ($onDone): void {
            $this->running = null;
            $onDone(0 === $code);
        });
        if (!$started) {
            // proc_open failure is environmental (fd exhaustion)
            // @codeCoverageIgnoreStart
            $this->running = null;
            $onDone(false);

            // @codeCoverageIgnoreEnd
        }
    }
}
