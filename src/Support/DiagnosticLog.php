<?php

declare(strict_types=1);

namespace Porta\Support;

use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;

/**
 * Best-effort append-only diagnostic file with a size cap: the shared sink
 * for evidence that must survive a session (freeze.log, error.log, dap.log).
 * Never throws — a diagnostic channel must not break the app it is
 * diagnosing — and starts the file over once it outgrows the cap, so a
 * repeating fault (a permanently hung loop re-alarming every few seconds)
 * cannot grow it until the disk ends.
 *
 * Every entry is stamped here rather than by its channel. The stamp is what
 * makes an entry EVIDENCE: without one there is no way to tell a crash from
 * this morning apart from one a fix already closed weeks ago, and the file
 * offers no other clue — a repeating fault writes the same bytes every time,
 * and the file's mtime dates only the newest of them. That is not
 * hypothetical. `error.log` here held twenty-one identical traces and no
 * dates; the only thing that eventually placed them in time was a test
 * docblock that happened to quote their count.
 *
 * Stamping the FIRST line only, so a multi-line trace stays one entry: the
 * lines after it are the trace's own, and prefixing those would corrupt the
 * shape a reader (or `grep '^2'`) uses to count entries.
 *
 * The clock is a trailing default rather than a required argument, which means
 * the two channels built where no container is at hand — `error` from
 * {@see \Porta\Ide} and {@see \Porta\Console\PortaCommand}, and `dap` — stamp
 * from the WALL clock, while `freeze` and `perf` hand in the injected one. A
 * test that freezes time therefore sees mock stamps in those two logs and real
 * ones in the others. Nothing asserts on a crash stamp, so this buys a real
 * date in a real user's crash log at the price of an inconsistency only a
 * time-frozen test can see; `Ide` cannot reach the clock without widening the
 * `porta.ide` locator for one caller, which its own membership gate refuses.
 */
final class DiagnosticLog
{
    /** Generous for a post-mortem, harmless on disk. */
    public const int MAX_BYTES = 262_144;

    public function __construct(
        private readonly string $path,
        private readonly int $maxBytes = self::MAX_BYTES,
        private readonly ClockInterface $clock = new NativeClock(),
    ) {}

    /** The channel's log under $rootDir's `.porta/` directory — the shared naming convention for error/dap/freeze logs. */
    public static function forRoot(string $rootDir, string $channel, ClockInterface $clock = new NativeClock()): self
    {
        return new self($rootDir.'/.porta/'.$channel.'.log', clock: $clock);
    }

    public function path(): string
    {
        return $this->path;
    }

    /** Append one entry under the current time, creating the parent directory on first use. */
    public function append(string $entry): void
    {
        $entry = $this->clock->now()->format('Y-m-d H:i:s').' '.$entry;
        set_error_handler(static fn (): bool => true);
        try {
            $dir = \dirname($this->path);
            if (!is_dir($dir) && is_dir(\dirname($dir))) {
                mkdir($dir, permissions: 0o755, recursive: true);
            }
            if (!is_dir($dir)) {
                return; // unwritable location: stay silent by contract
            }
            $oversized = is_file($this->path) && (int) filesize($this->path) > $this->maxBytes;
            file_put_contents($this->path, $entry."\n", $oversized ? 0 : \FILE_APPEND);
        } finally {
            restore_error_handler();
        }
    }
}
