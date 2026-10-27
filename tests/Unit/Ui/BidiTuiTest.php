<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;
use Porta\I18n\Bidi;
use Porta\I18n\PaneReorder;
use Porta\Overlay\Region\DropdownRegion;
use Porta\Overlay\Region\FloatingRegions;
use Porta\Overlay\State\FloatingLayer;
use Porta\Support\PerfCollector;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\PumpsLoop;
use Porta\Tests\Support\TempProject;
use Porta\Ui\BidiTui;
use Porta\Ui\Graphics\KittyPlaceholders;
use Porta\Ui\NonBlockingTerminal;
use Porta\Ui\PaneSegmenter;
use Revolt\EventLoop;
use Symfony\Component\Clock\MockClock;
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
    use PumpsLoop;

    public const string SHALOM = "\u{05E9}\u{05DC}\u{05D5}\u{05DD}";
    private const string SHALOM_VISUAL = "\u{05DD}\u{05D5}\u{05DC}\u{05E9}";

    /** Stands in for a mirrored row, so reuse is visible on the screen. */
    private const string MARKER = 'REMEMBERED';

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
        $tui->setPaneReorder(new PaneSegmenter($this->renderer, new class implements FloatingRegions {
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

        $this->assertStringContainsString(self::SHALOM, $terminal->getOutput());
    }

    public function testImageStandInsReachTheTerminalAsRealPlaceholders(): void
    {
        $terminal = new VirtualTerminal(columns: 20, rows: 2);
        $this->renderer = new Renderer(new StyleSheet());
        $tui = new BidiTui(terminal: $terminal, renderer: $this->renderer);
        $tui->add(new class extends AbstractWidget {
            public function render(RenderContext $context): array
            {
                return [KittyPlaceholders::row(1000, 0, 0, 3)];
            }
        });
        $tui->requestRender();
        $tui->processRender();

        $this->assertSame(3, \preg_match_all('/\x{10EEEE}\p{Mn}\p{Mn}/u', $terminal->getOutput()));
        $this->assertDoesNotMatchRegularExpression('/[\x{100000}-\x{10DF20}]/u', $terminal->getOutput(), 'no stand-in is left');
    }

    public function testATabReachesTheTerminalAsTheSpacesItWasMeasuredAs(): void
    {
        // The vendor counts a tab as TAB_WIDTH columns; a raw tab jumps to the
        // terminal's own stop instead, so `end` was drawn past column 20.
        $terminal = new VirtualTerminal(columns: 20, rows: 2);
        $this->renderer = new Renderer(new StyleSheet());
        $tui = new BidiTui(terminal: $terminal, renderer: $this->renderer);
        $tui->add(new class extends AbstractWidget {
            public function render(RenderContext $context): array
            {
                return ["ab\tcd\x1b[1m\tend\x1b[0m"];
            }
        });
        $tui->requestRender();
        $tui->processRender();

        $this->assertStringNotContainsString("\t", $terminal->getOutput());
        $this->assertStringContainsString('ab   cd', $terminal->getOutput());
        $this->assertStringContainsString('   end', $terminal->getOutput());
    }

    public function testARtlReorderRewritesEachLineToVisualOrder(): void
    {
        $terminal = new VirtualTerminal(columns: 20, rows: 2);
        $tui = $this->tuiWith($terminal);
        $this->enableRtl($tui);
        $tui->requestRender();
        $tui->processRender();

        $out = $terminal->getOutput();
        $this->assertStringContainsString(self::SHALOM_VISUAL, $out, 'the Hebrew word is reversed to visual order');
        $this->assertStringNotContainsString(self::SHALOM, $out, 'the logical-order form is not written');
    }

    /**
     * Render one mirrored frame, then replace what row 0's mirror produced with
     * a marker, leaving the inputs that produced it alone.
     *
     * Whether the next frame shows the marker is the whole question: shown, the
     * remembered row was reused; recomputed, it was not.
     *
     * @param array{in?: string, runs?: list<array{start: int, width: int, ltr: bool}>, regions?: list<array{start: int, width: int, ltr: bool}>} $perturb
     */
    private function markRememberedRow(BidiTui $tui, array $perturb = []): void
    {
        $tui->requestRender();
        $tui->processRender();
        $arranged = Probe::get($tui, 'arranged');
        $this->assertIsArray($arranged);
        $this->assertArrayHasKey(0, $arranged, 'the first frame remembered what it mirrored');
        $this->assertIsArray($arranged[0]);
        Probe::set($tui, 'arranged', [0 => [...$arranged[0], ...$perturb, 'out' => self::MARKER]]);
    }

    public function testAFrameTallerThanTheTerminalIsClippedToIt(): void
    {
        // The vendor renderer does NOT clip an over-tall widget: asked for two
        // rows it hands back four. The real workbench does exactly this at small
        // sizes — menu bar, main and status bar are three fixed rows, so a
        // one-row terminal was sent three of them and a three-row terminal four.
        // Those extra rows SCROLL the alternate screen, and every differential
        // repaint after that addresses rows one out with relative cursor moves,
        // in a session that emits no `CSI H` or `CSI 2J` ever again.
        $terminal = new VirtualTerminal(columns: 20, rows: 2);
        $this->renderer = new Renderer(new StyleSheet());
        $tui = new BidiTui(terminal: $terminal, renderer: $this->renderer);
        $tui->add(new class extends AbstractWidget {
            public function render(RenderContext $context): array
            {
                return ['x', 'y', BidiTuiTest::SHALOM, 'tail'];
            }
        });
        $this->enableRtl($tui);
        $tui->requestRender();
        $tui->processRender();

        $out = $terminal->getOutput();
        $this->assertStringContainsString('x', $out, 'the rows the terminal has are written');
        $this->assertStringNotContainsString(self::SHALOM, $out, 'and the rows it does not have are not');
        $this->assertStringNotContainsString('tail', $out);
        $arranged = Probe::get($tui, 'arranged');
        $this->assertIsArray($arranged);
        $this->assertSame([0, 1], \array_keys($arranged), 'only the rows that exist were mirrored at all');
    }

    public function testAnUnchangedRowIsMirroredOnceAndReused(): void
    {
        // Mirroring is what a right-to-left frame spends its time on: measured
        // through the real pipeline at 226x67, 2.9ms a frame against 0.17ms of
        // widget tree and 0.10ms of terminal write. A repaint changes a row or
        // two, which is the observation the differential writer downstream is
        // already built on.
        $terminal = new VirtualTerminal(columns: 20, rows: 2);
        $tui = $this->tuiWith($terminal);
        $this->enableRtl($tui);
        $this->markRememberedRow($tui);

        $tui->requestRender();
        $tui->processRender();

        $this->assertStringContainsString(self::MARKER, $terminal->getOutput(), 'the unchanged row was not mirrored a second time');
    }

    public function testARowWhoseTextChangedIsMirroredAgain(): void
    {
        $terminal = new VirtualTerminal(columns: 20, rows: 2);
        $tui = $this->tuiWith($terminal);
        $this->enableRtl($tui);
        $this->markRememberedRow($tui, ['in' => 'something else entirely']);

        $tui->requestRender();
        $tui->processRender();

        $out = $terminal->getOutput();
        $this->assertStringNotContainsString(self::MARKER, $out, 'a row whose text moved on is stale, not reusable');
        $this->assertStringContainsString(self::SHALOM_VISUAL, $out, 'it was mirrored again');
    }

    public function testARowWhosePanesChangedIsMirroredAgain(): void
    {
        // The same text under a different pane layout mirrors to something else:
        // a run reorders within its own span, so the span is an input.
        $terminal = new VirtualTerminal(columns: 20, rows: 2);
        $tui = $this->tuiWith($terminal);
        $this->enableRtl($tui);
        $this->markRememberedRow($tui, ['runs' => [['start' => 0, 'width' => 4, 'ltr' => true]]]);

        $tui->requestRender();
        $tui->processRender();

        $out = $terminal->getOutput();
        $this->assertStringNotContainsString(self::MARKER, $out, 'a resized pane invalidates the row');
        $this->assertStringContainsString(self::SHALOM_VISUAL, $out);
    }

    public function testARowWhoseFloatingBoxesChangedIsMirroredAgain(): void
    {
        // A dropdown opening over a row it does not change the text of: the box
        // is stamped over the mirrored panes, so it is an input too.
        $terminal = new VirtualTerminal(columns: 20, rows: 2);
        $tui = $this->tuiWith($terminal);
        $this->enableRtl($tui);
        $this->markRememberedRow($tui, ['regions' => [['start' => 2, 'width' => 3, 'ltr' => true]]]);

        $tui->requestRender();
        $tui->processRender();

        $out = $terminal->getOutput();
        $this->assertStringNotContainsString(self::MARKER, $out, 'a box appearing over the row invalidates it');
        $this->assertStringContainsString(self::SHALOM_VISUAL, $out);
    }

    public function testARowWhoseFloatingLayerChangedIsMirroredAgain(): void
    {
        // The same box geometry over the same text, with different contents: a
        // menu's selection moving. What the regions are stamped from is an input.
        $terminal = new VirtualTerminal(columns: 20, rows: 2);
        $tui = $this->tuiWith($terminal);
        $this->enableRtl($tui);
        $this->markRememberedRow($tui, ['surfaces' => 'something else']);

        $tui->requestRender();
        $tui->processRender();

        $this->assertStringNotContainsString(self::MARKER, $terminal->getOutput(), 'new box contents invalidate the row');
    }

    /**
     * A click inside a mirrored box of Hebrew maps through the box's own
     * contents, which are on the floating layer now, not in the pane's row.
     */
    public function testAClickInAMirroredBoxMapsThroughTheFloatingLayer(): void
    {
        $terminal = new VirtualTerminal(columns: 10, rows: 1);
        $this->renderer = new Renderer(new StyleSheet());
        $tui = new BidiTui(terminal: $terminal, renderer: $this->renderer);
        $tui->add(new class extends AbstractWidget {
            public function render(RenderContext $context): array
            {
                return [\str_repeat('x', times: 10)];
            }
        });
        $layer = new FloatingLayer();
        $layer->setMenu([['row' => 0, 'col' => 0, 'width' => 4, 'segment' => self::SHALOM]]);
        $tui->setPaneReorder(
            new PaneSegmenter(
                $this->renderer,
                new class implements FloatingRegions {
                    public function regions(): array
                    {
                        return [new DropdownRegion(0, 0, 4, 1)];
                    }
                },
                $layer,
            ),
            new PaneReorder(),
        );
        $tui->requestRender();
        $tui->processRender();

        // The box is stamped at 6..9 with its Hebrew reversed inside it, so its
        // leftmost screen cell is the LAST logical one.
        $this->assertSame(3, $tui->logicalColumn(0, 6));
        $this->assertSame(0, $tui->logicalColumn(0, 9));
    }

    public function testTheRememberedFrameHoldsOnlyTheRowsTheLastFrameHad(): void
    {
        // Rebuilt whole rather than written through: a terminal that shrinks
        // must not leave rows behind to be served to a later, taller frame.
        $terminal = new VirtualTerminal(columns: 20, rows: 2);
        $tui = $this->tuiWith($terminal);
        $this->enableRtl($tui);
        $tui->requestRender();
        $tui->processRender();
        $arranged = Probe::get($tui, 'arranged');
        $this->assertIsArray($arranged);
        Probe::set($tui, 'arranged', [...$arranged, 99 => $arranged[0]]);

        $tui->requestRender();
        $tui->processRender();

        $after = Probe::get($tui, 'arranged');
        $this->assertIsArray($after);
        // One row: this rig's widget renders a single line, and the frame is
        // exactly as tall as what the tree produced.
        $this->assertSame([0], \array_keys($after), 'only the rows this frame had');
    }

    public function testTheTerminalBidiSwitchForgetsTheMirroredRows(): void
    {
        // The freeze is part of what each remembered row carries, so answering
        // this question differently makes every one of them wrong.
        $terminal = new VirtualTerminal(columns: 20, rows: 2);
        $tui = $this->tuiWith($terminal);
        $this->enableRtl($tui);
        $tui->requestRender();
        $tui->processRender();
        $this->assertNotSame([], Probe::get($tui, 'arranged'));

        $tui->setTerminalBidi(true);

        $this->assertSame([], Probe::get($tui, 'arranged'), 'the frozen and unfrozen forms are not interchangeable');
    }

    public function testInstallingAReorderForgetsTheMirroredRows(): void
    {
        $terminal = new VirtualTerminal(columns: 20, rows: 2);
        $tui = $this->tuiWith($terminal);
        $this->enableRtl($tui);
        $tui->requestRender();
        $tui->processRender();
        $this->assertNotSame([], Probe::get($tui, 'arranged'));

        $this->enableRtl($tui); // a live locale switch replaces the reorder

        $this->assertSame([], Probe::get($tui, 'arranged'), 'rows the previous reorder mirrored are not this one\'s work');
    }

    public function testAFrameWithNothingPendingIsANoOp(): void
    {
        $terminal = new VirtualTerminal(columns: 20, rows: 2);
        $tui = $this->tuiWith($terminal);
        $this->enableRtl($tui);
        // No requestRender(): the reorder branch must early-return without writing.
        $tui->processRender();

        $this->assertSame('', $terminal->getOutput());
    }

    public function testABackloggedTerminalSkipsFrameGenerationUntilDrained(): void
    {
        // The scroll-lag regression: frame generation must self-pace to the
        // terminal's drain rate instead of queueing stale frames.
        $pair = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, protocol: 0);
        \assert(false !== $pair, description: 'stream_socket_pair created a socket pair');
        \stream_set_blocking($pair[1], enable: false);
        $terminal = new NonBlockingTerminal(new VirtualTerminal(columns: 20, rows: 2), $pair[0]);
        $terminal->start(static function (): void {}, static function (): void {}, static function (): void {});
        $tui = $this->tuiWith($terminal);

        $perfProject = new TempProject(); // held: its destructor removes the dir
        $perf = new PerfCollector($perfProject->dir, new MockClock());
        $tui->setPerfSink($perf);

        $terminal->write(\str_repeat('#', times: 524_288)); // a wedged tty: backlog past the socket buffer
        $backlog = $terminal->pendingBytes();
        $this->assertGreaterThan(0, $backlog);

        $tui->requestRender();
        $tui->processRender();

        $this->assertSame(1, Probe::get($perf, 'paceSkips'), 'the skipped generation was counted');
        $this->assertTrue((bool) Probe::get($tui, 'renderRequested'), 'the render request stays pending while the tty is behind');
        $this->assertSame($backlog, $terminal->pendingBytes(), 'no new frame bytes were generated into the backlog');

        // Drain the peer; the writer's watcher empties the queue as the loop runs.
        $suspension = EventLoop::getSuspension();
        $drain = EventLoop::repeat(0.01, static function () use ($pair, $terminal, $suspension): void {
            \fread($pair[1], length: 65_536);
            if (0 === $terminal->pendingBytes()) {
                $suspension->resume();
            }
        });
        $suspension->suspend();
        EventLoop::cancel($drain);

        $tui->processRender();
        $this->assertFalse((bool) Probe::get($tui, 'renderRequested'), 'the queued request rendered once the tty caught up');
        $this->assertGreaterThan(0, $terminal->pendingBytes() + \strlen((string) \fread($pair[1], length: 65_536)), 'the frame reached the terminal');

        $terminal->stop();
        \fclose($pair[1]);
    }

    public function testADrainedBacklogRepaintsWithoutAManualPump(): void
    {
        $pair = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, protocol: 0);
        \assert(false !== $pair, description: 'stream_socket_pair created a socket pair');
        \stream_set_blocking($pair[1], enable: false);
        $terminal = new NonBlockingTerminal(new VirtualTerminal(columns: 20, rows: 2), $pair[0]);
        $tui = $this->tuiWith($terminal);
        $tui->start();
        $this->microTurn(); // first frame out into the socket
        \fread($pair[1], length: 65_536);

        $terminal->write(\str_repeat('#', times: 524_288)); // a wedged tty
        $tui->requestRender();
        $this->microTurn(); // the deferred tick skips generation and arms the wakeup

        $this->assertTrue((bool) Probe::get($tui, 'renderRequested'), 'the request stays pending while the tty is behind');
        $this->assertTrue((bool) Probe::get($tui, 'drainWakeupArmed'));
        $this->assertNotNull(Probe::get(Probe::object($terminal, 'writer'), 'onDrain'), 'the skip installed the drain hook on the live writer');

        // Drain the peer using defer-only turns: the 10ms ticker cannot fire
        // inside them, so ONLY the drain wakeup can paint the skipped frame.
        for ($i = 0; $i < 500 && 0 !== $terminal->pendingBytes(); ++$i) {
            \fread($pair[1], length: 65_536);
            $this->microTurn();
        }
        $this->assertSame(0, $terminal->pendingBytes(), 'the backlog drained');
        $this->microTurn(); // run the wakeup's deferred tick
        $this->microTurn();

        $this->assertFalse((bool) Probe::get($tui, 'renderRequested'), 'the skipped frame painted at drain time, not on the ticker');

        // Empty the socket buffer first: stop()'s teardown escapes flush
        // BLOCKING, and a full buffer with no reader would park the worker.
        //
        // The body is empty because the WORK IS THE CONDITION — fread() drains,
        // and the loop ends when there is nothing left. `mago lint --fix`
        // deleted this once as an empty loop and turned the suite into a
        // deadlock at 99%, so the rule is suppressed here rather than left to
        // be rediscovered.
        // @mago-expect lint:no-empty-loop -- the fread in the condition is the body
        // @noinspection MissingOrEmptyGroupStatementInspection -- as above: the fread in the condition is the body
        while ('' !== (string) \fread($pair[1], length: 65_536)) {
        }
        $tui->stop();
        \fclose($pair[1]);
    }

    public function testClearingTheReorderRestoresTheStockPath(): void
    {
        $terminal = new VirtualTerminal(columns: 20, rows: 2);
        $tui = $this->tuiWith($terminal);
        $this->enableRtl($tui);
        $tui->setPaneReorder(null, null);
        $tui->requestRender();
        $tui->processRender();

        $this->assertStringContainsString(self::SHALOM, $terminal->getOutput());
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
        $this->assertSame(3, $tui->logicalColumn(0, 0));
        $this->assertSame(0, $tui->logicalColumn(0, 3));
    }

    /** A row's columns are mapped once a frame — and again for the next frame's row. */
    public function testAClickMapsThroughTheFrameNowOnScreen(): void
    {
        $content = new \ArrayObject(['line' => self::SHALOM]);
        $widget = new class($content) extends AbstractWidget {
            /** @param \ArrayObject<string, string> $content */
            public function __construct(
                private readonly \ArrayObject $content,
            ) {}

            public function render(RenderContext $context): array
            {
                return [$this->content['line']];
            }
        };
        $this->renderer = new Renderer(new StyleSheet());
        $tui = new BidiTui(terminal: new VirtualTerminal(columns: 20, rows: 2), renderer: $this->renderer);
        $tui->add($widget);
        $this->enableRtl($tui);
        $tui->requestRender();
        $tui->processRender();
        $this->assertSame(3, $tui->logicalColumn(0, 0), 'the premise: the first frame, mapped');

        $content['line'] = 'ab'.self::SHALOM;
        $widget->invalidate();
        $tui->requestRender();
        $tui->processRender();

        $this->assertSame(5, $tui->logicalColumn(0, 0), "the second frame's row: its last letter now sits leftmost");
    }

    /**
     * A floating box anchors where what it hangs from was DRAWN, mirrored
     * back across the row — the box is its own run, reordered as a whole.
     */
    public function testAFloatingBoxAnchorsWhereItsTargetWasDrawn(): void
    {
        $terminal = new VirtualTerminal(columns: 20, rows: 2);
        $tui = $this->tuiWith($terminal);
        $this->assertSame(5, $tui->floatingColumn(0, 5, 1, 4), 'no reorder: the logical column is the screen column');

        $this->enableRtl($tui);
        $tui->requestRender();
        $tui->processRender();

        // Logical column 0 (the word's first letter) is drawn at screen 3; a
        // 4-wide box mirrored across 20 columns starts there when anchored at 13.
        $this->assertSame(13, $tui->floatingColumn(0, 0, 1, 4));
        $this->assertSame(16, $tui->floatingColumn(0, 0, 4, 4), 'a span meets its leftmost drawn column: the whole word starts at screen 0');
        $this->assertSame(50, $tui->floatingColumn(0, 50, 1, 4), 'drawn nowhere on screen: the anchor stands');
    }

    public function testLogicalColumnIsIdentityWithoutAReorder(): void
    {
        $terminal = new VirtualTerminal(columns: 20, rows: 2);
        $tui = $this->tuiWith($terminal);
        $tui->requestRender();
        $tui->processRender();

        $this->assertSame(5, $tui->logicalColumn(0, 5)); // no reorder → identity
    }

    public function testLogicalColumnIsIdentityForAnUnknownRow(): void
    {
        $terminal = new VirtualTerminal(columns: 20, rows: 2);
        $tui = $this->tuiWith($terminal);
        $this->enableRtl($tui);
        $tui->requestRender();
        $tui->processRender();

        $this->assertSame(7, $tui->logicalColumn(99, 7)); // row not in the last frame
    }

    /**
     * One loop turn that runs ONLY already-queued defers — no timers. Proves
     * same-iteration behavior: the adaptive ticker's 10ms repeat cannot have
     * fired inside a defer-only turn.
     */
    private function microTurn(): void
    {
        $suspension = EventLoop::getSuspension();
        EventLoop::defer($suspension->resume(...));
        $suspension->suspend();
    }

    public function testARequestedFramePaintsInTheSameLoopIterationWithoutTheTicker(): void
    {
        $terminal = new VirtualTerminal(columns: 20, rows: 2);
        $tui = $this->tuiWith($terminal);
        $tui->start(); // start() itself requests the first render
        $tui->requestRender(); // a same-burst duplicate: with NO sink set, counting must be a no-op

        $this->microTurn();

        $this->assertStringContainsString(self::SHALOM, $terminal->getOutput(), 'the frame painted before any 10ms ticker interval elapsed');
        $tui->stop();
    }

    public function testARequestBurstTicksExactlyOnce(): void
    {
        $terminal = new VirtualTerminal(columns: 20, rows: 2);
        $tui = $this->tuiWith($terminal);
        $perfProject = new TempProject(); // held: its destructor removes the dir
        $perf = new PerfCollector($perfProject->dir, new MockClock());
        $tui->setPerfSink($perf);
        $ticks = 0;
        $tui->onTick(static function () use (&$ticks): bool {
            ++$ticks;

            return false;
        });
        $tui->start();

        $tui->requestRender();
        $tui->requestRender();
        $this->assertTrue((bool) Probe::get($tui, 'immediateTickQueued'), 'a burst holds one queued tick');
        $this->microTurn();

        $this->assertSame(1, $ticks, 'N requests in one iteration coalesce into one tick');
        $this->assertGreaterThanOrEqual(1, Probe::get($perf, 'coalesced'), 'the deduped request was counted');
        $this->assertFalse((bool) Probe::get($tui, 'immediateTickQueued'), 'the queue flag cleared after the tick');
        $tui->stop();
    }

    public function testARequestOnANeverStartedTuiQueuesNoTick(): void
    {
        $terminal = new VirtualTerminal(columns: 20, rows: 2);
        $tui = $this->tuiWith($terminal);

        $tui->requestRender();

        $this->assertFalse((bool) Probe::get($tui, 'immediateTickQueued'), 'no tick is queued while the Tui is not running');
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

        $this->assertSame($baseline, $terminal->getOutput(), 'the queued tick did not paint into the stopped terminal');
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
        $this->assertStringContainsString('stable', $terminal->getOutput());

        $widget->second = 'bbbb';
        $widget->invalidate();
        $terminal->clearOutput();
        $tui->requestRender();
        $this->microTurn();

        $out = $terminal->getOutput();
        $this->assertStringContainsString('bbbb', $out, 'the changed line was rewritten');
        $this->assertStringNotContainsString('stable', $out, 'a default request diffs — it must not reset the writer into a full repaint');
        $tui->stop();
    }

    public function testAnIdleTuiWritesNothingAfterTheFrameSettles(): void
    {
        $terminal = new VirtualTerminal(columns: 20, rows: 2);
        $tui = $this->tuiWith($terminal);
        $tui->start();
        $this->microTurn();
        $painted = \strlen($terminal->getOutput());
        $this->assertGreaterThan(0, $painted);

        // Pump real time: no busy loop may replace the torn-down ticker.
        $suspension = EventLoop::getSuspension();
        $timer = EventLoop::delay(0.2, $suspension->resume(...));
        try {
            $suspension->suspend();
        } finally {
            EventLoop::cancel($timer);
        }

        $this->assertSame($painted, \strlen($terminal->getOutput()), 'an idle Tui emits no further bytes');
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
        $this->assertSame([80], $widths, 'the first frame reports the initial width');

        $tui->requestRender();
        $tui->processRender();
        $this->assertSame([80], $widths, 'an unchanged width stays quiet');

        $terminal->simulateResize(120, 30);
        $tui->requestRender();
        $tui->processRender();
        $this->assertSame([80, 120], $widths, 'a resize fires with the new width');
    }

    public function testTheFrameSinkSeesTheFinalMirroredLines(): void
    {
        $terminal = new VirtualTerminal(columns: 20, rows: 2);
        $tui = $this->tuiWith($terminal);
        $this->enableRtl($tui);
        $seen = [];
        $tui->setFrameSink(static function (array $lines) use (&$seen): void {
            $seen = $lines;
        });
        $tui->requestRender();
        $tui->processRender();

        $this->assertNotSame([], $seen, 'the sink was handed the frame');
        $this->assertStringContainsString(self::SHALOM_VISUAL, \implode('', $seen), 'and it is the mirrored form, as written');
    }

    /**
     * The join sees the frame as painted — mirrored already, so a tee it
     * draws is never mirrored again — and what it returns is what the sink
     * records and the terminal gets.
     */
    public function testTheFrameJoinRewritesTheMirroredFrameBeforeTheSink(): void
    {
        $terminal = new VirtualTerminal(columns: 20, rows: 2);
        $tui = $this->tuiWith($terminal);
        $this->enableRtl($tui);
        $joined = [];
        $tui->setFrameJoin(static function (array $lines) use (&$joined): array {
            $joined = $lines;

            return \array_map(static fn (string $line): string => \str_replace(self::SHALOM_VISUAL, replace: '┤', subject: $line), $lines);
        });
        $seen = [];
        $tui->setFrameSink(static function (array $lines) use (&$seen): void {
            $seen = $lines;
        });
        $tui->requestRender();
        $tui->processRender();

        $this->assertStringContainsString(self::SHALOM_VISUAL, \implode('', $joined), 'handed the mirrored frame');
        $this->assertStringContainsString('┤', \implode('', $seen), 'and its answer is the frame');
        $this->assertStringContainsString('┤', $terminal->getOutput(), 'written as it answered');

        $tui->setFrameJoin(null);
        $tui->requestRender(force: true);
        $tui->processRender();
        $this->assertStringContainsString(self::SHALOM_VISUAL, \implode('', $seen), 'cleared, the frame is left as painted');
    }

    public function testClearingTheFrameSinkStopsIt(): void
    {
        $terminal = new VirtualTerminal(columns: 20, rows: 2);
        $tui = $this->tuiWith($terminal);
        $calls = 0;
        $tui->setFrameSink(static function (array $lines) use (&$calls): void {
            ++$calls;
        });
        $tui->setFrameSink(null);
        $tui->requestRender();
        $tui->processRender();

        $this->assertSame(0, $calls);
    }

    public function testTerminalBidiModeFreezesTheMirroredRow(): void
    {
        // A terminal that runs UAX#9 itself would reorder a row Porta has
        // already ordered. Porta keeps the layout either way and adds a
        // left-to-right override, which makes every character inside strong L
        // so nothing reverses and nothing reorders.
        //
        // Its own widget, with leading padding: the freeze SPENDS the row's
        // first cell rather than growing the row, and the shared fixture is
        // deliberately unpadded -- the one shape that cannot be frozen.
        $terminal = new VirtualTerminal(columns: 20, rows: 2);
        $renderer = new Renderer(new StyleSheet());
        $this->renderer = $renderer;
        $tui = new BidiTui(terminal: $terminal, renderer: $renderer);
        $tui->add(new class extends AbstractWidget {
            public function render(RenderContext $context): array
            {
                // TRAILING padding: the freeze runs after the mirror, which
                // moves a row's trailing padding to its front.
                return [BidiTuiTest::SHALOM.'  '];
            }
        });
        $this->enableRtl($tui);
        $tui->setTerminalBidi(true);
        $tui->requestRender();
        $tui->processRender();

        $out = $terminal->getOutput();
        $this->assertStringContainsString(self::SHALOM_VISUAL, $out, 'Porta still laid the row out');
        $this->assertStringContainsString(Bidi::LTR_OVERRIDE, $out, 'and told the terminal not to');
    }
}
