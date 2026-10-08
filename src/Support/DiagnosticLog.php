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

    /**
     * The directory is known to exist and to have taken a write.
     *
     * Cleared again by a failed write, which is the only thing that can mean it
     * has gone: see {@see append()}.
     */
    private bool $dirReady = false;

    /**
     * What this writer believes the file holds, null until it has looked once.
     *
     * Counted rather than stat'd because of who calls this and when. Five of the
     * 41 blocked-loop backtraces in the user's `freeze.log` are inside THIS
     * method, and every one of them was reached from {@see
     * \Porta\Support\FreezeWatchdog} — the instrument writing the stall line was
     * itself blocked on the filesystem, lengthening the stall it had just
     * measured, up to four times a second while the disk was slow. An append
     * used to cost `is_dir` + `is_file` + `filesize` + the write; it costs the
     * write now.
     *
     * The count is what THIS writer put there, plus whatever the file held when
     * it first looked. A second session appending to the same log is invisible
     * to it, so the cap holds per writer rather than absolutely — a bound of
     * `sessions × maxBytes` instead of `maxBytes`, which is the same promise for
     * the case that matters (one session, growing without limit) and harmless in
     * the case it gives up.
     */
    private ?int $bytes = null;

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
        $line = $this->clock->now()->format('Y-m-d H:i:s').' '.$entry."\n";
        \set_error_handler(static fn (): bool => true);
        try {
            if (!$this->directoryReady()) {
                return; // unwritable location: stay silent by contract
            }
            // No `is_file()` in front of it: a missing file warns and returns
            // false, the handler above swallows the warning, and `(int) false`
            // is the 0 the guard would have produced — one syscall instead of
            // two, and one branch instead of two. The cast is load-bearing:
            // `false` into a typed `?int` is a TypeError, and this method is
            // contractually unable to throw.
            $this->bytes ??= (int) \filesize($this->path);
            $held = $this->bytes;
            $trimming = $held > $this->maxBytes;
            $written = $trimming
                ? \file_put_contents($this->path, $this->recentTail().$line)
                : \file_put_contents($this->path, $line, \FILE_APPEND);
            if (false === $written) {
                // The one thing a failed write can mean that a retry would fix:
                // the directory has gone since it was last checked. Cheaper to
                // find out here, once, than to ask before every line — at the
                // price of losing THIS line, where asking every time would have
                // recreated the directory and kept it.
                $this->dirReady = false;

                return;
            }
            // A trim REWROTE the file, so what it wrote is what the file holds;
            // an append added to what was already there. Either way the answer
            // comes from the write's own return value, so this path needs no
            // stat either.
            $this->bytes = $trimming ? $written : $held + $written;
        } finally {
            \restore_error_handler();
        }
    }

    /**
     * Whether the log's directory exists and the log is not a link — asked
     * once per session, and again after a write has failed.
     */
    private function directoryReady(): bool
    {
        if ($this->dirReady) {
            return true;
        }
        $dir = \dirname($this->path);
        if (!\is_dir($dir) && \is_dir(\dirname($dir))) {
            \mkdir($dir, permissions: 0o755, recursive: true);
        }

        // A log that is a symlink is not written through: `.porta/` comes with
        // a cloned project, and `error.log -> ~/.bashrc` would have every error
        // line — paths and names the project chose among them — appended there.
        $this->dirReady = \is_dir($dir) && !\is_link($this->path);

        return $this->dirReady;
    }

    /**
     * The recent half of an oversized log, cut at an ENTRY boundary.
     *
     * Trimming rather than emptying, because the two are not equally bad in the
     * case that actually happens: a crash STORM is what pushes this file past
     * its cap, so starting over discarded the history at the exact moment there
     * was most of it. The class docblock's own example — the twenty-one
     * identical traces this repo's `error.log` held — is what that rule would
     * have thrown away, and reading them is how a real defect was found.
     *
     * Forward to the first dated line, so the survivors begin with a stamp
     * rather than the middle of somebody's stack trace: that is the shape the
     * docblock above promises a reader counting entries with `grep '^2'`. A
     * tail holding no entry start at all — one enormous trace — keeps nothing,
     * which is the same answer as before and still bounded.
     */
    private function recentTail(): string
    {
        $tail = \substr((string) \file_get_contents($this->path), -\intdiv($this->maxBytes, num2: 2));
        $matches = [];
        if (1 !== \preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2} /m', $tail, $matches, \PREG_OFFSET_CAPTURE)) {
            return '';
        }

        return \substr($tail, (int) $matches[0][1]);
    }
}
