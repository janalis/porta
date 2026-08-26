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

    public function testRenderAndWriteSplitsKeepTheirOwnPercentiles(): void
    {
        $perf = $this->collector();

        $perf->passMark();
        // Distinct, rounding-hostile values: an argument swap cannot hide, and
        // a 1000-vs-999 ms multiplier mutant shifts the first decimal.
        $perf->paintMark(0.0505, 0.0705);

        $perf->beat();

        $log = $this->log($perf);
        $this->assertStringContainsString('renderMs p50=50.5 p95=50.5', $log);
        $this->assertStringContainsString('writeMs p50=70.5 p95=70.5', $log);
        $this->assertStringContainsString('paintMs p50=121.0', $log, 'the total stays the sum');
        $this->assertStringContainsString('busy=121', $log);
    }

    public function testASlowPaintAppendsAnImmediateLineNamingTheLayer(): void
    {
        $perf = new PerfCollector(
            $this->project->dir,
            $this->clock,
            loadAvg: static fn (): float => 12.34,
        );
        $perf->setPendingBytesProbe(static fn (): int => 7);

        // Values whose 999x multiple crosses a rounding boundary (150.51 vs
        // 150.36; 100.51 vs 100.41), so a 1000-vs-999 mutant changes the digit.
        $perf->paintMark(0.15051, 0.10051);

        $log = $this->log($perf);
        $this->assertStringContainsString('slow-paint render=151ms write=101ms pendingBytes=7 load=12.3', $log);

        // The complementary rounding boundary (149.49 vs 149.64) catches a
        // 1001x mutant the first pair cannot.
        $perf->paintMark(0.14949, 0.10049);
        $this->assertStringContainsString('slow-paint render=149ms write=100ms', $this->log($perf));

        $perf->paintMark(0.1, 0.0); // exactly the threshold still fires
        $this->assertStringContainsString('render=100ms write=0ms', $this->log($perf));
    }

    public function testAPaintJustUnderTheThresholdStaysQuiet(): void
    {
        $perf = $this->collector();

        $perf->paintMark(0.0989, 0.001); // 0.0999 total: under the 0.1 threshold

        $this->assertFileDoesNotExist($perf->logFile());
    }

    public function testTheBeatCarriesTheBacklogProbeAndLoad(): void
    {
        $perf = new PerfCollector(
            $this->project->dir,
            $this->clock,
            loadAvg: static fn (): float => 3.21,
        );
        $perf->setPendingBytesProbe(static fn (): int => 4_096);
        $perf->passMark();
        $perf->beat();

        $this->assertStringContainsString('pend=4096 load=3.2', $this->log($perf));
    }

    public function testWithoutAProbeTheBeatReportsZeroPendingAndARealLoad(): void
    {
        $perf = $this->collector(); // default loadAvg: the real sys_getloadavg
        $perf->passMark();
        $perf->beat();
        $this->assertMatchesRegularExpression('/pend=0 load=\d+\.\d$/m', $this->log($perf));

        // A probe-less slow paint reports the same zero-backlog fallback.
        $perf->paintMark(0.2, 0.0);
        $this->assertMatchesRegularExpression('/slow-paint render=200ms write=0ms pendingBytes=0 load=\d+\.\d$/m', $this->log($perf));
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
