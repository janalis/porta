<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;
use Porta\I18n\PaneReorder;
use Porta\Support\PerfCollector;
use Porta\Tests\Support\FakeScheduler;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\TempProject;
use Porta\Ui\BidiTui;
use Porta\Ui\PaneSegmenter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Style\StyleSheet;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * The frame-rate cap: with pacing wired, a frame inside MIN_FRAME_INTERVAL of
 * the previous one is skipped and a single trailing wakeup repaints the
 * pending state when the window elapses — an input flood coalesces to the cap
 * rate while the final resting frame always lands. Without setFramePacing the
 * cap is inert (bare rigs), and force bypasses it (resize, test frames).
 */
final class BidiTuiFramePacingTest extends TestCase
{
    private VirtualTerminal $terminal;
    private MockClock $clock;
    private FakeScheduler $scheduler;
    private Renderer $renderer;

    /** The mutable widget every test scrolls: bumping $line changes the frame. */
    private AbstractWidget $widget;

    #[\Override]
    protected function setUp(): void
    {
        $this->terminal = new VirtualTerminal(columns: 20, rows: 2);
        $this->clock = new MockClock('2026-08-26 12:00:00');
        $this->scheduler = new FakeScheduler();
        $this->renderer = new Renderer(new StyleSheet());
    }

    private function pacedTui(): BidiTui
    {
        $tui = new BidiTui(terminal: $this->terminal, renderer: $this->renderer);
        $this->widget = new class extends AbstractWidget {
            public int $line = 0;

            public function render(RenderContext $context): array
            {
                return ['line '.$this->line];
            }
        };
        $tui->add($this->widget);
        $tui->setFramePacing($this->clock, $this->scheduler);

        return $tui;
    }

    /** Bump the widget content and request a (non-forced) repaint. */
    private function scroll(BidiTui $tui): void
    {
        ++$this->widget->line; // @phpstan-ignore-line property on the anonymous class
        $this->widget->invalidate();
        $tui->requestRender();
    }

    /** One defer-only loop turn (runs already-queued defers, no timers). */
    private function microTurn(): void
    {
        $suspension = \Revolt\EventLoop::getSuspension();
        \Revolt\EventLoop::defer($suspension->resume(...));
        $suspension->suspend();
    }

    public function testASecondFrameInsideTheIntervalIsSkipped(): void
    {
        $tui = $this->pacedTui();
        $tui->start();
        $this->microTurn(); // first frame paints immediately (no lastFrameAt yet)
        $this->assertStringContainsString('line 0', $this->terminal->getOutput());

        $this->clock->sleep(0.005);
        $this->scroll($tui);
        $this->microTurn();

        $this->assertStringNotContainsString('line 1', $this->terminal->getOutput(), 'the frame inside the window was not generated');
        $this->assertTrue((bool) Probe::get($tui, 'renderRequested'), 'the request stays pending');
        $this->assertCount(1, $this->scheduler->delays, 'one trailing wakeup armed');
        $this->assertEqualsWithDelta(BidiTui::MIN_FRAME_INTERVAL - 0.005, \array_first($this->scheduler->delays), 0.0005, 'armed for the remainder of the window');
        $tui->stop();
    }

    public function testAFrameExactlyAtTheIntervalPaints(): void
    {
        $tui = $this->pacedTui();
        $tui->start();
        $this->microTurn();

        $this->clock->sleep(BidiTui::MIN_FRAME_INTERVAL);
        $this->scroll($tui);
        $this->microTurn();

        $this->assertStringContainsString('line 1', $this->terminal->getOutput(), 'exactly one interval later is not "inside" the window');
        $this->assertSame([], $this->scheduler->scheduled, 'no wakeup armed for a painted frame');
        $tui->stop();
    }

    public function testOnlyOneTrailingWakeupArmsPerCapWindow(): void
    {
        $tui = $this->pacedTui();
        $tui->start();
        $this->microTurn();

        $this->clock->sleep(0.004);
        $this->scroll($tui);
        $this->microTurn();
        $this->clock->sleep(0.004);
        $this->scroll($tui);
        $this->microTurn();

        $this->assertCount(1, $this->scheduler->scheduled, 'the second capped skip reuses the armed wakeup');
        $tui->stop();
    }

