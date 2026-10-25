<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Render;

use Janalis\Viz\Layout\GraphNode;
use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Render\GraphCellGrid;
use Porta\ImageViewer\ZoomPanModel;
use Porta\Ui\Role;
use Porta\Ui\Sgr;
use Porta\Ui\Theme\Styles;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * The grid under the cell drawing, at one point a column and two a row: a
 * cell (c, r) is the world point (c, 2r). What {@see GraphRendererDrawingTest}
 * shows whole, piece by piece where one picture cannot tell the cases apart.
 */
final class GraphCellGridTest extends TestCase
{
    /**
     * A line is level until it climbs half a column per column, upright from
     * two rows per column, and leans with its slope between: one glyph per
     * cell of each, so the two diagonals are told apart on both sides.
     */
    public function testEachSegmentTakesTheGlyphOfItsSlope(): void
    {
        $grid = self::grid(20, 10);
        // As cells: from (column, row) to (column, row).
        $segments = [
            [[0, 0], [4, 2]], // 4 across, 2 down: level
            [[6, 0], [9, 2]], // 3 across, 2 down: leans
            [[15, 0], [12, 2]],
            [[17, 0], [18, 2]], // 1 across, 2 down: upright
            [[0, 9], [3, 7]], // 3 across, 2 up
            [[9, 9], [6, 7]],
            [[12, 9], [14, 6]], // 2 across, 3 up: leans
        ];
        foreach ($segments as [[$c0, $r0], [$c1, $r1]]) {
            $grid->segment([$c0, 2 * $r0], [$c1, 2 * $r1], '', 1);
        }

        $this->assertSame(
            [
                '─     ╲        ╱ │  ',
                ' ──    ╲╲    ╱╱   │ ',
                '   ──    ╲  ╱     │ ',
                '                    ',
                '                    ',
                '                    ',
                '              ╱     ',
                '   ╱  ╲      ╱      ',
                ' ╱╱    ╲╲    ╱      ',
                '╱        ╲  ╱       ',
            ],
            self::plain($grid),
        );
    }

    public function testASegmentOfOneCellIsADotWhenDashedAndAStrokeWhenNot(): void
    {
        $grid = self::grid(4, 1);
        $grid->segment([1.0, 0.0], [1.0, 0.0], '', 1);
        $grid->segment([3.0, 0.0], [3.0, 0.0], '', 2);

        $this->assertSame([' ─ ·'], self::plain($grid));
    }

    /** A steep line steps its columns by rounding, not by flooring nor ceiling. */
    public function testASteepLineRoundsItsColumns(): void
    {
        $grid = self::grid(4, 6);
        $grid->segment([0.0, 0.0], [2.0, 10.0], '', 1);

        $this->assertSame(['│   ', '│   ', ' │  ', ' │  ', '  │ ', '  │ '], self::plain($grid));
    }

    /** A row is the half-rows rounded first, then halved: 1.2 half-rows is row 0, 1.6 is row 1. */
    public function testARowIsTheRoundedHalfRowHalved(): void
    {
        $grid = self::grid(4, 4);

        $this->assertSame([0, 1, 1, 1], [$grid->rowOf(1.2), $grid->rowOf(1.6), $grid->rowOf(2.0), $grid->rowOf(3.4)]);
    }

    /** The window is the pane: a rect touching any of its four sides is in it, one a fraction beyond is not. */
    public function testARectTouchingTheWindowIsInItAndOneBeyondIsNot(): void
    {
        $grid = self::grid(20, 10); // the world from (0, 0) to (20, 20)

        $this->assertSame(
            [true, true, true, true, false, false],
            [
                $grid->intersects(-5.0, 5.0, 0.0, 6.0),
                $grid->intersects(20.0, 5.0, 25.0, 6.0),
                $grid->intersects(5.0, -5.0, 6.0, 0.0),
                $grid->intersects(5.0, 20.0, 6.0, 25.0),
                $grid->intersects(5.0, 20.5, 6.0, 25.0),
                $grid->intersects(20.5, 5.0, 25.0, 6.0),
            ],
        );
    }

    /** A cell beside a node's outline, in its columns but not its rows, is not covered at all — never less. */
    public function testACellOutsideTheOutlineIsCoveredByNothing(): void
    {
        $node = new GraphNode(0, 'n', 'n', 10.0, 4.0, 6.0, 4.0, null, null, false);

        $this->assertSame(0.0, self::grid(20, 10)->coverage($node, 10, 8));
    }

    /** Panned, the outline is counted from the window's corner: a quarter of the 40-point world is off each way. */
    public function testAPannedOutlineIsCountedFromTheWindowsCorner(): void
    {
        $view = new ZoomPanModel(40, 40);
        $view->actualSize(20, 20); // centred: the world from (10, 10)
        $node = new GraphNode(0, 'n', 'n', 15.0, 15.0, 4.0, 8.0, null, null, false); // (13, 11) to (17, 19)

        $this->assertSame([3, 0, 6, 4], new GraphCellGrid(20, 10, $view, null)->shownBox($node));
    }

    /** A stroke reaches the pane's first column and first row. */
    public function testAStrokeReachesTheFirstColumnAndRow(): void
    {
        $grid = self::grid(3, 2);
        $grid->segment([0.0, 0.0], [0.0, 2.0], '', 1);

        $this->assertSame(['│  ', '│  '], self::plain($grid));
    }

    /** A DOT colour is a truecolor run, opened before its cells and reset after them. */
    public function testADotColourWrapsItsRunInItsOwnCode(): void
    {
        $grid = self::grid(4, 1, Styles::dark());
        $red = $grid->colorKey('#ff0000', Role::Dim);
        $grid->text(1, 0, 'ab', $red, null);
        $grid->text(3, 0, 'c', $grid->colorKey('#ff0000aa', Role::Dim), null);

        $this->assertSame([' '."\x1b[38;2;255;0;0m".'abc'.Sgr::RESET], $grid->lines());
    }

    private static function grid(int $cols, int $rows, ?Styles $styles = null): GraphCellGrid
    {
        $view = new ZoomPanModel($cols, 2 * $rows);
        $view->actualSize($cols, 2 * $rows);

        return new GraphCellGrid($cols, $rows, $view, $styles);
    }

    /** @return list<string> */
    private static function plain(GraphCellGrid $grid): array
    {
        return \array_map(AnsiUtils::stripAnsiCodes(...), $grid->lines());
    }
}
