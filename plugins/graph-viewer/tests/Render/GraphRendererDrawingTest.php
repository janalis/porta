<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Render;

use Janalis\Viz\Layout\GraphCluster;
use Janalis\Viz\Layout\GraphEdge;
use Janalis\Viz\Layout\GraphLayout;
use Janalis\Viz\Layout\GraphNode;
use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Render\GraphRenderer;
use Porta\ImageViewer\ZoomPanModel;
use Porta\Ui\Role;
use Porta\Ui\Theme\Styles;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * The whole cell drawing of one graph that has every kind of thing in it, at
 * one point a column and two a row, cell for cell. The piecewise tests in
 * {@see GraphRendererTest} ask that a glyph appears somewhere; a frame one
 * cell short, a label a row off or a dash for a line passed them all.
 */
final class GraphRendererDrawingTest extends TestCase
{
    public function testEveryKindOfElementLandsOnItsOwnCells(): void
    {
        $lines = GraphRenderer::render(self::layout(), self::view(), null, 64, 30, Styles::dark());

        $this->assertSame(
            [
                '                                                                ',
                '  ┌─ App ─────────────────────────────────┐   ┌┄ External serv┐ ',
                '  │                                       │   ┆  two          ┆ ',
                '  │   ┌───────────────┐                   │   ┆               ┆ ',
                '  │   │               │                   │   ┆      ▪        ┆ ',
                '  │   │      Box      │                   │   ┆      │        ┆ ',
                '  │   │               │────────           │   ┆               ┆ ',
                '  │   │               │       │           │   ┆      │        ┆ ',
                '  │   └───────────────┘       │   calls   │   ┆               ┆ ',
                '  │                           │           │   ┆      │        ┆ ',
                '  │                           │           │   ┆┌───────────┐  ┆ ',
                '  │                           │           │   ┆│     │     │  ┆ ',
                '  │                    ┌╌╌╌╌╌╌▾╌╌╌╌╌╌┐    │   ┆│    Bg     │  ┆ ',
                '  │                    ╎             ╎    │   ┆│     │     │  ┆ ',
                '  │                    ╎     Two     ╎    │   ┆└───────────┘  ┆ ',
                '  │                    ╎    Lines    ╎    │   ┆      │        ┆ ',
                '  │                    ╎             ╎    │   ┆               ┆ ',
                '  │   ──               └╌╌╌╌╌╌╌╌╌╌╌╌╌┘    │   └┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┘ ',
                '  │     ──                                │   ┌───────────────┐ ',
                '  │                                       │   │      │        │ ',
                '  │         ▸                             │   │               │ ',
                '  │                                       │   │      ▾        │ ',
                '  └───────────────────────────────────────┘   │               │ ',
                '                ─────────                     └───────────────┘ ',
                '                        ▾         ▪                             ',
                '                                          ─                     ',
                '    ┌─┐      ┌─┐                        ──       ┌─────────┐    ',
                '    └─┘      └n┘    ▴   ◂──────       ◂          │   one   │    ',
                '                    │                            │   two   │    ',
                '                    │                            └─────────┘    ',
            ],
            \array_map(AnsiUtils::stripAnsiCodes(...), $lines),
        );
    }

    public function testTheSelectedNodeIsDrawnInTheSelectionsColour(): void
    {
        $styles = Styles::dark();
        $lines = GraphRenderer::render(self::layout(), self::view(), 0, 64, 30, $styles);

        $this->assertStringContainsString($styles->of(Role::SelectedRow)->apply('┌───────────────┐'), $lines[3], 'Box, selected');
        $this->assertStringNotContainsString($styles->of(Role::SelectedRow)->apply('┌╌╌╌╌╌╌'), $lines[12], 'Two, not');
    }

    /**
     * A node one point inside the pane on each side still draws its edge
     * there: the cull is against the node's whole box, not a shrunk one.
     */
    public function testANodeOnlyJustInsideThePaneStillDraws(): void
    {
        $node = static fn (int $id, float $x, float $y): GraphNode => new GraphNode($id, (string) $id, '', $x, $y, 20.0, 20.0, null, null, false);
        $layout = new GraphLayout(100.0, 100.0, [$node(id: 0, x: 21.0, y: 50.0), $node(id: 1, x: 79.0, y: 50.0), $node(id: 2, x: 50.0, y: 21.0), $node(id: 3, x: 50.0, y: 79.0)], [], []);
        $view = new ZoomPanModel(100, 100);
        $view->actualSize(40, 40); // centred: the world from (30, 30) to (70, 70)
        $this->assertSame([30, 30], [$view->visibleRect(40, 40)['srcX'], $view->visibleRect(40, 40)['srcY']]);

        $rows = \array_map(AnsiUtils::stripAnsiCodes(...), GraphRenderer::render($layout, $view, null, 40, 20, Styles::dark()));

        $this->assertSame('│', \mb_substr($rows[10], start: 1, length: 1), 'the left one\'s right side');
        $this->assertSame('│', \mb_substr($rows[10], start: 39, length: 1), 'the right one\'s left side');
        $this->assertSame('─', \mb_substr($rows[0], start: 20, length: 1), 'the top one\'s bottom');
        $this->assertSame('─', \mb_substr($rows[19], start: 20, length: 1), 'the bottom one\'s top');
    }

