<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;
use Porta\I18n\PaneReorder;
use Porta\Tests\Support\Probe;
use Porta\Ui\BidiTui;
use Porta\Ui\PaneSegmenter;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Style\StyleSheet;
use Symfony\Component\Tui\Terminal\TerminalInterface;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * The bidi-aware Tui: with a per-pane reorder installed it maps every rendered
 * line to visual order before it reaches the screen; without one it defers to
 * the stock render path, and a frame with nothing pending is a no-op. A single
 * full-width widget is one pane, so the whole line reorders.
 */
final class BidiTuiTest extends TestCase
{
    use \Porta\Tests\Support\PumpsLoop;

    private const string SHALOM = "\u{05E9}\u{05DC}\u{05D5}\u{05DD}";
    private const string SHALOM_VISUAL = "\u{05DD}\u{05D5}\u{05DC}\u{05E9}";

    private Renderer $renderer;

    private function tuiWith(TerminalInterface $terminal): BidiTui
    {
        $this->renderer = new Renderer(new StyleSheet());
        $tui = new BidiTui(terminal: $terminal, renderer: $this->renderer);
        $tui->add(new class extends AbstractWidget {
            public function render(RenderContext $context): array
            {
                return [BidiTuiTest::wordLine()];
            }
        });

        return $tui;
    }

    /** Enable the per-pane RTL reorder (no dropdown regions in these single-widget frames). */
    private function enableRtl(BidiTui $tui): void
    {
        $tui->setPaneReorder(new PaneSegmenter($this->renderer, new class implements \Porta\Overlay\Region\FloatingRegions {
            public function regions(): array
            {
                return [];
            }
        }), new PaneReorder());
    }

    public static function wordLine(): string
    {
        return self::SHALOM;
    }

    public function testWithoutAReorderTheStockLineIsWritten(): void
    {
        $terminal = new VirtualTerminal(columns: 20, rows: 2);
        $tui = $this->tuiWith($terminal);
        $tui->requestRender();
        $tui->processRender();

        self::assertStringContainsString(self::SHALOM, $terminal->getOutput());
    }

    public function testARtlReorderRewritesEachLineToVisualOrder(): void
    {
        $terminal = new VirtualTerminal(columns: 20, rows: 2);
        $tui = $this->tuiWith($terminal);
        $this->enableRtl($tui);
        $tui->requestRender();
        $tui->processRender();

        $out = $terminal->getOutput();
        self::assertStringContainsString(self::SHALOM_VISUAL, $out, 'the Hebrew word is reversed to visual order');
        self::assertStringNotContainsString(self::SHALOM, $out, 'the logical-order form is not written');
    }

    public function testAFrameWithNothingPendingIsANoOp(): void
    {
        $terminal = new VirtualTerminal(columns: 20, rows: 2);
        $tui = $this->tuiWith($terminal);
        $this->enableRtl($tui);
        // No requestRender(): the reorder branch must early-return without writing.
        $tui->processRender();

        self::assertSame('', $terminal->getOutput());
    }

    public function testABackloggedTerminalSkipsFrameGenerationUntilDrained(): void
    {
        // The scroll-lag regression: frame generation must self-pace to the
        // terminal's drain rate instead of queueing stale frames.
        $pair = stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, protocol: 0);
        \assert(false !== $pair, description: 'stream_socket_pair created a socket pair');
        stream_set_blocking($pair[1], enable: false);
        $terminal = new \Porta\Ui\NonBlockingTerminal(new VirtualTerminal(columns: 20, rows: 2), $pair[0]);
        $terminal->start(static function (): void {}, static function (): void {}, static function (): void {});
        $tui = $this->tuiWith($terminal);

        $perfProject = new \Porta\Tests\Support\TempProject(); // held: its destructor removes the dir
        $perf = new \Porta\Support\PerfCollector($perfProject->dir, new \Symfony\Component\Clock\MockClock());
        $tui->setPerfSink($perf);

        $terminal->write(str_repeat('#', times: 524_288)); // a wedged tty: backlog past the socket buffer
        $backlog = $terminal->pendingBytes();
        $this->assertGreaterThan(0, $backlog);

        $tui->requestRender();
        $tui->processRender();

        $this->assertSame(1, Probe::get($perf, 'paceSkips'), 'the skipped generation was counted');
        $this->assertTrue((bool) Probe::get($tui, 'renderRequested'), 'the render request stays pending while the tty is behind');
        $this->assertSame($backlog, $terminal->pendingBytes(), 'no new frame bytes were generated into the backlog');

        // Drain the peer; the writer's watcher empties the queue as the loop runs.
        $suspension = \Revolt\EventLoop::getSuspension();
        $drain = \Revolt\EventLoop::repeat(0.01, static function () use ($pair, $terminal, $suspension): void {
            fread($pair[1], length: 65_536);
            if (0 === $terminal->pendingBytes()) {
                $suspension->resume();
            }
        });
        $suspension->suspend();
        \Revolt\EventLoop::cancel($drain);