    public function testTheTrailingWakeupPaintsTheFinalRestingFrame(): void
    {
        $tui = $this->pacedTui();
        $tui->start();
        $this->microTurn();

        $this->clock->sleep(0.005);
        $this->scroll($tui);
        $this->microTurn(); // capped: pending + wakeup armed

        $this->clock->sleep(BidiTui::MIN_FRAME_INTERVAL);
        $this->scheduler->fire();
        $this->microTurn(); // the wakeup's deferred tick

        $this->assertStringContainsString('line 1', $this->terminal->getOutput(), 'the flood\'s last state landed');
        $this->assertFalse((bool) Probe::get($tui, 'renderRequested'));
        $tui->stop();
    }

    public function testAForcedRequestBypassesTheCap(): void
    {
        $tui = $this->pacedTui();
        $tui->start();
        $this->microTurn();

        $this->clock->sleep(0.001); // deep inside the window
        ++$this->widget->line;
        $this->widget->invalidate();
        $tui->requestRender(force: true); // the test-frame / resize idiom
        $this->microTurn();

        $this->assertStringContainsString('line 1', $this->terminal->getOutput(), 'force paints immediately');
        $tui->stop();
    }

    public function testAnUnpacedTuiNeverCaps(): void
    {
        $tui = new BidiTui(terminal: $this->terminal, renderer: $this->renderer);
        $widget = new class extends AbstractWidget {
            public int $line = 0;

            public function render(RenderContext $context): array
            {
                return ['line '.$this->line];
            }
        };
        $tui->add($widget);
        $tui->start();
        $this->microTurn();

        ++$widget->line;
        $widget->invalidate();
        $tui->requestRender(); // back-to-back, zero time elapsed
        $this->microTurn();

        $this->assertStringContainsString('line 1', $this->terminal->getOutput(), 'without setFramePacing the cap is inert');
        $tui->stop();
    }

    public function testStopCancelsAPendingTrailingWakeup(): void
    {
        $tui = $this->pacedTui();
        $tui->start();
        $this->microTurn();

        $this->clock->sleep(0.005);
        $this->scroll($tui);
        $this->microTurn();
        $this->assertCount(1, $this->scheduler->scheduled);

        $tui->stop();

        $this->assertCount(1, $this->scheduler->cancelled, 'stop() disarmed the wakeup');
        $this->assertSame([], $this->scheduler->scheduled);
    }

    public function testCapSkipsFeedThePerfSink(): void
    {
        $tui = $this->pacedTui();
        $perfProject = new TempProject(); // held: its destructor removes the dir
        $perf = new PerfCollector($perfProject->dir, $this->clock);
        $tui->setPerfSink($perf);
        $tui->start();
        $this->microTurn();

        $this->clock->sleep(0.005);
        $this->scroll($tui);
        $this->microTurn();

        $this->assertSame(1, Probe::get($perf, 'capSkips'));
        $this->assertSame(1, Probe::get($perf, 'paints'), 'only the initial frame painted; the capped one fed the skip counter');
        $tui->stop();
    }

    public function testTheMirroredPathCapsAndStampsTheSameWay(): void
    {
        $tui = $this->pacedTui();
        $tui->setPaneReorder(new PaneSegmenter($this->renderer, new class implements \Porta\Overlay\Region\FloatingRegions {
            public function regions(): array
            {
                return [];
            }
        }), new PaneReorder());
        $tui->start();
        $this->microTurn();
        // The reorder rewrites the row, so assert on the digit, which survives.
        $this->assertStringContainsString('0', $this->terminal->getOutput());

        $this->clock->sleep(0.005);
        $this->scroll($tui);
        $this->microTurn();
        $this->assertStringNotContainsString('1', $this->terminal->getOutput(), 'the mirrored path is capped too');

        $this->clock->sleep(BidiTui::MIN_FRAME_INTERVAL);
        $this->scheduler->fire();
        $this->microTurn();
        $this->assertStringContainsString('1', $this->terminal->getOutput(), 'and its trailing frame stamps lastFrameAt');
        $this->assertFalse((bool) Probe::get($tui, 'renderRequested'), 'the mirrored paint consumed the request');
        $tui->stop();
    }

