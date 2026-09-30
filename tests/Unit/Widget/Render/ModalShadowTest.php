<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget\Render;

use PHPUnit\Framework\TestCase;
use Porta\I18n\BaseDirection;
use Porta\Ui\Theme\Styles;
use Porta\Widget\Render\ModalShadow;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\WidgetRect;

/**
 * The drop shadow's +1/+1 cast: a right band beside every row but the title
 * bar, a bottom band one column in, both clipped at the pane's edges, and
 * solid — the cells overwrite what sits beneath.
 */
final class ModalShadowTest extends TestCase
{
    /** @return list<string> a 10×20 pane of dots */
    private function pane(): array
    {
        return \array_fill(start_index: 0, count: 10, value: \str_repeat('.', times: 20));
    }

    public function testBlitCastsTheOffsetBands(): void
    {
        $lines = \array_map(AnsiUtils::stripAnsiCodes(...), ModalShadow::blit($this->pane(), 2, 4, 6, 3, 20, Styles::dark()));

        // Right band: col 10 (= 4 + 6) on rows 3 and 4 only.
        $this->assertSame('.', $lines[2][10], 'no shadow beside the title bar');
        $this->assertSame(' ', $lines[3][10]);
        $this->assertSame(' ', $lines[4][10]);
        // Bottom band: row 5 (= 2 + 3), cols 5..10 — one column in, its last
        // cell under the right band's column (the classic +1/+1 corner).
        $this->assertSame('.'.\str_repeat(' ', times: 6).\str_repeat('.', times: 9), \substr($lines[5], offset: 4), 'the bottom band sits one column in');
        // Every composited row keeps the pane width.
        foreach ($lines as $i => $line) {
            $this->assertSame(20, AnsiUtils::visibleWidth(ModalShadow::blit($this->pane(), 2, 4, 6, 3, 20, Styles::dark())[$i]), "row {$i}");
        }
    }

    public function testBlitClipsAtThePaneEdges(): void
    {
        // Box flush against the right and bottom edges: nothing to cast.
        $lines = \array_map(AnsiUtils::stripAnsiCodes(...), ModalShadow::blit($this->pane(), 7, 14, 6, 3, 20, Styles::dark()));

        $this->assertSame(\array_fill(start_index: 0, count: 10, value: \str_repeat('.', times: 20)), $lines, 'no band painted outside the pane');
    }

    /**
     * sliceByColumn re-emits an APC caret marker at a tail slice's start, so
     * a blit over a caret row must cap the markers back to the original count
     * — the hardware cursor cannot be in two places.
     */
    public function testBlitNeverMultipliesTheCaretMarker(): void
    {
        $marker = AnsiUtils::cursorMarker();
        $pane = $this->pane();
        $pane[3] = 'ab'.$marker.\str_repeat('.', times: 18); // a caret on a right-band row

        $lines = ModalShadow::blit($pane, 2, 4, 6, 3, 20, Styles::dark());

        $this->assertSame(1, \substr_count($lines[3], AnsiUtils::CURSOR_MARKER_PREFIX), 'exactly the original caret survives');
        $this->assertSame(20, AnsiUtils::visibleWidth($lines[3]));
    }

    public function testSpansMirrorTheBlitGeometry(): void
    {
        $rect = new WidgetRect(row: 0, col: 0, rows: 10, columns: 20);

        $spans = ModalShadow::spans($rect, 2, 4, 6, 3, Styles::dark());

        $byRow = [];
        foreach ($spans as $span) {
            $byRow[] = [$span['row'], $span['col'], $span['width']];
        }
        $this->assertSame([[3, 10, 1], [4, 10, 1], [5, 5, 6]], $byRow, 'right band rows 3-4, bottom band at row 5 one column in');
    }

    /**
     * A mirrored surface is flipped across the row after it is composed, so
     * it casts to the LEFT: the flip then lands the shadow bottom-right.
     */
    public function testAMirroredSurfaceCastsToTheLeftBeforeTheFlip(): void
    {
        $rect = new WidgetRect(row: 0, col: 0, rows: 10, columns: 20);

        $spans = ModalShadow::spans($rect, 2, 4, 6, 3, Styles::dark(), BaseDirection::Rtl);

        $byRow = \array_map(static fn (array $span): array => [$span['row'], $span['col'], $span['width']], $spans);
        $this->assertSame([[3, 3, 1], [4, 3, 1], [5, 3, 6]], $byRow, 'left band rows 3-4, bottom band at row 5 from the left band');
    }

    public function testSpansClipToThePane(): void
    {
        $rect = new WidgetRect(row: 0, col: 0, rows: 5, columns: 10);

        // Box at (2, 4), 6 wide: the right band's column (10) is outside the
        // pane, the bottom band (row 5) is below it.
        $this->assertSame([], ModalShadow::spans($rect, 2, 4, 6, 3, Styles::dark()));
    }

    /**
     * A box near the right edge (but not flush against it) makes the bottom
     * band's width bounded by the REMAINING columns, not by the box width —
     * the only arrangement where the `columns - col - 1` term, not `$width`,
     * actually drives min()'s result.
     */
    public function testBottomBandWidthIsClippedByTheRemainingColumnsNotTheBoxWidth(): void
    {
        // col=15, width=6, columns=20: remaining columns past col+1 is 4, well
        // under the box's own 6 — the band must stop at 4, not 6.
        $lines = \array_map(AnsiUtils::stripAnsiCodes(...), ModalShadow::blit($this->pane(), 2, 15, 6, 3, 20, Styles::dark()));

        $this->assertSame(\str_repeat('.', times: 16).\str_repeat(' ', times: 4), $lines[5], 'the band stops 4 columns in, not 6');
    }

    /** A box flush against the right edge leaves no room at all: no band, no composited row at all. */
    public function testNoRemainingColumnsPaintsNoBottomBandRow(): void
    {
        $pane = $this->pane();

        $lines = ModalShadow::blit($pane, 2, 19, 1, 3, 20, Styles::dark());

        $this->assertSame($pane[5], $lines[5], 'row is untouched byte-for-byte — not even a zero-width composite');
    }
}
