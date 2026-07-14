<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;
use Porta\I18n\PaneReorder;
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
        $tui->setPaneReorder(new PaneSegmenter($this->renderer, new class implements \Porta\Overlay\FloatingRegions {
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
        $pair = stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, 0);
        \assert(false !== $pair);
        stream_set_blocking($pair[1], false);
        $terminal = new \Porta\Ui\NonBlockingTerminal(new VirtualTerminal(columns: 20, rows: 2), $pair[0]);
        $terminal->start(static function (): void {}, static function (): void {}, static function (): void {});
        $tui = $this->tuiWith($terminal);

        $terminal->write(str_repeat('#', 524288)); // a wedged tty: backlog past the socket buffer
        $backlog = $terminal->pendingBytes();
        $this->assertGreaterThan(0, $backlog);

        $tui->requestRender();
        $tui->processRender();

        $requested = new \ReflectionProperty(\Symfony\Component\Tui\Tui::class, 'renderRequested');
        $this->assertTrue((bool) $requested->getValue($tui), 'the render request stays pending while the tty is behind');
        $this->assertSame($backlog, $terminal->pendingBytes(), 'no new frame bytes were generated into the backlog');

        // Drain the peer; the writer's watcher empties the queue as the loop runs.
        $suspension = \Revolt\EventLoop::getSuspension();
        $drain = \Revolt\EventLoop::repeat(0.01, static function () use ($pair, $terminal, $suspension): void {
            fread($pair[1], 65536);
            if (0 === $terminal->pendingBytes()) {
                $suspension->resume();
            }
        });
        $suspension->suspend();
        \Revolt\EventLoop::cancel($drain);

        $tui->processRender();
        $this->assertFalse((bool) $requested->getValue($tui), 'the queued request rendered once the tty caught up');
        $this->assertGreaterThan(0, $terminal->pendingBytes() + \strlen((string) fread($pair[1], 65536)), 'the frame reached the terminal');

        $terminal->stop();
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
}
