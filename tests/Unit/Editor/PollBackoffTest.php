<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\TestCase;
use Porta\Editor\PollBackoff;
use Symfony\Component\Clock\MockClock;

/**
 * The filesystem poll's pacer: a slice that overruns its budget stands the poll
 * down for a doubling number of ticks, and a quick one puts it straight back.
 *
 * The case it exists for is in the user's own freeze.log — 35 backtraces of the
 * loop blocked five seconds or more, all stopped inside one `is_file()`. A
 * bounded slice cannot make a single blocking stat shorter; it can stop it
 * happening four times a second.
 */
final class PollBackoffTest extends TestCase
{
    private MockClock $clock;

    #[\Override]
    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-09-13 09:00:00');
    }

    /** Run one slice that takes $seconds of clock. */
    private function slice(PollBackoff $backoff, float $seconds): bool
    {
        if (!$backoff->mayRun()) {
            return false;
        }
        $this->clock->sleep($seconds);
        $backoff->finished();

        return true;
    }

    /**
     * Tick until a slice is allowed through, and say how many ticks were turned
     * away first. The allowed slice takes $seconds like any other, so passing a
     * slow one leaves the NEXT gap armed.
     */
    private function ticksSkippedBefore(PollBackoff $backoff, float $seconds): int
    {
        $skipped = 0;
        while (!$this->slice($backoff, $seconds)) {
            ++$skipped;
        }

        return $skipped;
    }

    public function testAQuickSliceRunsEveryTick(): void
    {
        $backoff = new PollBackoff($this->clock);

        for ($tick = 0; $tick < 5; ++$tick) {
            self::assertTrue($this->slice($backoff, 0.001), 'tick '.$tick.' polled');
        }
    }

    public function testASliceExactlyOnBudgetIsStillQuick(): void
    {
        $backoff = new PollBackoff($this->clock);

        $this->slice($backoff, PollBackoff::BUDGET_MICROS / 1_000_000);

        self::assertTrue($this->slice($backoff, 0.001), 'the budget is what is allowed, not what is too much');
    }

    public function testAnOverrunStandsThePollDownForOneTick(): void
    {
        $backoff = new PollBackoff($this->clock);

        $this->slice($backoff, 0.2);

        self::assertFalse($this->slice($backoff, 0.001), 'the tick after an overrun is skipped');
        self::assertTrue($this->slice($backoff, 0.001), 'and the one after that polls again');
    }

    public function testEachFurtherOverrunDoublesTheGap(): void
    {
        $backoff = new PollBackoff($this->clock);
        $gaps = [];

        $this->slice($backoff, 0.2); // the first overrun arms the first gap
        for ($round = 0; $round < 5; ++$round) {
            // Every slice here overruns, so the call that ends one count arms
            // the gap the next one measures.
            $gaps[] = $this->ticksSkippedBefore($backoff, 0.2);
        }

        self::assertSame([1, 2, 4, 8, 16], $gaps);
    }

    public function testTheGapIsCappedSoThePollNeverStopsAltogether(): void
    {
        $backoff = new PollBackoff($this->clock);

        // Far more overruns than the cap needs: 2^20 ticks would be three hours.
        $this->slice($backoff, 0.2);
        for ($round = 0; $round < 20; ++$round) {
            $this->ticksSkippedBefore($backoff, 0.2);
        }

        $skipped = $this->ticksSkippedBefore($backoff, 0.001);

        self::assertSame(PollBackoff::MAX_SKIPS, $skipped, 'a sick disk is still checked every 30s');
    }

    public function testAQuickSliceForgetsTheGapAltogether(): void
    {
        $backoff = new PollBackoff($this->clock);
        // Two overruns in a row: the gap is at two ticks and the next would be
        // four if nothing reset it.
        $this->slice($backoff, 0.2);
        self::assertSame(1, $this->ticksSkippedBefore($backoff, 0.2), 'the second overrun');
        self::assertSame(2, $this->ticksSkippedBefore($backoff, 0.001), 'then a QUICK slice');

        $this->slice($backoff, 0.2); // slow again, from a clean slate

        self::assertFalse($this->slice($backoff, 0.001));
        self::assertTrue($this->slice($backoff, 0.001), 'the ladder started over from one tick');
    }

    public function testAFinishWithNoSliceBehindItReadsNothing(): void
    {
        // A finished() the stopwatch knows nothing about: the reconciler's slice
        // can throw, and the next tick then starts its own. What must not happen
        // is a finish reading the LAST slice's start as its own — the gap
        // between two ticks would measure as one enormous slice and stand the
        // poll down for half a minute over a quick one.
        $backoff = new PollBackoff($this->clock);
        $this->slice($backoff, 0.001); // quick: nothing armed, stopwatch cleared
        $this->clock->sleep(60.0); // a long gap between TICKS, not inside one

        $backoff->finished();

        self::assertTrue($backoff->mayRun(), 'a stray finish measures nothing');
    }

    public function testAStrayFinishDoesNotCancelAnArmedGap(): void
    {
        $backoff = new PollBackoff($this->clock);
        $this->slice($backoff, 0.2); // arms one skip

        $backoff->finished(); // nothing was running

        self::assertFalse($backoff->mayRun(), 'the armed skip survives it');
        self::assertTrue($backoff->mayRun(), 'and is spent exactly once');
    }
}
