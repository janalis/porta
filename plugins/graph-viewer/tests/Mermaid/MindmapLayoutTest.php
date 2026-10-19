<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Graph\GraphNode;
use Porta\GraphViewer\Mermaid\MermaidScene;

/** The two-sided mind map: branches either side of the root, subtrees stacked without overlap. */
final class MindmapLayoutTest extends TestCase
{
    private const string MAP = <<<'MMD'
        mindmap
          root((Root))
            One
              One A
              One B
                Deep
            Two
            Three
              Three A
            Four
            Five
        MMD;

    public function testTheFirstHalfOfTheBranchesGrowsRightAndTheRestLeft(): void
    {
        $scene = NativeFixtureScene::render(self::MAP);
        $root = self::node($scene, 'Root');
        foreach (['One' => 1, 'Two' => 1, 'Three' => 1, 'Four' => -1, 'Five' => -1, 'Deep' => 1, 'Three A' => 1] as $label => $side) {
            $node = self::node($scene, $label);
            $this->assertSame($side, $node->x <=> $root->x, $label);
        }
        $one = self::node($scene, 'One');
        $this->assertSame(6.0, $one->x - ($one->w / 2) - ($root->x + ($root->w / 2)), 'six units between a parent and its children');
        $four = self::node($scene, 'Four');
        $this->assertSame(6.0, $root->x - ($root->w / 2) - ($four->x + ($four->w / 2)));
        $this->assertSame($root->y, (self::node($scene, 'Four')->y + self::node($scene, 'Five')->y) / 2, 'a side stacks about the root');
    }

    public function testSiblingsStackWithoutOverlapAndParentsCenterOnTheirChildren(): void
    {
        $scene = NativeFixtureScene::render(self::MAP);
        $one = self::node($scene, 'One');
        [$a, $b] = [self::node($scene, 'One A'), self::node($scene, 'One B')];
        $this->assertSame($one->y, ($a->y + $b->y) / 2);
        $this->assertSame(2.0, $b->y - ($b->h / 2) - ($a->y + ($a->h / 2)), 'two units between leaf siblings');
        $this->assertSame($b->y, self::node($scene, 'Deep')->y, 'an only child lines up with its parent');
        $two = self::node($scene, 'Two');
        $this->assertSame(2.0, $two->y - ($two->h / 2) - ($b->y + ($b->h / 2)), 'a subtree takes its children\'s height');
        $three = self::node($scene, 'Three');
        $this->assertSame($three->y, self::node($scene, 'Three A')->y);
        foreach ($scene->graph->nodes as $node) {
            $this->assertGreaterThanOrEqual(2.0, $node->x - ($node->w / 2));
            $this->assertGreaterThanOrEqual(2.0, $node->y - ($node->h / 2));
            $this->assertLessThanOrEqual($scene->graph->width - 2.0, $node->x + ($node->w / 2));
            $this->assertLessThanOrEqual($scene->graph->height - 2.0, $node->y + ($node->h / 2));
        }
        $this->assertSame(2.0, \min(\array_map(static fn (GraphNode $node): float => $node->x - ($node->w / 2), $scene->graph->nodes)));
        $this->assertSame(2.0, \min(\array_map(static fn (GraphNode $node): float => $node->y - ($node->h / 2), $scene->graph->nodes)));
        $this->assertSame($scene->graph->width - 2.0, \max(\array_map(static fn (GraphNode $node): float => $node->x + ($node->w / 2), $scene->graph->nodes)));
    }

    public function testEdgesJoinCentersAndRowsFollowTheirNodes(): void
    {
        $scene = NativeFixtureScene::render(self::MAP);
        $this->assertSame(\range(0, end: 9), \array_keys($scene->graph->nodes), 'nodes keep declaration order for drawing and navigation');
        $this->assertCount(9, $scene->graph->edges);
        foreach ($scene->graph->edges as $edge) {
            [$from, $to] = [$scene->graph->nodes[$edge->tail], $scene->graph->nodes[$edge->head]];
            $this->assertSame([[$from->x, $from->y], [$to->x, $to->y]], $edge->points);
            $this->assertSame([\min($from->x, $to->x), \min($from->y, $to->y), \max($from->x, $to->x), \max($from->y, $to->y)], [$edge->minX, $edge->minY, $edge->maxX, $edge->maxY]);
        }
        $this->assertSame(5, $scene->elements[self::node($scene, 'Deep')->id]);
        foreach ($scene->origins as $row => $origin) {
            $covering = \array_filter(
                $scene->graph->nodes,
                static fn (GraphNode $node): bool => (int) \floor(($node->y - ($node->h / 2)) / 2) <= $row && $row <= (int) \ceil(($node->y + ($node->h / 2)) / 2),
            );
            $expected = [] === $covering ? [1] : \array_map(static fn (GraphNode $node): int => $scene->elements[$node->id], $covering);
            $this->assertContains($origin, $expected, 'row '.$row.' leads to a node on it, else to the root');
        }
        $this->assertSame(1, NativeFixtureScene::render("mindmap\n  Alone")->graph->nodes[0]->id + 1);
    }

    public function testPlainLabelsWrapAtTheMaximumNodeWidthKeepingTheirCharacters(): void
    {
        $scene = NativeFixtureScene::render("mindmap\n  British popular psychology author Tony Buzan\n    snake_case *stars* and \\back\n    Short");
        $this->assertSame("British popular psychology\nauthor Tony Buzan", $scene->graph->nodes[0]->label, 'twenty-six characters fit at the pinned 200px');
        $this->assertSame("snake_case *stars* and\n\\back", $scene->graph->nodes[1]->label);
        $this->assertSame('Short', $scene->graph->nodes[2]->label);
        $narrow = NativeFixtureScene::render("---\nconfig:\n  mindmap:\n    maxNodeWidth: 60\n---\nmindmap\n  British popular psychology");
        $this->assertSame("British\npopular\npsychology", $narrow->graph->nodes[0]->label);
        $flowchart = NativeFixtureScene::render("flowchart LR\n  A[British popular psychology author Tony Buzan]");
        $this->assertSame('British popular psychology author Tony Buzan', $flowchart->graph->nodes[0]->label, 'pinned flowcharts wrap Markdown strings only');
    }

    private static function node(MermaidScene $scene, string $label): GraphNode
    {
        $node = \array_find($scene->graph->nodes, static fn (GraphNode $node): bool => $label === $node->label);
        self::assertInstanceOf(GraphNode::class, $node, $label);

        return $node;
    }
}
