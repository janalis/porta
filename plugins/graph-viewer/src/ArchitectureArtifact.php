<?php

declare(strict_types=1);

namespace Porta\GraphViewer;

use Porta\Support\AsyncProcess;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;

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
        private readonly Filesystem $fs = new Filesystem(),
    ) {}

    /** Where this run will write, created on demand by {@see generate()}. */
    public function path(): string
    {
        $dir = \trim($this->outputDir);
        $base = match (true) {
            '' === $dir => $this->rootDir,
            \str_starts_with($dir, '/') => $dir,
            default => $this->rootDir.'/'.$dir,
        };

        return \rtrim($base, characters: '/').'/'.self::FILENAME;
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
                $stderr = \trim($process->errorOutput());
                self::fail($done, 'Architecture command failed: '.('' !== $stderr ? $stderr : "exit {$code}"));

                return;
            }
            if ('' === \trim($stdout)) {
                self::fail($done, 'Architecture command produced no output');

                return;
            }
            $this->publish($stdout, $done);
        });
        if (!$started) {
            // proc_open spawns even unusable binaries — unreachable in tests.
            // @codeCoverageIgnoreStart
            $this->running = null;
            self::fail($done, 'Architecture command failed: could not start');

            // @codeCoverageIgnoreEnd
        }
    }

    /**
     * The failure half of the `$done` protocol: an error and no path.
     *
     * @param \Closure(?string $error, ?string $path): void $done
     */
    private static function fail(\Closure $done, string $error): void
    {
        // @mago-expect lint:literal-named-argument -- a named argument on a closure binds to the CALLER's parameter names, which this seam does not pin.
        $done($error, null);
    }

    /** @param \Closure(?string $error, ?string $path): void $done */
    private function publish(string $dot, \Closure $done): void
    {
        $path = $this->path();
        try {
            // dumpFile creates the directory chain (a configured output dir
            // need not exist yet, and may be nested) and writes atomically via
            // temp-file+rename — which retires the mkdir-race dance it replaced.
            $this->fs->dumpFile($path, $dot);
        } catch (IOExceptionInterface) {
            self::fail($done, 'Cannot write '.$path);

            return;
        }
        $done(null, ($resolved = \realpath($path)) ? $resolved : $path);
    }
}
