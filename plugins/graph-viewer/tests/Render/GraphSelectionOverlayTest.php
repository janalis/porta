<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Render;

use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Graph\GraphLayout;
use Porta\GraphViewer\Graph\GraphNode;
use Porta\GraphViewer\Render\GraphCellGrid;
use Porta\GraphViewer\Render\GraphSelectionOverlay;
use Porta\ImageViewer\ZoomPanModel;
use Porta\Ui\Glyphs;
use Porta\Ui\Theme\Styles;
use Symfony\Component\Tui\Ansi\AnsiUtils;

final class GraphSelectionOverlayTest extends TestCase
{
    /**
     * Today the overlay frames half-block raster cells, which are one column
     * each — but it takes any rendered lines, and its splice is the same
     * cut-and-compose every other overlay does. Under double-width text the
     * old splice slid the frame: a rail at an odd column cut the 中 under it,
     * the head came back one column short, and the rail landed a column left
     * of the node it frames. Every rail stays on its column, every row stays
     * pane-wide, and the covered glyph's other half is blank.
     */
    public function testTheFrameKeepsItsColumnsOverDoubleWidthText(): void
    {
        $node = new GraphNode(0, 'A', 'A', 100.0, 100.0, 60.0, 40.0, null, null, false);
        $layout = new GraphLayout(200.0, 200.0, [0 => $node], [], []);
        $view = new ZoomPanModel(200, 200);
        [$x0, $y0, $x1, $y1] = new GraphCellGrid(20, 10, $view, null)->nodeBox($node);
        $this->assertSame([7, 4, 13, 6], [$x0, $y0, $x1, $y1], 'the premise: both rails on the right half of a 中');

        $lines = GraphSelectionOverlay::apply(\array_fill(0, count: 10, value: \str_repeat('中', times: 10)), $layout, $view, 0, 20, 10, Styles::dark());

        foreach ($lines as $i => $line) {
            $this->assertSame(20, AnsiUtils::visibleWidth($line), 'row '.$i);
        }
        $mid = AnsiUtils::stripAnsiCodes($lines[5]);
        $this->assertSame(7, AnsiUtils::visibleWidth(\strstr($mid, Glyphs::FRAME_VERTICAL, before_needle: true)), 'the left rail sits on column 7');
        $this->assertSame('中中中 '.Glyphs::FRAME_VERTICAL.'中中 '.Glyphs::FRAME_VERTICAL.'中中中', $mid);
        $this->assertSame('中中中 '.\str_repeat(Glyphs::FRAME_HORIZONTAL, times: 7).'中中中', AnsiUtils::stripAnsiCodes($lines[4]));
    }

    /**
     * The same frame over text shifted one column: now each rail covers a 中's
     * LEFT half, so the cut that meets a glyph is the TAIL's (columns 8 and 14).
     * The old splice dropped it there and slid everything after the rail left.
     */
    public function testTheFrameKeepsItsColumnsWhenTheTailStartsInsideAGlyph(): void
    {
        $node = new GraphNode(0, 'A', 'A', 100.0, 100.0, 60.0, 40.0, null, null, false);
        $layout = new GraphLayout(200.0, 200.0, [0 => $node], [], []);
        $row = ' '.\str_repeat('中', times: 9).' '; // 中 at columns 1-2, 3-4, … 17-18

        $lines = GraphSelectionOverlay::apply(\array_fill(0, count: 10, value: $row), $layout, new ZoomPanModel(200, 200), 0, 20, 10, Styles::dark());

        $this->assertSame(' 中中中'.Glyphs::FRAME_VERTICAL.' 中中'.Glyphs::FRAME_VERTICAL.' 中中 ', AnsiUtils::stripAnsiCodes($lines[5]));
        $this->assertSame(20, AnsiUtils::visibleWidth($lines[5]));
    }

    /** After a rail, the blank for the glyph it half-covers keeps the text's background. */
    public function testTheBlankBesideARailKeepsTheTextBackground(): void
    {
        $node = new GraphNode(0, 'A', 'A', 100.0, 100.0, 60.0, 40.0, null, null, false);
        $layout = new GraphLayout(200.0, 200.0, [0 => $node], [], []);
        $row = "\e[44m ".\str_repeat('中', times: 9)." \e[0m";

        $lines = GraphSelectionOverlay::apply(\array_fill(0, count: 10, value: $row), $layout, new ZoomPanModel(200, 200), 0, 20, 10, Styles::dark());

        $after = \substr($lines[5], (int) \strrpos($lines[5], Glyphs::FRAME_VERTICAL) + \strlen(Glyphs::FRAME_VERTICAL));
        $this->assertMatchesRegularExpression('/^(?:\e\[[0-9;:]*m)*?\e\[44m /', $after);
    }
}