        $tui->processRender();
        $this->assertFalse((bool) Probe::get($tui, 'renderRequested'), 'the queued request rendered once the tty caught up');
        $this->assertGreaterThan(0, $terminal->pendingBytes() + \strlen((string) fread($pair[1], length: 65_536)), 'the frame reached the terminal');

        $terminal->stop();
        fclose($pair[1]);
    }

    public function testADrainedBacklogRepaintsWithoutAManualPump(): void
    {
        $pair = stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, protocol: 0);
        \assert(false !== $pair, description: 'stream_socket_pair created a socket pair');
        stream_set_blocking($pair[1], enable: false);
        $terminal = new \Porta\Ui\NonBlockingTerminal(new VirtualTerminal(columns: 20, rows: 2), $pair[0]);
        $tui = $this->tuiWith($terminal);
        $tui->start();
        $this->microTurn(); // first frame out into the socket
        fread($pair[1], length: 65_536);

        $terminal->write(str_repeat('#', times: 524_288)); // a wedged tty
        $tui->requestRender();
        $this->microTurn(); // the deferred tick skips generation and arms the wakeup

        self::assertTrue((bool) Probe::get($tui, 'renderRequested'), 'the request stays pending while the tty is behind');
        self::assertTrue((bool) Probe::get($tui, 'drainWakeupArmed'));
        self::assertNotNull(Probe::get(Probe::object($terminal, 'writer'), 'onDrain'), 'the skip installed the drain hook on the live writer');

        // Drain the peer using defer-only turns: the 10ms ticker cannot fire
        // inside them, so ONLY the drain wakeup can paint the skipped frame.
        for ($i = 0; $i < 500 && 0 !== $terminal->pendingBytes(); ++$i) {
            fread($pair[1], length: 65_536);
            $this->microTurn();
        }
        self::assertSame(0, $terminal->pendingBytes(), 'the backlog drained');
        $this->microTurn(); // run the wakeup's deferred tick
        $this->microTurn();

        self::assertFalse((bool) Probe::get($tui, 'renderRequested'), 'the skipped frame painted at drain time, not on the ticker');

        // Empty the socket buffer first: stop()'s teardown escapes flush
        // BLOCKING, and a full buffer with no reader would park the worker.
        while ('' !== (string) fread($pair[1], length: 65_536)) {
        }
        $tui->stop();
        fclose($pair[1]);
    }

    public function testClearingTheReorderRestoresTheStockPath(): void
    {
        $terminal = new VirtualTerminal(columns: 20, rows: 2);
        $tui = $this->tuiWith($terminal);
        $this->enableRtl($tui);
        $tui->setPaneReorder(null, null);
        $tui->requestRender();
        $tui->processRender();

        self::assertStringContainsString(self::SHALOM, $terminal->getOutput());
    }

    public function testLogicalColumnMapsClicksBackThroughTheReorder(): void
    {
        $terminal = new VirtualTerminal(columns: 20, rows: 2);
        $tui = $this->tuiWith($terminal);
        $this->enableRtl($tui);
        $tui->requestRender();
        $tui->processRender();

        // Row 0 renders שלום (reversed on screen); the leftmost screen column
        // maps back to the last logical character.
        self::assertSame(3, $tui->logicalColumn(0, 0));
        self::assertSame(0, $tui->logicalColumn(0, 3));
    }

    public function testLogicalColumnIsIdentityWithoutAReorder(): void
    {
        $terminal = new VirtualTerminal(columns: 20, rows: 2);
        $tui = $this->tuiWith($terminal);
        $tui->requestRender();
        $tui->processRender();

        self::assertSame(5, $tui->logicalColumn(0, 5)); // no reorder → identity
    }

    public function testLogicalColumnIsIdentityForAnUnknownRow(): void
    {
        $terminal = new VirtualTerminal(columns: 20, rows: 2);
        $tui = $this->tuiWith($terminal);
        $this->enableRtl($tui);
        $tui->requestRender();
        $tui->processRender();

        self::assertSame(7, $tui->logicalColumn(99, 7)); // row not in the last frame
    }

    /**
     * One loop turn that runs ONLY already-queued defers — no timers. Proves
     * same-iteration behavior: the adaptive ticker's 10ms repeat cannot have
     * fired inside a defer-only turn.
     */
    private function microTurn(): void
    {
        $suspension = \Revolt\EventLoop::getSuspension();
        \Revolt\EventLoop::defer($suspension->resume(...));
        $suspension->suspend();
    }

    public function testARequestedFramePaintsInTheSameLoopIterationWithoutTheTicker(): void
    {
        $terminal = new VirtualTerminal(columns: 20, rows: 2);
        $tui = $this->tuiWith($terminal);
        $tui->start(); // start() itself requests the first render
        $tui->requestRender(); // a same-burst duplicate: with NO sink set, counting must be a no-op

        $this->microTurn();

        self::assertStringContainsString(self::SHALOM, $terminal->getOutput(), 'the frame painted before any 10ms ticker interval elapsed');
        $tui->stop();
    }

    public function testARequestBurstTicksExactlyOnce(): void
    {
        $terminal = new VirtualTerminal(columns: 20, rows: 2);
        $tui = $this->tuiWith($terminal);
        $perfProject = new \Porta\Tests\Support\TempProject(); // held: its destructor removes the dir
        $perf = new \Porta\Support\PerfCollector($perfProject->dir, new \Symfony\Component\Clock\MockClock());
        $tui->setPerfSink($perf);
        $ticks = 0;
        $tui->onTick(static function () use (&$ticks): bool {
            ++$ticks;

            return false;
        });
        $tui->start();

        $tui->requestRender();
        $tui->requestRender();
        self::assertTrue((bool) Probe::get($tui, 'immediateTickQueued'), 'a burst holds one queued tick');
        $this->microTurn();

        self::assertSame(1, $ticks, 'N requests in one iteration coalesce into one tick');
        self::assertGreaterThanOrEqual(1, Probe::get($perf, 'coalesced'), 'the deduped request was counted');
        self::assertFalse((bool) Probe::get($tui, 'immediateTickQueued'), 'the queue flag cleared after the tick');
        $tui->stop();
    }

    public function testARequestOnANeverStartedTuiQueuesNoTick(): void
    {
        $terminal = new VirtualTerminal(columns: 20, rows: 2);
        $tui = $this->tuiWith($terminal);

        $tui->requestRender();

        self::assertFalse((bool) Probe::get($tui, 'immediateTickQueued'), 'no tick is queued while the Tui is not running');
    }

    public function testAStopBetweenQueueAndFireSuppressesTheTick(): void
    {
        $terminal = new VirtualTerminal(columns: 20, rows: 2);
        $tui = $this->tuiWith($terminal);
        $tui->start();
        $this->microTurn(); // first frame out of the way
        $terminal->clearOutput();

        $tui->requestRender();
        $tui->stop();
        $baseline = $terminal->getOutput(); // stop()'s own teardown writes
        $this->microTurn();

        self::assertSame($baseline, $terminal->getOutput(), 'the queued tick did not paint into the stopped terminal');
    }

    public function testADefaultRequestRenderDiffsInsteadOfForcingAFullRepaint(): void
    {
        $terminal = new VirtualTerminal(columns: 20, rows: 3);
        $this->renderer = new Renderer(new StyleSheet());
        $tui = new BidiTui(terminal: $terminal, renderer: $this->renderer);
        $widget = new class extends AbstractWidget {
            public string $second = 'aaaa';

            public function render(RenderContext $context): array
            {
                return ['stable', $this->second];
            }
        };
        $tui->add($widget);
        $tui->start();
        $this->microTurn();
        self::assertStringContainsString('stable', $terminal->getOutput());

        $widget->second = 'bbbb';
        $widget->invalidate();
        $terminal->clearOutput();
        $tui->requestRender();
        $this->microTurn();

        $out = $terminal->getOutput();
        self::assertStringContainsString('bbbb', $out, 'the changed line was rewritten');
        self::assertStringNotContainsString('stable', $out, 'a default request diffs — it must not reset the writer into a full repaint');
        $tui->stop();
    }

    public function testAnIdleTuiWritesNothingAfterTheFrameSettles(): void
    {
        $terminal = new VirtualTerminal(columns: 20, rows: 2);
        $tui = $this->tuiWith($terminal);
        $tui->start();
        $this->microTurn();
        $painted = \strlen($terminal->getOutput());
        self::assertGreaterThan(0, $painted);

        // Pump real time: no busy loop may replace the torn-down ticker.
        $suspension = \Revolt\EventLoop::getSuspension();
        $timer = \Revolt\EventLoop::delay(0.2, $suspension->resume(...));
        try {
            $suspension->suspend();
        } finally {
            \Revolt\EventLoop::cancel($timer);
        }

        self::assertSame($painted, \strlen($terminal->getOutput()), 'an idle Tui emits no further bytes');
        $tui->stop();
    }

    public function testOnColumnsChangedFiresOnWidthChangesOnly(): void
    {
        $terminal = new VirtualTerminal(columns: 80, rows: 24);
        $tui = $this->tuiWith($terminal);
        $widths = [];
        $tui->onColumnsChanged(static function (int $columns) use (&$widths): void {
            $widths[] = $columns;
        });

        $tui->requestRender();
        $tui->processRender();
        self::assertSame([80], $widths, 'the first frame reports the initial width');

        $tui->requestRender();
        $tui->processRender();
        self::assertSame([80], $widths, 'an unchanged width stays quiet');

        $terminal->simulateResize(120, 30);
        $tui->requestRender();
        $tui->processRender();
        self::assertSame([80, 120], $widths, 'a resize fires with the new width');
    }
}
