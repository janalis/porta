<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Input;

use PHPUnit\Framework\TestCase;
use Porta\Input\ZoomGesture;

/**
 * The burst rule. Real time, like the double-click window it is modelled on —
 * there is one sleep, of 120ms, and the whole point of the 0.1s window is that
 * it sits an order of magnitude away from both cases it separates.
 */
final class ZoomGestureTest extends TestCase
{
    public function testTheFirstNotchAlwaysStepsTheLadder(): void
    {
        $this->assertNull(new ZoomGesture()->glideFactor(1), 'nothing to continue yet');
    }

    public function testConsecutiveNotchesGlideInBothDirections(): void
    {
        $gesture = new ZoomGesture();
        $gesture->glideFactor(1);

        $in = $gesture->glideFactor(1);
        $this->assertNotNull($in);
        $this->assertGreaterThan(1.0, $in, 'zooming in magnifies');

        $out = $gesture->glideFactor(-1);
        $this->assertNotNull($out);
        $this->assertLessThan(1.0, $out, 'zooming out shrinks');
        $this->assertSame(1.0, round($in * $out, precision: 9), 'and the two are exact inverses, so a jiggle returns');
    }

    public function testANotchAfterThePauseStandsAloneAgain(): void
    {
        $gesture = new ZoomGesture();
        $gesture->glideFactor(1);
        $this->assertNotNull($gesture->glideFactor(1), 'still the same gesture');

        usleep(120_000); // past the 0.1s window: a deliberate second wheel click
        $this->assertNull($gesture->glideFactor(1), 'the ladder takes over again');
    }
}
