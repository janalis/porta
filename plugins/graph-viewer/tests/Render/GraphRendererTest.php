<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Render;

use Janalis\Viz\Layout\GraphCluster;
use Janalis\Viz\Layout\GraphEdge;
use Janalis\Viz\Layout\GraphLayout;
use Janalis\Viz\Layout\GraphNode;
use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Render\GraphCellGrid;
use Porta\GraphViewer\Render\GraphRenderer;
use Porta\ImageViewer\ZoomPanModel;
use Porta\Ui\Role;
use Porta\Ui\Theme\Styles;
use Symfony\Component\Tui\Ansi\AnsiUtils;

final class GraphRendererTest extends TestCase
{
    private static function node(int $id, string $name, float $x, float $y, float $w = 40.0, float $h = 20.0, ?string $color = null, bool $dashed = false, ?string $label = null): GraphNode
    {
        return new GraphNode($id, $name, $label ?? $name, $x, $y, $w, $h, $color, null, $dashed);
    }

    /** @param list<array{float, float}> $points */
    private static function edge(int $tail, int $head, array $points, ?array $tip, ?string $label = null, ?array $lp = null): GraphEdge
    {
        $xs = \array_column($points, 0);
        $ys = \array_column($points, 1);

        return new GraphEdge($tail, $head, $points, $tip, \min($xs), \min($ys), \max($xs), \max($ys), null, $label, $lp, false);
    }

    private static function plain(array $lines): string
    {
        return \implode("\n", \array_map(AnsiUtils::stripAnsiCodes(...), $lines));
    }

    public function testArrowheadsPointInAllFourDirections(): void
    {
        $layout = new GraphLayout(
            200.0,
            200.0,
            [
                0 => self::node(0, name: 'C', x: 100.0, y: 100.0, w: 10.0, h: 10.0),
                1 => self::node(1, name: 'L', x: 30.0, y: 100.0, w: 10.0, h: 10.0),
                2 => self::node(2, name: 'U', x: 100.0, y: 30.0, w: 10.0, h: 10.0),
                3 => self::node(3, name: 'D', x: 100.0, y: 170.0, w: 10.0, h: 10.0),
            ],
            [
                self::edge(0, head: 1, points: [[90.0, 100.0], [50.0, 100.0]], tip: [40.0, 100.0]), // pointing left
                self::edge(0, head: 2, points: [[100.0, 90.0], [100.0, 50.0]], tip: [100.0, 40.0]), // pointing up
                // The empty first label line draws no label (the arrow still renders).
                self::edge(0, head: 3, points: [[100.0, 110.0], [100.0, 150.0]], tip: [100.0, 160.0], label: "\nonly-second-line", lp: [100.0, 130.0]), // pointing down
            ],
            [],
        );
        $view = new ZoomPanModel(200, 200); // fit: 0.3 — the whole graph is visible

        $screen = self::plain(GraphRenderer::render($layout, $view, selected: null, cols: 60, rows: 30, styles: Styles::dark()));
        $this->assertStringContainsString('◂', $screen);
        $this->assertStringContainsString('▴', $screen);
        $this->assertStringContainsString('▾', $screen);
    }

    public function testTinyNodesRenderAsASingleCellAndLongLabelsTruncate(): void
    {
        $layout = new GraphLayout(
            400.0,
            60.0,
            [
                0 => self::node(0, name: 'tiny', x: 20.0, y: 30.0, w: 2.0, h: 2.0),
                1 => self::node(1, name: 'wide', x: 200.0, y: 30.0, w: 60.0, h: 24.0, label: 'AVeryLongLabelThatCannotFit'),
            ],
            [],
            [],
        );
        $view = new ZoomPanModel(400, 60); // fit: 0.2 — the tiny node maps under 2 cells

        $screen = self::plain(GraphRenderer::render($layout, $view, selected: null, cols: 80, rows: 20, styles: Styles::dark()));
        $this->assertStringContainsString('▪', $screen, 'a sub-box node collapses to one cell');
        $this->assertStringContainsString('…', $screen, 'an overlong label ellipsizes');
        $this->assertStringNotContainsString('AVeryLongLabelThatCannotFit', $screen);
    }

