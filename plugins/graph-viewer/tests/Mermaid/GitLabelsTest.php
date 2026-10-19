<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Graph\GraphEdge;
use Porta\GraphViewer\Graph\GraphNode;
use Porta\GraphViewer\Mermaid\MermaidOutline;
use Porta\GraphViewer\Mermaid\MermaidScene;

/** Pinned git commit labels, tag flags, rotation and rerouted arrows. */
final class GitLabelsTest extends TestCase
{
    private static function node(MermaidScene $scene, string $name): GraphNode
    {
        $node = \array_find($scene->graph->nodes, static fn (GraphNode $node): bool => $name === $node->name);
        self::assertInstanceOf(GraphNode::class, $node, $name);

        return $node;
    }

    public function testLabelsShowIdsExceptForAnonymousMergesAndCherryPicks(): void
    {
        $scene = NativeFixtureScene::render("gitGraph\ncommit id:'base' msg:'not drawn'\nbranch topic\ncommit id:'work'\ncheckout main\ncherry-pick id:'work'\nmerge topic\nmerge topic id:'named'");
        $captions = \array_filter($scene->graph->nodes, static fn (GraphNode $node): bool => \str_starts_with($node->name, '@caption:'));
        $this->assertSame(['base', 'work', '', '', 'named'], \array_values(\array_column($captions, 'label')));
        $this->assertStringContainsString('not drawn', self::node($scene, 'base')->label, 'the message stays in the semantic label');
        $hidden = NativeFixtureScene::render("---\nconfig:\n  gitGraph:\n    showCommitLabel: false\n---\ngitGraph\ncommit id:'base'");
        $this->assertSame('', self::node($hidden, '@caption:base')->label);
    }

    public function testLeftToRightLabelsSlantUnlessTurnedOffAndOtherDirectionsStayLevel(): void
    {
        $scene = NativeFixtureScene::render("gitGraph\ncommit id:'abcdefgh'");
        $commit = self::node($scene, 'abcdefgh');
        $caption = self::node($scene, '@caption:abcdefgh');
        $reach = 4 * \M_SQRT1_2;
        $this->assertSame('rotated:-45:'.($commit->x + 0.5 - $reach).':'.($commit->y + 3 + $reach), $scene->shapes[$caption->id], 'the label ends just below the commit');
        $backdrop = MermaidOutline::marks($caption->painted(null, '#abcdef', null), $scene->shapes[$caption->id]);
        $this->assertCount(1, $backdrop);
        $this->assertSame('#abcdef', $backdrop[0]->fill);
        $this->assertEqualsWithDelta($backdrop[0]->points[0][1] - $backdrop[0]->points[1][1], $backdrop[0]->points[1][0] - $backdrop[0]->points[0][0], 1e-9, 'its long side slants at 45°');
        $this->assertEqualsWithDelta(
            (9 / 2) * \M_SQRT2,
            \hypot($backdrop[0]->points[1][0] - $backdrop[0]->points[0][0], $backdrop[0]->points[1][1] - $backdrop[0]->points[0][1]) / \M_SQRT2,
            1e-9,
            'one column wider than the text',
        );
        $this->assertSame([], MermaidOutline::marks($caption->painted(null, 'none', null), $scene->shapes[$caption->id]));
        $this->assertSame([], MermaidOutline::marks($caption, 'rotated:-45:x:1'));
        $level = NativeFixtureScene::render("---\nconfig:\n  gitGraph:\n    rotateCommitLabel: false\n---\ngitGraph\ncommit id:'abcdefgh'");
        $this->assertSame('rect', $level->shapes[self::node($level, '@caption:abcdefgh')->id]);
        $down = NativeFixtureScene::render("gitGraph TB:\ncommit id:'abcdefgh'");
        $this->assertSame('rect', $down->shapes[self::node($down, '@caption:abcdefgh')->id]);
    }