    /**
     * A solid cluster, a dashed one, one with a colour and no label, and one
     * with neither (never drawn, though it spans the page); a boxed node, a
     * dashed one with two lines, one too small to box, and a background one an
     * edge is drawn over; a labelled edge bending down onto a box, a dashed
     * one with a label but nowhere to put it, one hidden under a box; and along
     * the bottom, the smallest cluster that frames beside one a column too
     * narrow, the smallest node that boxes (room for one of its two label
     * lines) and one a column wide however tall, arrowheads read off the point
     * before a tip that sits on the last, one turning down just before its tip,
     * one coming in on the diagonal (the horizontal's side wins), and three
     * label lines in a box with room for two.
     */
    private static function layout(): GraphLayout
    {
        return new GraphLayout(
            64.0,
            60.0,
            [
                0 => new GraphNode(0, 'box', 'Box', 14.0, 12.0, 16.0, 10.0, null, null, false),
                1 => new GraphNode(1, 'two', "Two\nLines", 30.0, 30.0, 14.0, 10.0, null, null, true),
                2 => new GraphNode(2, 't', 'T', 53.0, 8.0, 1.0, 1.0, null, null, false),
                3 => new GraphNode(3, 'bg', 'Bg', 53.0, 24.0, 12.0, 8.0, null, null, false, background: true),
                4 => new GraphNode(4, 'n', "n\nm", 14.0, 53.0, 2.0, 2.0, null, null, false),
                6 => new GraphNode(6, 'thin', 'thin', 34.0, 49.0, 1.0, 6.0, null, null, false),
                5 => new GraphNode(5, 'many', "one\ntwo\nthree", 54.0, 55.0, 10.0, 6.0, null, null, false),
            ],
            [
                new GraphEdge(0, 1, [[22.0, 12.0], [30.0, 12.0], [30.0, 22.0]], [30.0, 25.0], 22.0, 12.0, 30.0, 25.0, null, 'calls', [36.0, 16.0], false),
                new GraphEdge(2, 3, [[53.0, 10.0], [53.0, 40.0]], [53.0, 42.0], 53.0, 10.0, 53.0, 42.0, null, 'unplaced', null, true),
                new GraphEdge(0, 0, [[7.0, 10.0], [21.0, 10.0]], null, 7.0, 10.0, 21.0, 10.0, null, null, null, false),
                new GraphEdge(4, 4, [[30.0, 54.0], [24.0, 54.0]], [24.0, 54.0], 24.0, 54.0, 30.0, 54.0, null, null, null, false),
                new GraphEdge(5, 5, [[42.0, 50.0], [40.0, 52.0]], [38.0, 54.0], 38.0, 50.0, 42.0, 54.0, null, null, null, false),
                new GraphEdge(6, 6, [[16.0, 46.0], [24.0, 46.0]], [24.0, 49.0], 16.0, 46.0, 24.0, 49.0, null, null, null, false),
                new GraphEdge(6, 6, [[20.0, 58.0], [20.0, 54.0]], [20.0, 54.0], 20.0, 54.0, 20.0, 58.0, null, null, null, false),
                new GraphEdge(1, 1, [[6.0, 34.0], [9.0, 37.0]], [12.0, 40.0], 6.0, 34.0, 12.0, 40.0, null, null, null, false),
            ],
            [
                new GraphCluster('c_app', 'App', 2.0, 2.0, 42.0, 44.0, null, null),
                new GraphCluster('c_ext', "External services\ntwo", 46.0, 2.0, 62.0, 34.0, null, null, dashed: true),
                new GraphCluster('c_tiny', '', 4.0, 52.0, 6.0, 54.0, '#808080', null),
                new GraphCluster('c_narrow', 'N', 9.0, 52.0, 10.0, 54.0, '#808080', null),
                new GraphCluster('c_plain', '', 46.0, 36.0, 62.0, 46.0, '#808080', null),
                new GraphCluster('c_none', '', 0.0, 0.0, 63.0, 59.0, 'none', null),
            ],
        );
    }

    private static function view(): ZoomPanModel
    {
        $view = new ZoomPanModel(64, 60);
        $view->actualSize(64, 60);

        return $view;
    }
}
