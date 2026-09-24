<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;
use Porta\Ui\Density;
use Porta\Ui\ScreenDensity;

/** One threshold for every surface: thirty terminal rows and up is roomy. */
final class DensityTest extends TestCase
{
    public function testTheThresholdIsThirtyRows(): void
    {
        $this->assertSame(Density::Compact, Density::of(29));
        $this->assertSame(Density::Roomy, Density::of(30));
        $this->assertSame(Density::Compact, Density::of(0));
    }

    public function testTheHolderIsRoomyUntilObservedAndSaysWhenItFlips(): void
    {
        $density = new ScreenDensity();
        $this->assertSame(Density::Roomy, $density->current());

        $this->assertFalse($density->observe(40), 'already roomy');
        $this->assertTrue($density->observe(29));
        $this->assertSame(Density::Compact, $density->current());
        $this->assertFalse($density->observe(24), 'still compact');
        $this->assertTrue($density->observe(30));
        $this->assertSame(Density::Roomy, $density->current());
    }
}
