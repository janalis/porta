<?php

declare(strict_types=1);

namespace Porta\Graph;

use Porta\Support\AsyncProcess;

/**
 * Produces the project's architecture DOT and writes it to
 * `.porta/architecture.dot` (a machine artifact, per the settings-store
 * split): the configured generator command's stdout when one is given (any
 * language, any generator — `/bin/sh -c`, project trust level, async on the
 * shared loop), else the built-in {@see NamespaceGraphScanner}.
 *
 * Deliberately constructible from a plugin file (rootDir only, no container
 * services) — the bundled graph-viewer plugin's `architecture` command is thin
 * glue over this class, per the registration-only doctrine. Errors surface as
 * strings through $done (the caller flashes); the success path reports the
 * REALPATH'd artifact so path-keyed buffer reuse matches.
 */
final class ArchitectureArtifact
{
    private ?AsyncProcess $running = null;

    public function __construct(
        private readonly string $rootDir,
    ) {}

    public static function path(string $rootDir): string
    {
        return $rootDir.'/.porta/architecture.dot';
    }

    /** @param \Closure(?string $error, ?string $path): void $done exactly one call; a newer generate() supersedes an in-flight command */
    public function generate(?string $command, \Closure $done): void
    {
        if (null === $command || '' === $command) {
            $this->publish(new NamespaceGraphScanner($this->rootDir)->scan(), $done);

            return;
        }
        $this->running?->cancel();
        $process = new AsyncProcess(['/bin/sh', '-c', $command], $this->rootDir, captureStderr: true);
        $this->running = $process;
        $started = $process->start(function (int $code, string $stdout) use ($process, $done): void {
            if ($this->running === $process) {
                $this->running = null;
            }
            if (0 !== $code) {
                $stderr = trim($process->errorOutput());
                $done('Architecture command failed: '.('' !== $stderr ? $stderr : "exit {$code}"), null);

                return;
            }
            if ('' === trim($stdout)) {
                $done('Architecture command produced no output', null);

                return;
            }
            $this->publish($stdout, $done);
        });
        if (!$started) {
            // proc_open spawns even unusable binaries — unreachable in tests.
            // @codeCoverageIgnoreStart
            $this->running = null;
            $done('Architecture command failed: could not start', null);

            // @codeCoverageIgnoreEnd
        }
    }

    /** @param \Closure(?string $error, ?string $path): void $done */
    private function publish(string $dot, \Closure $done): void
    {
        $path = self::path($this->rootDir);
        $dir = \dirname($path);
        // @mago-expect lint:no-error-control-operator -- a concurrent mkdir (or a file squatting on the name) warns; the is_dir recheck handles both.
        if (!is_dir($dir) && !@mkdir($dir, permissions: 0o755) && !is_dir($dir)) {
            $done('Cannot write .porta/architecture.dot', null);

            return;
        }
        file_put_contents($path, $dot);
        $done(null, ($resolved = realpath($path)) ? $resolved : $path);
    }
}
