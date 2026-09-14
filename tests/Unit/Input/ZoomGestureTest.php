<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Input;

use PHPUnit\Framework\TestCase;
use Porta\Input\ZoomGesture;
use Symfony\Component\Clock\MockClock;

/**
 * The burst rule, on a MockClock: the window is driven with ->sleep() instead
 * of a real 120ms usleep, so the pause case is deterministic under any machine
 * load and the suite no longer pays wall-clock time for it.
 */
final class ZoomGestureTest extends TestCase
{
    public function testTheFirstNotchAlwaysStepsTheLadder(): void
    {
        $this->assertNull(new ZoomGesture(new MockClock())->glideFactor(1), 'nothing to continue yet');
    }

    public function testConsecutiveNotchesGlideInBothDirections(): void
    {
        $clock = new MockClock();
        $gesture = new ZoomGesture($clock);
        $gesture->glideFactor(1);
        $clock->sleep(0.02); // trackpad notch spacing, well inside the window

        $in = $gesture->glideFactor(1);
        $this->assertNotNull($in);
        $this->assertGreaterThan(1.0, $in, 'zooming in magnifies');

        $clock->sleep(0.02);
        $out = $gesture->glideFactor(-1);
        $this->assertNotNull($out);
        $this->assertLessThan(1.0, $out, 'zooming out shrinks');
        $this->assertSame(1.0, \round($in * $out, precision: 9), 'and the two are exact inverses, so a jiggle returns');
    }

    public function testANotchAfterThePauseStandsAloneAgain(): void
    {
        $clock = new MockClock();
        $gesture = new ZoomGesture($clock);
        $gesture->glideFactor(1);
        $this->assertNotNull($gesture->glideFactor(1), 'still the same gesture');

        $clock->sleep(0.12); // past the 0.1s window: a deliberate second wheel click
        $this->assertNull($gesture->glideFactor(1), 'the ladder takes over again');
    }

    public function testANotchExactlyAtTheWindowBoundaryStandsAlone(): void
    {
        // A real wall-clock sleep(0.1) never lands bit-exactly on 0.1 (double
        // rounding), so the boundary itself is only reachable by placing the
        // clock at exactly epoch 0.1s while the gesture's own internal
        // "$lastTime = 0.0" default is still in effect: 0.1 - 0.0 is exact.
        $clock = new MockClock('@0.1');
        $gesture = new ZoomGesture($clock);

        $this->assertNull($gesture->glideFactor(1), 'elapsed time equal to the window is already too late: strict "<", not "<="');
    }

    public function testANonPositiveDirectionShrinks(): void
    {
        $clock = new MockClock();
        $gesture = new ZoomGesture($clock);
        $gesture->glideFactor(1);
        $clock->sleep(0.02);

        $factor = $gesture->glideFactor(0);

        $this->assertNotNull($factor);
        $this->assertLessThan(1.0, $factor, 'dir=0 is not a positive direction, so it takes the shrink branch');
    }
}