    public function testOffscreenElementsAreCulled(): void
    {
        $cluster = new GraphCluster('cluster_far', 'Far', 300.0, 10.0, 390.0, 50.0, null, null);
        $layout = new GraphLayout(
            400.0,
            60.0,
            [
                0 => self::node(0, name: 'Near', x: 20.0, y: 30.0),
                1 => self::node(1, name: 'Far', x: 350.0, y: 30.0),
            ],
            [
                self::edge(0, head: 1, points: [[40.0, 30.0], [330.0, 30.0]], tip: null, label: 'link', lp: [180.0, 25.0]),
                self::edge(1, head: 1, points: [[340.0, 30.0], [360.0, 30.0]], tip: null),
            ],
            [$cluster],
        );
        // 100% zoom in a small pane, panned hard left: everything near x=350 is off-screen.
        $view = new ZoomPanModel(400, 60);
        $view->actualSize(30, pxRows: 20);
        $view->panBy(-10_000, dyHalfRows: 0, cols: 30, pxRows: 20);

        $screen = self::plain(GraphRenderer::render($layout, $view, selected: null, cols: 30, rows: 10, styles: Styles::dark()));
        $this->assertStringContainsString('Near', $screen);
        $this->assertStringNotContainsString('Far', $screen, 'the far node and its cluster were culled');
    }

    public function testAClusterTooSmallToFrameIsSkipped(): void
    {
        $cluster = new GraphCluster('cluster_a', 'A', 10.0, 10.0, 150.0, 90.0, '#0000ff', [80.0, 12.0]);
        $layout = new GraphLayout(420.0, 100.0, [0 => self::node(0, name: 'N', x: 80.0, y: 50.0)], [], [$cluster]);

        $screen = self::plain(GraphRenderer::render($layout, new ZoomPanModel(420, 100), selected: null, cols: 4, rows: 3, styles: Styles::dark()));
        $this->assertStringNotContainsString('┌', $screen, 'no frame fits a 4-column pane at fit scale');
    }

    /**
     * Where two boxes overlap, each cell goes to the box whose centre is
     * nearer — a row counting twice a column, a cell being twice as tall as
     * wide — and a tie to the one drawn last.
     */
    public function testWhereBoxesOverlapTheNearerCentreWins(): void
    {
        $layout = new GraphLayout(40.0, 24.0, [0 => self::node(0, name: 'A', x: 10.0, y: 10.0, w: 16.0, h: 12.0), 1 => self::node(1, name: 'B', x: 20.0, y: 14.0, w: 16.0, h: 12.0)], [], []);
        $view = new ZoomPanModel(40, 24);
        $view->actualSize(40, pxRows: 24);

        $map = [];
        for ($row = 2; $row <= 10; ++$row) {
            $line = '';
            for ($col = 2; $col <= 28; ++$col) {
                $line .= ['A', 'B'][GraphRenderer::nodeAt($layout, $view, cols: 40, rows: 12, relRow: $row, relCol: $col) ?? -1] ?? '.';
            }
            $map[] = $line;
        }

        $this->assertSame(
            [
                'AAAAAAAAAAAAAAAAA..........',
                'AAAAAAAAAAAAAAAAA..........',
                'AAAAAAAAAAAAAAABBBBBBBBBBBB',
                'AAAAAAAAAAAAAAABBBBBBBBBBBB',
                'AAAAAAAAAAAAABBBBBBBBBBBBBB',
                'AAAAAAAAAAABBBBBBBBBBBBBBBB',
                'AAAAAAAAAAABBBBBBBBBBBBBBBB',
                '..........BBBBBBBBBBBBBBBBB',
                '..........BBBBBBBBBBBBBBBBB',
            ],
            $map,
        );
    }