    public function testPaintDurationsReachTheSink(): void
    {
        // The widget advances the clock DURING its render, so the paint
        // duration is observable: paintMs must report exactly that time.
        $tui = new BidiTui(terminal: $this->terminal, renderer: $this->renderer);
        $clock = $this->clock;
        $tui->add(new class($clock) extends AbstractWidget {
            public function __construct(
                private readonly MockClock $clock,
            ) {}

            public function render(RenderContext $context): array
            {
                $this->clock->sleep(0.003); // simulated render cost

                return ['x'];
            }
        });
        $tui->setFramePacing($this->clock, $this->scheduler);
        $perfProject = new TempProject(); // held: its destructor removes the dir
        $perf = new PerfCollector($perfProject->dir, $this->clock);
        $tui->setPerfSink($perf);
        $tui->start();
        $this->microTurn();

        $durations = Probe::get($perf, 'renderDurations');
        $this->assertIsArray($durations);
        $this->assertEqualsWithDelta(0.003, $durations[0], 0.0001, 'the widget-tree time lands in the render split');
        $writes = Probe::get($perf, 'writeDurations');
        $this->assertIsArray($writes);
        $this->assertEqualsWithDelta(0.0, $writes[0], 0.0001, 'the frozen clock puts nothing in the write split');
        $tui->stop();
    }

    public function testTheReorderIsTimedApartFromTheTerminalWrite(): void
    {
        // perf.log's whole promise is "render-heavy = the widget tree,
        // write-heavy = the tty", and the RTL reorder sits between the two with
        // no name of its own: measured at the user's 226x67, reordering one
        // frame costs ~8ms, which was reported as eight milliseconds of
        // terminal write on a tty holding zero pending bytes. So the stage that
        // has cost this project five rounds was invisible in the one log that
        // would have shown it.
        $tui = new BidiTui(terminal: $this->terminal, renderer: $this->renderer);
        $tui->add(new class extends AbstractWidget {
            public function render(RenderContext $context): array
            {
                return ['x'];
            }
        });
        $clock = $this->clock;
        // The regions are asked for INSIDE the reorder stage, so a sleep here is
        // time genuinely spent between the widget tree and the terminal.
        $tui->setPaneReorder(new PaneSegmenter($this->renderer, new readonly class($clock) implements \Porta\Overlay\Region\FloatingRegions {
            public function __construct(
                private MockClock $clock,
            ) {}

            public function regions(): array
            {
                $this->clock->sleep(0.004); // simulated reorder cost

                return [];
            }
        }), new PaneReorder());
        $tui->setFramePacing($this->clock, $this->scheduler);
        $perfProject = new TempProject(); // held: its destructor removes the dir
        $perf = new PerfCollector($perfProject->dir, $this->clock);
        $tui->setPerfSink($perf);
        $tui->start();
        $this->microTurn();

        $bidi = Probe::get($perf, 'bidiDurations');
        $this->assertIsArray($bidi);
        $this->assertEqualsWithDelta(0.004, $bidi[0], 0.0001, 'the reorder time lands in its own split');
        $writes = Probe::get($perf, 'writeDurations');
        $this->assertIsArray($writes);
        $this->assertEqualsWithDelta(0.0, $writes[0], 0.0001, 'and NOT in the terminal write, which cost nothing');
        $totals = Probe::get($perf, 'paintDurations');
        $this->assertIsArray($totals);
        $this->assertEqualsWithDelta(0.004, $totals[0], 0.0001, 'the frame total still counts it');
        $tui->stop();
    }

    public function testASlowRenderWritesTheSlowPaintLineThroughTheRealPaintPath(): void
    {
        $tui = new BidiTui(terminal: $this->terminal, renderer: $this->renderer);
        $clock = $this->clock;
        $tui->add(new class($clock) extends AbstractWidget {
            public function __construct(
                private readonly MockClock $clock,
            ) {}

            public function render(RenderContext $context): array
            {
                $this->clock->sleep(0.15); // a genuinely slow widget tree

                return ['x'];
            }
        });
        $tui->setFramePacing($this->clock, $this->scheduler);
        $perfProject = new TempProject(); // held: its destructor removes the dir
        $perf = new PerfCollector($perfProject->dir, $this->clock, loadAvg: static fn (): float => 1.0);
        $tui->setPerfSink($perf);
        $tui->start();
        $this->microTurn();

        $log = (string) \file_get_contents($perf->logFile());
        $this->assertStringContainsString('slow-paint render=150ms bidi=0ms write=0ms', $log, 'the bad frame named its layer on the spot');
        $tui->stop();
    }

