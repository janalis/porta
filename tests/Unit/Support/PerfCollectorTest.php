<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Porta\Support\PerfCollector;
use Porta\Tests\Support\TempProject;
use Porta\Ui\FrameHooks;
use Revolt\EventLoop;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Tui\Event\InputEvent;

final class PerfCollectorTest extends TestCase
{
    private TempProject $project;
    private MockClock $clock;

    #[\Override]
    protected function setUp(): void
    {
        $this->project = new TempProject();
        $this->clock = new MockClock('2026-08-26 10:00:00');
    }

    private function collector(): PerfCollector
    {
        return new PerfCollector($this->project->dir, $this->clock);
    }

    private function log(PerfCollector $perf): string
    {
        return (string) file_get_contents($perf->logFile());
    }

    public function testABeatLogsPassGapPercentiles(): void
    {
        $perf = $this->collector();

        $perf->passMark();
        $this->clock->modify('+10 milliseconds');
        $perf->passMark();
        $this->clock->modify('+30 milliseconds');
        $perf->passMark();
        $perf->beat();

        $log = $this->log($perf);
        $this->assertStringContainsString('passes=3', $log);
        $this->assertStringContainsString('gapMs p50=10.0 p95=30.0 max=30.0', $log);
        $this->assertStringContainsString('2026-08-26 10:00:00', $log);
    }

    public function testWheelInputsAreCountedSeparately(): void
    {
        $perf = $this->collector();

        $perf->countInput(new InputEvent("\x1b[<65;10;5M")); // wheel down
        $perf->countInput(new InputEvent("\x1b[<64;10;5M")); // wheel up
        $perf->countInput(new InputEvent('x')); // a keystroke
        $perf->countInput(new InputEvent("\x1b[<35;10;5M")); // bare motion: not a wheel
        $perf->beat();

        $this->assertStringContainsString('in=4 wheel=2', $this->log($perf));
    }

    public function testPaintDurationsFeedPaintMsAndBusy(): void
    {
        $perf = $this->collector();

        $perf->passMark();
        $perf->paintMark(0.006);
        $perf->paintMark(0.010);
        $perf->beat();

        $log = $this->log($perf);
        $this->assertStringContainsString('paints=2', $log);
        $this->assertStringContainsString('paintMs p50=6.0 p95=10.0', $log);
        $this->assertStringContainsString('busy=16', $log);
    }

    public function testSkipAndCoalescedCountersLand(): void
    {
        $perf = $this->collector();

        $perf->passMark();
        $perf->countPaceSkip();
        $perf->countCapSkip();
        $perf->countCapSkip();
        $perf->countCoalesced();
        $perf->beat();

        $this->assertStringContainsString('paceSkip=1 capSkip=2 coalesced=1', $this->log($perf));
    }

    public function testAnIdleBeatWritesNothing(): void
    {
        $perf = $this->collector();

        $perf->beat();

        $this->assertFileDoesNotExist($perf->logFile());
    }

    public function testCountersResetBetweenBeats(): void
    {
        $perf = $this->collector();
        $perf->passMark();
        $this->clock->modify('+50 milliseconds');
        $perf->passMark();
        $perf->beat();

        // The next window starts clean — and its first pass has no predecessor,
        // so the idle stretch between windows never lands as one giant gap.
        $this->clock->modify('+90 seconds');
        $perf->passMark();
        $perf->beat();

        $lines = array_values(array_filter(explode("\n", $this->log($perf))));
        $this->assertCount(2, $lines);
        $this->assertStringContainsString('passes=1', $lines[1]);
        $this->assertStringContainsString('gapMs p50=0.0 p95=0.0 max=0.0', $lines[1]);
    }

    public function testTheFrameHookRegistersOnConstruction(): void
    {
        $hooks = new class implements FrameHooks {
            /** @var list<callable(): void> */
            public array $hooks = [];

            #[\Override]
            public function onBeforeFrame(callable $hook): void
            {
                $this->hooks[] = $hook;
            }
        };

        $perf = new PerfCollector($this->project->dir, $this->clock, $hooks);
        $this->assertCount(1, $hooks->hooks);

        $hooks->hooks[0]();
        $perf->beat();
        $this->assertStringContainsString('passes=1', $this->log($perf));
    }

    public function testARealLoopBeatAppendsALine(): void
    {
        $perf = new PerfCollector($this->project->dir, new NativeClock(), interval: 0.05);

        $perf->start();
        $perf->passMark();
        $suspension = EventLoop::getSuspension();
        $timer = EventLoop::delay(0.2, $suspension->resume(...));
        try {
            $suspension->suspend();
        } finally {
            EventLoop::cancel($timer);
        }
        $perf->stop();

        $this->assertFileExists($perf->logFile());
        $this->assertStringContainsString('passes=1', $this->log($perf));
    }
}
