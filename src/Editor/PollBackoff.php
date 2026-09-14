<?php

declare(strict_types=1);

namespace Porta\Editor;

use Porta\Support\Clocks;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;

/**
 * Paces the filesystem poll against a filesystem that has stopped answering
 * promptly.
 *
 * The slice in {@see FilesystemReconciler::pollSlice()} exists because the old
 * full pass was a proven loop stall: bound the work per tick and the cost never
 * lands as one long freeze. That bound is a count, and a count is only half the
 * story — the user's own `.porta/freeze.log` has 41 backtraces of the loop
 * blocked five seconds or more, and 35 of them are stopped inside ONE
 * `is_file()` in {@see Buffer::diskStampNow()}. A single stat can block for
 * twelve seconds on a loaded box, and no slice size makes that shorter.
 *
 * What a slice size cannot do is stop it happening four times a second. So when
 * a slice overruns its budget this steps the poll back, doubling the gap each
 * time it overruns again, and drops back to every tick the moment the
 * filesystem is quick again. Detection gets slower — which is what the
 * reconciler's docblock already names as the acceptable trade — while the
 * screen stops locking up on a cadence.
 *
 * Its own class because a stopwatch with a memory is not what reconciling
 * buffers is about, and because a clock this small can be driven directly by a
 * test: the reconciler is an inlined service, so nothing can hand ITS clock a
 * MockClock after the container built it.
 */
final class PollBackoff
{
    /**
     * A slice slower than this means the filesystem is not answering.
     *
     * Four stats and eight directory scans are microseconds warm; 50ms is three
     * orders of magnitude of headroom, and still a twentieth of the 1s
     * threshold the freeze watchdog would call a hitch. In microseconds, not
     * float seconds, so a slice can sit exactly ON the budget and be judged the
     * same way twice.
     */
    public const int BUDGET_MICROS = 50_000;

    /** The longest the poll may stand down — 120 ticks of the 4Hz timer, 30s. */
    public const int MAX_SKIPS = 120;

    /** Ticks still to be skipped before the next slice may run. */
    private int $skips = 0;

    /** What the NEXT overrun will cost, doubling each time until the cap. */
    private int $gap = 1;

    /** When the running slice started, null when none is running. */
    private ?int $startedAt = null;

    public function __construct(
        private readonly ClockInterface $clock = new NativeClock(),
    ) {}

    /**
     * Whether this tick may poll — and, when it may, the moment it started.
     *
     * The stopwatch starts here rather than in a separate call so the two halves
     * cannot drift apart: there is no way to run a slice that is not being
     * timed.
     */
    public function mayRun(): bool
    {
        if ($this->skips > 0) {
            --$this->skips;

            return false;
        }
        $this->startedAt = Clocks::microseconds($this->clock);

        return true;
    }

    /**
     * The slice finished: widen the gap if it overran, forget it if it did not.
     *
     * Doubling from one rather than jumping to the cap: a single slow slice is
     * as likely to be a busy moment as a sick disk, and standing down for thirty
     * seconds over one of those would hide an external edit for no reason.
     */
    public function finished(): void
    {
        $started = $this->startedAt;
        $this->startedAt = null;
        if (null === $started || (Clocks::microseconds($this->clock) - $started) <= self::BUDGET_MICROS) {
            $this->gap = 1;

            return;
        }
        // The gap armed here, and the next one doubled: written this way round
        // so the reset above is a value with meaning rather than a zero that
        // `max(1, …)` would paper over — which is how the first draft's reset
        // survived being mutated to -1.
        $this->skips = $this->gap;
        $this->gap = \min(self::MAX_SKIPS, 2 * $this->gap);
    }
}
