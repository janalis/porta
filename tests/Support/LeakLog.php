<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

/**
 * The one sink both leak reporters write through.
 *
 * `[loop-leak]` and `[env-leak]` are the leading indicators of the failure this
 * suite actually suffers from: a test that walked out mid-flight leaves a
 * watcher armed, or an environment variable set, and the damage lands in
 * whatever runs next in that worker — somewhere with no connection to the
 * cause. Both reporters wrote to STDERR only, and
 * {@see LoopIsolationExtension}'s own docblock records the measurement that
 * makes that useless: **paratest swallows worker STDERR**, so under the command
 * everyone actually runs, the signal was discarded.
 *
 * So it goes to a file as well. Appended, never truncated: sixteen workers
 * write to it concurrently and none of them can know whether it is the first,
 * so a reader takes the delta instead (record the size, run, read from there —
 * which is what `flake:scan` does).
 *
 * A single `fwrite` of one short line is atomic on POSIX below PIPE_BUF, which
 * is what keeps concurrent appends from interleaving mid-line.
 *
 * Report-only, on both channels. Escalating `[loop-leak]` to a failure is
 * settled and refused — decisions.md has the evidence — and writing it down
 * more durably does not reopen that.
 */
final class LeakLog
{
    /** Overridable so a test can read what the reporters wrote. */
    public const string PATH_ENV = 'PORTA_LEAK_LOG';

    private const string DEFAULT_PATH = '/.phpunit.cache/leaks.log';

    public static function append(string $line): void
    {
        fwrite(\STDERR, $line);

        $path = self::path();
        $dir = \dirname($path);
        // Concurrent workers race to create this; the recursive flag plus the
        // is_dir re-check is what makes losing that race harmless, and is why
        // the failure is ignored rather than silenced with `@`.
        if (!is_dir($dir) && !mkdir($dir, permissions: 0o777, recursive: true) && !is_dir($dir)) {
            return;
        }
        if (is_dir($dir)) {
            file_put_contents($path, $line, flags: \FILE_APPEND);
        }
    }

    /** Where the reports are accumulating for this run. */
    public static function path(): string
    {
        $configured = getenv(self::PATH_ENV);

        return \is_string($configured) && '' !== $configured
            ? $configured
            : \dirname(__DIR__, levels: 2).self::DEFAULT_PATH;
    }
}