    public function testAnUnpacedPaintRecordsAZeroDuration(): void
    {
        $tui = new BidiTui(terminal: $this->terminal, renderer: $this->renderer);
        $tui->add(new class extends AbstractWidget {
            public function render(RenderContext $context): array
            {
                return ['x'];
            }
        });
        $perfProject = new TempProject(); // held: its destructor removes the dir
        $perf = new PerfCollector($perfProject->dir, $this->clock);
        $tui->setPerfSink($perf);
        $tui->start();
        $this->microTurn();

        $this->assertSame([0.0], Probe::get($perf, 'paintDurations'), 'no clock wired: paints count with a zero duration');
        // Every stage, not just the total: the stamps are all 0.0 on this path,
        // and a single wrong one would leave the total right and the splits
        // nonsense — one stage a second long and the next a second short.
        $this->assertSame([0.0], Probe::get($perf, 'renderDurations'));
        $this->assertSame([0.0], Probe::get($perf, 'bidiDurations'));
        $this->assertSame([0.0], Probe::get($perf, 'writeDurations'));
        $tui->stop();
    }

    public function testStopCancelsAPendingImmediateTick(): void
    {
        $tui = $this->pacedTui();
        $tui->start();
        $this->microTurn();

        $this->scroll($tui); // queues a deferred tick
        $this->assertTrue((bool) Probe::get($tui, 'immediateTickQueued'));
        $deferId = Probe::get($tui, 'immediateTickId');
        $this->assertIsString($deferId);
        $tui->stop();

        $this->assertFalse((bool) Probe::get($tui, 'immediateTickQueued'), 'the flag reset with the cancel');
        $this->assertNull(Probe::get($tui, 'immediateTickId'));
        $this->assertNotContains($deferId, \Revolt\EventLoop::getIdentifiers(), 'the pending defer was cancelled, not leaked');
        $this->assertFalse($tui->isRunning(), 'the override still delegates to the vendor stop');
    }

    public function testARestartAfterStopStillPaints(): void
    {
        // The stop-time cleanup must leave the dedup flag ready for a restart.
        $tui = $this->pacedTui();
        $tui->start();
        $this->microTurn();
        $this->scroll($tui); // pending defer at stop time
        $tui->stop();

        $this->clock->sleep(1.0);
        $tui->start();
        $this->microTurn();
        $this->clock->sleep(1.0);
        $this->scroll($tui);
        $this->microTurn();

        $this->assertStringContainsString('line', $this->terminal->getOutput(), 'the restarted Tui queues ticks again');
        $tui->stop();
    }

    public function testAWheelFloodPaintsAtMostAtTheCapRate(): void
    {
        $tui = $this->pacedTui();
        $perfProject = new TempProject(); // held: its destructor removes the dir
        $perf = new PerfCollector($perfProject->dir, $this->clock);
        $tui->setPerfSink($perf);
        $tui->start();
        $this->microTurn();

        // 100 scroll events at 5ms spacing = a 200 events/s trackpad fling.
        for ($i = 0; $i < 100; ++$i) {
            $this->clock->sleep(0.005);
            $this->scroll($tui);
            $this->microTurn();
        }

        $this->assertNotNull(Probe::get($tui, 'lastFrameAt'), 'frames stamped the cap window');
        $painted = Probe::get($perf, 'paints');
        // Events land on a 5ms grid, so paints land on the 20ms grid (the first
        // event past each 16.7ms window): 500ms of flood ≈ 25 paints + the
        // initial frame — versus 101 unpaced (the companion test below).
        $this->assertGreaterThanOrEqual(24, $painted, 'the flood still paints near the cap rate');
        $this->assertLessThanOrEqual(31, $painted, 'the flood cannot exceed the cap rate');
        $this->assertGreaterThanOrEqual(69, Probe::get($perf, 'capSkips'), 'the majority of events were coalesced away');
        $tui->stop();
    }

    public function testAnUnpacedFloodPaintsEveryEvent(): void
    {
        // The bug this cap fixes, pinned: without pacing, every event = a frame.
        $tui = new BidiTui(terminal: $this->terminal, renderer: $this->renderer);
        $widget = new class extends AbstractWidget {
            public int $line = 0;

            public function render(RenderContext $context): array
            {
                return ['line '.$this->line];
            }
        };
        $tui->add($widget);
        $perfProject = new TempProject(); // held: its destructor removes the dir
        $perf = new PerfCollector($perfProject->dir, $this->clock);
        $tui->setPerfSink($perf);
        $tui->start();
        $this->microTurn();

        for ($i = 0; $i < 50; ++$i) {
            ++$widget->line;
            $widget->invalidate();
            $tui->requestRender();
            $this->microTurn();
        }

        $this->assertSame(51, Probe::get($perf, 'paints'), 'one frame per event, plus the initial frame');
        $tui->stop();
    }
}