    public function testTagsFlyFlagsAboveTheirCommitFirstTagHighest(): void
    {
        $scene = NativeFixtureScene::render("gitGraph\ncommit id:'one' tag:'v1' tag:'release-candidate'");
        $commit = self::node($scene, 'one');
        $tags = self::node($scene, '@tag:one');
        $this->assertSame("v1\nrelease-candidate", $tags->label);
        $this->assertSame([$commit->x, 4.0, \mb_strlen('release-candidate') + 3.0], [$tags->x, $tags->h, $tags->w]);
        $this->assertSame($commit->y - 3, $tags->y + ($tags->h / 2), 'the stack ends three units above the commit');
        $this->assertSame('git-tag|@textColor', $scene->shapes[$tags->id], 'the hole takes the text color');
        $marks = MermaidOutline::marks($tags, 'git-tag|#123456');
        $this->assertCount(4, $marks, 'a flag and a hole per tag');
        $left = $tags->x - ($tags->w / 2);
        $this->assertSame([[$left, $tags->y - 1], [$left + 0.8, $tags->y - 1.9]], \array_slice($marks[0]->points, offset: 0, length: 2), 'the flag points left');
        $this->assertSame($tags->y + 1, $marks[2]->points[0][1], 'the second flag lies beneath the first');
        $this->assertSame(['#123456', 0.0], [$marks[1]->fill, $marks[1]->strokeWidth]);
        $this->assertSame('#333333', MermaidOutline::marks($tags, 'git-tag')[1]->fill);
        $this->assertNull($scene->selectable($tags->id));
    }

    public function testArrowsDetourMidwayRoundCommitsOnTheTurningLaneAndThemesSetWidths(): void
    {
        // Cherry-picking b after d, the arrow from b would cross d on its own lane, so it turns midway.
        $source = "gitGraph\ncommit id:'a'\nbranch topic\ncommit id:'b'\ncommit id:'d'\ncheckout main\ncherry-pick id:'b'";
        $scene = NativeFixtureScene::render($source);
        [$b, $d] = [self::node($scene, 'b'), self::node($scene, 'd')];
        $pick = \array_last(\array_filter($scene->graph->nodes, static fn (GraphNode $node): bool => \str_ends_with($node->name, '-native') && '@' !== $node->name[0]));
        $detour = \array_find($scene->graph->relationships, static fn (GraphEdge $edge): bool => $b->id === $edge->tail && $pick->id === $edge->head);
        $middle = ($b->y + $pick->y) / 2;
        $this->assertCount(2, \array_filter($detour->points, static fn (array $point): bool => \abs($point[1] - $middle) < 1e-9), 'it crosses at the middle lane');
        $this->assertEqualsWithDelta([$b->x, $middle + 1], $detour->points[1], 1e-9, 'its corners round by one unit');
        $this->assertTrue($detour->dashed);
        $plain = NativeFixtureScene::render("gitGraph\ncommit id:'a'\nbranch topic\ncommit id:'b'\ncheckout main\ncherry-pick id:'b'");
        $straight = \array_find($plain->graph->relationships, static fn (GraphEdge $edge): bool => self::node($plain, 'b')->id === $edge->tail && $edge->dashed);
        $this->assertCount(7, $straight->points, 'with nothing in the way it turns once');
        $this->assertSame(self::node($plain, 'b')->x, $straight->points[1][0], 'a cherry-pick leaves its source like a branching child, not a merge');
        $this->assertSame(self::node($plain, 'a')->color, $straight->color, 'in the colour of the lane it lands on');
        $redux = NativeFixtureScene::render("---\nconfig:\n  theme: redux\n---\n".$source);
        $widths = \array_unique(\array_map(static fn (GraphEdge $edge): string => $edge->decoration ? 'track '.$edge->strokeWidth : 'arrow '.$edge->strokeWidth, $redux->graph->edges));
        \sort($widths);
        $this->assertSame(['arrow 0.2', 'track 0.2'], $widths);
        $classic = \array_unique(\array_map(
            static fn (GraphEdge $edge): string => $edge->decoration ? 'track '.$edge->strokeWidth : 'arrow '.$edge->strokeWidth,
            NativeFixtureScene::render("---\nconfig:\n  theme: default\n---\n".$source)->graph->edges,
        ));
        \sort($classic);
        $this->assertSame(['arrow 0.8', 'track 0.1'], $classic);
    }
}
