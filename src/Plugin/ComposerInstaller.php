<?php

declare(strict_types=1);

namespace Porta\Plugin;

use Porta\Support\AsyncProcess;
use Symfony\Component\Process\ExecutableFinder;

/**
 * Runs `composer require --dev <package>` in the project on the shared event
 * loop, so a plugin's Settings page can install its missing tool binary (which
 * lands in `vendor/bin`, where the plugins look first). Single-flight; the
 * caller handles the outcome flash and any follow-up (re-render, LSP reload).
 */
final class ComposerInstaller
{
    /** The require in progress, if any (single-flight). */
    private ?AsyncProcess $running = null;

    public function __construct(
        private readonly string $rootDir,
        private readonly ?string $composerBinary,
    ) {}

    /** Production wiring: `composer` resolved from the PATH. */
    public static function create(string $rootDir): self
    {
        return new self($rootDir, new ExecutableFinder()->find('composer'));
    }

    /**
     * Require $package as a dev dependency. Returns false immediately if a
     * require is already running; otherwise calls $onDone exactly once with
     * whether it succeeded (false when composer is missing or exits non-zero).
     *
     * @param \Closure(bool): void $onDone
     */
    public function require(string $package, \Closure $onDone): bool
    {
        if (null !== $this->running) {
            return false;
        }
        if (null === $this->composerBinary) {
            $onDone(false);

            return true;
        }
        $this->running = new AsyncProcess([$this->composerBinary, 'require', '--dev', $package], $this->rootDir);
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

        return true;
    }
}
