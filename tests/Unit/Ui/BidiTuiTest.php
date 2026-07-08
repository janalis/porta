<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;
use Porta\I18n\Bidi;
use Porta\Ui\BidiTui;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Style\StyleSheet;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * The bidi-aware Tui: with a reorderer installed it maps every rendered line to
 * visual order before it reaches the screen; without one it defers to the stock
 * render path, and a frame with nothing pending is a no-op.
 */
final class BidiTuiTest extends TestCase
{
    private const string SHALOM = "\u{05E9}\u{05DC}\u{05D5}\u{05DD}";
    private const string SHALOM_VISUAL = "\u{05DD}\u{05D5}\u{05DC}\u{05E9}";

    private function tuiWith(VirtualTerminal $terminal): BidiTui
    {
        $tui = new BidiTui(terminal: $terminal, renderer: new Renderer(new StyleSheet()));
        $tui->add(new class extends AbstractWidget {
            public function render(RenderContext $context): array
            {
                return [BidiTuiTest::wordLine()];
            }
        });

        return $tui;
    }

    public static function wordLine(): string
    {
        return self::SHALOM;
    }

    public function testWithoutAReordererTheStockLineIsWritten(): void
    {
        $terminal = new VirtualTerminal(columns: 20, rows: 2);
        $tui = $this->tuiWith($terminal);
        $tui->requestRender();
        $tui->processRender();

        self::assertStringContainsString(self::SHALOM, $terminal->getOutput());
    }

    public function testARtlReordererRewritesEachLineToVisualOrder(): void
    {
        $terminal = new VirtualTerminal(columns: 20, rows: 2);
        $tui = $this->tuiWith($terminal);
        $tui->setLineReorderer(static fn (string $line): string => Bidi::reorder($line, true));
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
        $tui->setLineReorderer(static fn (string $line): string => Bidi::reorder($line, true));
        // No requestRender(): the reorder branch must early-return without writing.
        $tui->processRender();

        self::assertSame('', $terminal->getOutput());
    }

    public function testClearingTheReordererRestoresTheStockPath(): void
    {
        $terminal = new VirtualTerminal(columns: 20, rows: 2);
        $tui = $this->tuiWith($terminal);
        $tui->setLineReorderer(static fn (string $line): string => Bidi::reorder($line, true));
        $tui->setLineReorderer(null);
        $tui->requestRender();
        $tui->processRender();

        self::assertStringContainsString(self::SHALOM, $terminal->getOutput());
    }

    public function testLogicalColumnMapsClicksBackThroughTheReorder(): void
    {
        $terminal = new VirtualTerminal(columns: 20, rows: 2);
        $tui = $this->tuiWith($terminal);
        $tui->setLineReorderer(static fn (string $line): string => Bidi::reorder($line, true));
        $tui->requestRender();
        $tui->processRender();

        // Row 0 renders שלום (reversed on screen); the leftmost screen column
        // maps back to the last logical character.
        self::assertSame(3, $tui->logicalColumn(0, 0));
        self::assertSame(0, $tui->logicalColumn(0, 3));
    }

    public function testLogicalColumnIsIdentityWithoutAReorderer(): void
    {
        $terminal = new VirtualTerminal(columns: 20, rows: 2);
        $tui = $this->tuiWith($terminal);
        $tui->requestRender();
        $tui->processRender();

        self::assertSame(5, $tui->logicalColumn(0, 5)); // no reorderer → identity
    }

    public function testLogicalColumnIsIdentityForAnUnknownRow(): void
    {
        $terminal = new VirtualTerminal(columns: 20, rows: 2);
        $tui = $this->tuiWith($terminal);
        $tui->setLineReorderer(static fn (string $line): string => Bidi::reorder($line, true));
        $tui->requestRender();
        $tui->processRender();

        self::assertSame(7, $tui->logicalColumn(99, 7)); // row not in the last frame
    }
}