    /** Over the picture, two nodes covering a cell alike: the one drawn last, which the picture shows on top. */
    public function testOverThePictureATieGoesToTheNodeDrawnLast(): void
    {
        $layout = new GraphLayout(40.0, 24.0, [0 => self::node(0, name: 'A', x: 20.0, y: 12.0), 1 => self::node(1, name: 'B', x: 20.0, y: 12.0)], [], []);

        $this->assertSame(1, GraphRenderer::nodeShownAt($layout, new ZoomPanModel(40, 24), cols: 40, rows: 12, relRow: 6, relCol: 20));
    }

    public function testNodeAtHitsTheBoxAndMissesEmptySpace(): void
    {
        $layout = new GraphLayout(
            200.0,
            100.0,
            [
                5 => self::node(5, name: 'Hit', x: 100.0, y: 50.0, w: 80.0, h: 40.0),
            ],
            [],
            [],
        );
        $view = new ZoomPanModel(200, 100);
        $view->actualSize(100, pxRows: 60);
        [$x0, $y0, $x1, $y1] = new GraphCellGrid(100, 30, $view, null)->nodeBox($layout->nodes[5]);

        $this->assertSame(5, GraphRenderer::nodeAt($layout, $view, cols: 100, rows: 30, relRow: \intdiv($y0 + $y1, num2: 2), relCol: \intdiv($x0 + $x1, num2: 2)));
        $this->assertNull(GraphRenderer::nodeAt($layout, $view, cols: 100, rows: 30, relRow: 0, relCol: 0));
    }

    /**
     * A zoomed-out graph rounds neighbours onto shared rows: the click on one
     * node's label, at its centre, is that node's even where the box drawn
     * after it reaches over — the architecture graph's GitPlugin under its
     * GraphViewer neighbour.
     */
    public function testWhereBoxesOverlapTheNearestCentreWins(): void
    {
        $nodes = [
            1 => self::node(1, name: 'Under', x: 100.0, y: 40.0, w: 80.0, h: 40.0),
            2 => self::node(2, name: 'Over', x: 100.0, y: 50.0, w: 80.0, h: 40.0),
            3 => self::node(3, name: 'Twin', x: 100.0, y: 50.0, w: 80.0, h: 40.0),
        ];
        $layout = new GraphLayout(200.0, 100.0, $nodes, [], []);
        $view = new ZoomPanModel(200, 100);
        $view->actualSize(100, pxRows: 60);
        $grid = new GraphCellGrid(100, 30, $view, null);
        $centre = static function (GraphNode $node) use ($grid): array {
            [$x0, $y0, $x1, $y1] = $grid->nodeBox($node);

            return [\intdiv($y0 + $y1, num2: 2), \intdiv($x0 + $x1, num2: 2)];
        };
        [$row, $col] = $centre($nodes[1]);
        $this->assertGreaterThanOrEqual($grid->nodeBox($nodes[2])[1], $row, 'the later boxes do reach over the first one\'s centre');

        $this->assertSame(1, GraphRenderer::nodeAt($layout, $view, cols: 100, rows: 30, relRow: $row, relCol: $col));
        [$row, $col] = $centre($nodes[2]);
        $this->assertSame(3, GraphRenderer::nodeAt($layout, $view, cols: 100, rows: 30, relRow: $row, relCol: $col), 'a tie: the topmost, drawn last');
    }

