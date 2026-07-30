<?php

declare(strict_types=1);

namespace Porta\GraphViewer;

use Porta\Support\AsyncProcess;

/**
 * Produces the project's architecture DOT and writes it as `architecture.dot`
 * in the output directory — the project root by default: the artifact is meant
 * to be opened, versioned or handed around, so it sits where the user can see
 * it rather than hidden among the machine files in `.porta/`. The content is
 * the configured generator command's stdout when one is given (any language,
 * any generator — `/bin/sh -c`, project trust level, async on the shared loop),
 * else the built-in {@see NamespaceGraphScanner}.
 *
 * Deliberately constructible from a plugin file (two strings, no container
 * services) — the bundled graph-viewer plugin's `architecture` command is thin
 * glue over this class, per the registration-only doctrine, and the output
 * directory is that plugin's `outputDir` setting. Errors surface as strings
 * through $done (the caller flashes); the success path reports the REALPATH'd
 * artifact so path-keyed buffer reuse matches.
 */
final class ArchitectureArtifact
{
    /** The artifact's file name, wherever it is written — also its tab-title identity. */
    public const string FILENAME = 'architecture.dot';

    private ?AsyncProcess $running = null;

    /** @param string $outputDir absolute, or relative to the project root; '' is the root itself */
    public function __construct(
        private readonly string $rootDir,
        private readonly string $outputDir = '',
    ) {}

    /** Where this run will write, created on demand by {@see generate()}. */
    public function path(): string
    {
        $dir = trim($this->outputDir);
        $base = match (true) {
            '' === $dir => $this->rootDir,
            str_starts_with($dir, '/') => $dir,
            default => $this->rootDir.'/'.$dir,
        };

        return rtrim($base, characters: '/').'/'.self::FILENAME;
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
        $path = $this->path();
        $dir = \dirname($path);
        // A configured output directory need not exist yet, so create it (with
        // parents — the setting may name a nested one).
        // @mago-expect lint:no-error-control-operator -- a concurrent mkdir (or a file squatting on the name) warns; the is_dir recheck handles both.
        if (!is_dir($dir) && !@mkdir($dir, permissions: 0o755, recursive: true) && !is_dir($dir)) {
            $done('Cannot write '.$path, null);

            return;
        }
        file_put_contents($path, $dot);
        $done(null, ($resolved = realpath($path)) ? $resolved : $path);
    }
}
