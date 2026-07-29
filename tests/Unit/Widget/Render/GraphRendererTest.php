<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget\Render;

use PHPUnit\Framework\TestCase;
use Porta\Graph\GraphCluster;
use Porta\Graph\GraphEdge;
use Porta\Graph\GraphLayout;
use Porta\Graph\GraphNode;
use Porta\Image\ZoomPanModel;
use Porta\Ui\Role;
use Porta\Ui\Theme\Styles;
use Porta\Widget\Render\GraphCellGrid;
use Porta\Widget\Render\GraphRenderer;
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
        $xs = array_column($points, 0);
        $ys = array_column($points, 1);

        return new GraphEdge($tail, $head, $points, $tip, min($xs), min($ys), max($xs), max($ys), null, $label, $lp, false);
    }

    private static function plain(array $lines): string
    {
        return implode("\n", array_map(AnsiUtils::stripAnsiCodes(...), $lines));
    }

    public function testArrowheadsPointInAllFourDirections(): void
    {
        $layout = new GraphLayout(
            200.0,
            200.0,
            [
                0 => self::node(0, 'C', 100.0, 100.0, w: 10.0, h: 10.0),
                1 => self::node(1, 'L', 30.0, 100.0, w: 10.0, h: 10.0),
                2 => self::node(2, 'U', 100.0, 30.0, w: 10.0, h: 10.0),
                3 => self::node(3, 'D', 100.0, 170.0, w: 10.0, h: 10.0),
            ],
            [
                self::edge(0, 1, [[90.0, 100.0], [50.0, 100.0]], [40.0, 100.0]), // pointing left
                self::edge(0, 2, [[100.0, 90.0], [100.0, 50.0]], [100.0, 40.0]), // pointing up
                // The empty first label line draws no label (the arrow still renders).
                self::edge(0, 3, [[100.0, 110.0], [100.0, 150.0]], [100.0, 160.0], label: "\nonly-second-line", lp: [100.0, 130.0]), // pointing down
            ],
            [],
        );
        $view = new ZoomPanModel(200, 200); // fit: 0.3 — the whole graph is visible

        $screen = self::plain(GraphRenderer::render($layout, $view, null, 60, 30, Styles::dark()));
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
                0 => self::node(0, 'tiny', 20.0, 30.0, w: 2.0, h: 2.0),
                1 => self::node(1, 'wide', 200.0, 30.0, w: 60.0, h: 24.0, label: 'AVeryLongLabelThatCannotFit'),
            ],
            [],
            [],
        );
        $view = new ZoomPanModel(400, 60); // fit: 0.2 — the tiny node maps under 2 cells

        $screen = self::plain(GraphRenderer::render($layout, $view, null, 80, 20, Styles::dark()));
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
                0 => self::node(0, 'Near', 20.0, 30.0),
                1 => self::node(1, 'Far', 350.0, 30.0),
            ],
            [
                self::edge(0, 1, [[40.0, 30.0], [330.0, 30.0]], null, 'link', [180.0, 25.0]),
                self::edge(1, 1, [[340.0, 30.0], [360.0, 30.0]], null),
            ],
            [$cluster],
        );
        // 100% zoom in a small pane, panned hard left: everything near x=350 is off-screen.
        $view = new ZoomPanModel(400, 60);
        $view->actualSize(30, 20);
        $view->panBy(-10_000, 0, 30, 20);

        $screen = self::plain(GraphRenderer::render($layout, $view, null, 30, 10, Styles::dark()));
        $this->assertStringContainsString('Near', $screen);
        $this->assertStringNotContainsString('Far', $screen, 'the far node and its cluster were culled');
    }

    public function testAClusterTooSmallToFrameIsSkipped(): void
    {
        $cluster = new GraphCluster('cluster_a', 'A', 10.0, 10.0, 150.0, 90.0, '#0000ff', [80.0, 12.0]);
        $layout = new GraphLayout(420.0, 100.0, [0 => self::node(0, 'N', 80.0, 50.0)], [], [$cluster]);

        $screen = self::plain(GraphRenderer::render($layout, new ZoomPanModel(420, 100), null, 4, 3, Styles::dark()));
        $this->assertStringNotContainsString('┌', $screen, 'no frame fits a 4-column pane at fit scale');
    }

    public function testNodeAtHitsTheBoxAndMissesEmptySpace(): void
    {
        $layout = new GraphLayout(
            200.0,
            100.0,
            [
                5 => self::node(5, 'Hit', 100.0, 50.0, w: 80.0, h: 40.0),
            ],
            [],
            [],
        );
        $view = new ZoomPanModel(200, 100);
        $view->actualSize(100, 60);
        $grid = new GraphCellGrid(100, 30, $view, null);
        [$x0, $y0, $x1, $y1] = $grid->nodeBox($layout->nodes[5]);

        $this->assertSame(5, GraphRenderer::nodeAt($layout, $view, 100, 30, intdiv($y0 + $y1, num2: 2), intdiv($x0 + $x1, num2: 2)));
        $this->assertNull(GraphRenderer::nodeAt($layout, $view, 100, 30, 0, 0));
    }

    public function testAStylelessGridPaintsNothing(): void
    {
        $view = new ZoomPanModel(100, 100);
        $grid = new GraphCellGrid(10, 5, $view, null);
        $this->assertSame('', $grid->roleKey(Role::Dim), 'no styles, no painter');
        $grid->text(0, 0, 'clipped-by-max', '', maxCol: 3);
        $lines = $grid->lines();
        $this->assertSame('clip', substr($lines[0], offset: 0, length: 4), 'text stops at maxCol');
        $this->assertStringNotContainsString("\x1b[", implode('', $lines), 'a style-less grid emits no escapes');
    }
}
