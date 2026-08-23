<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Input;

use PHPUnit\Framework\TestCase;
use Porta\Input\ClickCounter;
use Porta\Tests\Support\Probe;
use Symfony\Component\Clock\MockClock;

/**
 * The double/triple-click window, on a MockClock. The expiry case was
 * untestable before the clock became an input: only a real 400ms wait could
 * reach it, so no test held the window itself.
 */
final class ClickCounterTest extends TestCase
{
    public function testConsecutiveClicksInTheSameCellCountUpAndCapAtTriple(): void
    {
        $clock = new MockClock();
        $counter = new ClickCounter($clock);

        $this->assertSame(1, $counter->countAt(3, 7));
        $clock->sleep(0.1);
        $this->assertSame(2, $counter->countAt(3, 7), 'a second press inside the window doubles');
        $clock->sleep(0.1);
        $this->assertSame(3, $counter->countAt(3, 7), 'a third triples');
        $clock->sleep(0.1);
        $this->assertSame(3, $counter->countAt(3, 7), 'and the multiplicity caps there');
    }

    public function testAPressInAnotherCellStartsOver(): void
    {
        $clock = new MockClock();
        $counter = new ClickCounter($clock);

        $counter->countAt(3, 7);
        $this->assertSame(1, $counter->countAt(3, 8), 'one column over is a new click');
    }

    public function testAnExpiredWindowStartsOverInTheSameCell(): void
    {
        $clock = new MockClock();
        $counter = new ClickCounter($clock);

        $counter->countAt(3, 7);
        $clock->sleep(0.5); // past the 0.4s window
        $this->assertSame(1, $counter->countAt(3, 7), 'the same cell, but too late to be a double-click');
    }

    public function testAPressExactlyAtTheWindowBoundaryIsExpired(): void
    {
        // A real wall-clock sleep(0.4) never lands bit-exactly on 0.4 (double
        // rounding), so the boundary itself is only reachable by placing the
        // clock at exactly epoch 0.4s while the counter's own internal
        // "$lastTime = 0.0" default is still in effect: 0.4 - 0.0 is exact.
        $clock = new MockClock('@0.4');
        $counter = new ClickCounter($clock);
        Probe::set($counter, 'lastRow', 3);
        Probe::set($counter, 'lastCol', 7);
        Probe::set($counter, 'count', 1);

        $this->assertSame(1, $counter->countAt(3, 7), 'elapsed time equal to the window is already too late: strict "<", not "<="');
    }
}
