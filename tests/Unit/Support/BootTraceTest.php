<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Porta\Support\BootTrace;

final class BootTraceTest extends TestCase
{
    /** @param list<float> $readings */
    private static function nanos(array $readings): \Closure
    {
        $i = 0;

        return static function () use (&$i, $readings): float {
            return $readings[$i++];
        };
    }

    public function testMarksAreCumulativeMillisecondsSinceConstruction(): void
    {
        // Construction reads 0ns; the marks read 12ms and 45ms later.
        $trace = new BootTrace(nanos: self::nanos([0.0, 12e6, 45e6]));

        $trace->mark('container');
        $trace->mark('eager');

        $this->assertSame('boot container=12ms eager=45ms', $trace->line());
    }

    public function testThePreludeCoversTheTimeBeforeConstructionAndShiftsEveryMark(): void
    {
        // The process started 65ms before the wall read; the mark lands 10ms
        // after construction — cumulative from process start, so 75ms.
        $trace = new BootTrace(
            processStartSeconds: 100.000,
            nanos: self::nanos([0.0, 10e6]),
            wallSeconds: static fn (): float => 100.065,
        );

        $trace->mark('container');

        $this->assertSame('boot prelude=65ms container=75ms', $trace->line());
    }

    public function testAWallClockBehindTheProcessStartClampsThePreludeToZero(): void
    {
        $trace = new BootTrace(
            processStartSeconds: 100.000,
            nanos: self::nanos([0.0, 10e6]),
            wallSeconds: static fn (): float => 99.500,
        );

        $trace->mark('container');

        $this->assertSame('boot prelude=0ms container=10ms', $trace->line());
    }

    public function testWithoutAProcessStartThereIsNoPreludeMark(): void
    {
        $trace = new BootTrace(nanos: self::nanos([0.0, 5e6]));

        $trace->mark('firstPaint');

        $this->assertSame('boot firstPaint=5ms', $trace->line());
    }

    public function testTheDefaultClocksProduceAPlausibleLine(): void
    {
        $trace = new BootTrace(processStartSeconds: \microtime(true));

        $trace->mark('container');

        $this->assertMatchesRegularExpression('/^boot prelude=\d+ms container=\d+ms$/', $trace->line());
    }
}