    /**
     * Over dot's picture, column c shows the document from c's left edge:
     * a node spanning columns 7.3 to 13.3 is in cells 7 through 13, the node
     * covering most of a cell wins it, and a cell no node reaches selects
     * nothing.
     */
    public function testNodeShownAtAnswersForThePictureNotTheRoundedBox(): void
    {
        $wide = self::node(1, name: 'Wide', x: 103.0, y: 101.0, w: 60.0, h: 40.0);
        $sliver = self::node(2, name: 'Sliver', x: 140.0, y: 101.0, w: 12.0, h: 40.0);
        $layout = new GraphLayout(200.0, 200.0, [1 => $wide, 2 => $sliver], [], []);
        $view = new ZoomPanModel(200, 200); // fit in 20 × 10: 0.1 column per point
        $grid = new GraphCellGrid(20, 10, $view, null);
        $this->assertSame([7, 4, 13, 6], $grid->shownBox($wide));

        $this->assertSame(1, GraphRenderer::nodeShownAt($layout, $view, cols: 20, rows: 10, relRow: 5, relCol: 7), 'the left edge\'s cell, 70% covered');
        $this->assertSame(2, GraphRenderer::nodeShownAt($layout, $view, cols: 20, rows: 10, relRow: 5, relCol: 13), 'cell 13 holds 30% of Wide and 60% of Sliver: the most covered wins');
        $this->assertSame(2, GraphRenderer::nodeShownAt($layout, $view, cols: 20, rows: 10, relRow: 5, relCol: 14), 'Sliver alone, to 14.6');
        $this->assertNull(GraphRenderer::nodeShownAt($layout, $view, cols: 20, rows: 10, relRow: 5, relCol: 15), 'right of it: empty picture');
        $this->assertNull(GraphRenderer::nodeShownAt($layout, $view, cols: 20, rows: 10, relRow: 5, relCol: 6), 'left of Wide: empty picture');
        $this->assertNull(GraphRenderer::nodeShownAt($layout, $view, cols: 20, rows: 10, relRow: 3, relCol: 10), 'above the node');
        $this->assertSame(0.7, \round($grid->coverage($wide, col: 7, row: 5), precision: 6));
    }

    public function testAStylelessGridPaintsNothing(): void
    {
        $view = new ZoomPanModel(100, 100);
        $grid = new GraphCellGrid(10, 5, $view, null);
        $this->assertSame('', $grid->roleKey(Role::Dim), 'no styles, no painter');
        $grid->text(0, row: 0, text: 'clipped-by-max', key: '', maxCol: 3);
        $lines = $grid->lines();
        $this->assertSame('clip', \substr($lines[0], offset: 0, length: 4), 'text stops at maxCol');
        $this->assertStringNotContainsString("\x1b[", \implode('', $lines), 'a style-less grid emits no escapes');
    }

    public function testNodeLabelsCenterAndTruncateByDisplayColumns(): void
    {
        foreach ([10 => '漢字漢字', 6 => '漢字…'] as $width => $expected) {
            $graph = new GraphLayout(24.0, 12.0, [self::node(0, name: 'Unicode', x: 10.0, y: 6.0, w: (float) $width, h: 6.0, label: '漢字漢字')], [], []);
            $view = new ZoomPanModel(24, 12, [1.0]);
            $lines = GraphRenderer::render($graph, $view, selected: null, cols: 24, rows: 6, styles: Styles::dark());
            $this->assertStringContainsString($expected, self::plain($lines));
            $this->assertSame(24, AnsiUtils::visibleWidth($lines[2]));
        }
    }

    public function testWideGraphemesOccupyTwoCellsAndClipAtTheBoundary(): void
    {
        $grid = new GraphCellGrid(5, 2, new ZoomPanModel(5, 4), null);
        $grid->text(0, row: 0, text: '漢字X', key: '', maxCol: null);
        $grid->text(0, row: 1, text: 'é漢字', key: '', maxCol: 2);
        $this->assertSame(['漢字X', 'é漢  '], $grid->lines());
        $grid->text(4, row: 0, text: '漢', key: '', maxCol: null);
        $this->assertSame('漢字X', $grid->lines()[0]);
    }

    public function testABlankRunUnderAnOrdinaryRoleSkipsEscapesButASelectedRowRunDoesNot(): void
    {
        $view = new ZoomPanModel(100, 100);
        $grid = new GraphCellGrid(4, 2, $view, Styles::dark());
        $dim = $grid->roleKey(Role::Dim);
        $selected = $grid->roleKey(Role::SelectedRow);
        $grid->put(0, row: 0, char: ' ', key: $dim);
        $grid->put(1, row: 0, char: ' ', key: $dim);
        $grid->put(0, row: 1, char: ' ', key: $selected);
        $grid->put(1, row: 1, char: ' ', key: $selected);
        $lines = $grid->lines();

        $this->assertStringNotContainsString("\x1b[", $lines[0], 'a blank run under a plain role needs no escapes');
        $this->assertStringContainsString("\x1b[", $lines[1], "a selected node's blank interior is still inverse-painted");
    }
}
