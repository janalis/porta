<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use Janalis\Viz\Layout\GraphLayout;
use Janalis\Viz\Layout\GraphNode;
use Janalis\Viz\Mermaid\MermaidScene;
use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Mermaid\MermaidHitTest;
use Porta\ImageViewer\ZoomPanModel;

/**
 * A cell hits an element where the element's own outline covers the cell's
 * MIDDLE — the column's centre, the row's centre — at one point a column and
 * two a row here: a diamond 8 points across centred on (10, 10).
 */
final class MermaidHitTestTest extends TestCase
{
    public function testACellHitsADiamondWhereItsMiddleIsInside(): void
    {
        $scene = self::scene([0 => self::node(0, 10.0, 10.0, 8.0)], [0 => 'diamond']);

        $this->assertSame(
            ['left of the middle' => 0, 'below the middle' => 0, 'above the middle' => 0, 'off the tip' => null],
            [
                // (7.5, 9): 2.5 + 1 from the centre, of 4; the cell's left edge alone would be out.
                'left of the middle' => self::hit($scene, 4, 7),
                // (10.5, 13): its lower half-row alone would be out.
                'below the middle' => self::hit($scene, 6, 10),
                // (10.5, 7): its upper half-row alone would be out.
                'above the middle' => self::hit($scene, 3, 10),
                'off the tip' => self::hit($scene, 2, 7),
            ],
        );
    }

    /** Two markers on one cell, alike: the one drawn last, which is the one on top. */
    public function testTwoMarkersAlikeGoToTheOneDrawnLast(): void
    {
        $scene = self::scene([0 => self::node(0, 10.0, 10.0, 2.0), 1 => self::node(1, 10.0, 10.0, 2.0)], [0 => 'point', 1 => 'point']);

        $this->assertSame(1, self::hit($scene, 5, 10));
    }

    private static function node(int $id, float $x, float $y, float $size): GraphNode
    {
        return new GraphNode($id, (string) $id, '', $x, $y, $size, $size, null, null, false);
    }

    /**
     * @param array<int, GraphNode> $nodes
     * @param array<int, string> $shapes
     */
    private static function scene(array $nodes, array $shapes): MermaidScene
    {
        return new MermaidScene(new GraphLayout(20.0, 20.0, $nodes, [], []), [], [], elements: \array_combine(\array_keys($nodes), \array_keys($nodes)), shapes: $shapes);
    }

    private static function hit(MermaidScene $scene, int $row, int $col): ?int
    {
        $view = new ZoomPanModel(20, 20);
        $view->actualSize(20, 20);

        return MermaidHitTest::nodeAt($scene, $view, 20, 10, $row, $col);
    }
}
