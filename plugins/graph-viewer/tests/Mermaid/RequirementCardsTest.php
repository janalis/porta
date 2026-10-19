<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Graph\GraphEdge;
use Porta\GraphViewer\Graph\GraphFont;
use Porta\GraphViewer\Graph\GraphNode;
use Porta\GraphViewer\Mermaid\MermaidOutline;

/** Pinned `requirementBox` cards and SysML relationship markers. */
final class RequirementCardsTest extends TestCase
{
    private const string SOURCE = "requirementDiagram\nfunctionalRequirement req {\nverifymethod: TEST\nrisk: HIGH\ntext: the text.\nid: 1\n}\nelement entity {\ndocRef: spec.md\ntype: simulation\n}\nrequirement bare {}\nentity - satisfies -> req\nreq - contains -> bare";

    public function testCardsCenterTheTypeAndBoldNameOverADividerAndListFieldsInPinnedOrder(): void
    {
        $scene = NativeFixtureScene::render(self::SOURCE);
        $node = static fn (string $name): GraphNode => \array_find($scene->graph->nodes, static fn (GraphNode $node): bool => $name === $node->name);
        $req = $node(name: 'req');
        $this->assertSame("<<Functional Requirement>>\nreq\nID: 1\nText: the text.\nRisk: High\nVerification: Test", $req->label);
        $runs = $scene->runs['node:'.$req->id];
        $this->assertSame([['<<Functional Requirement>>', GraphFont::Regular]], $runs[0]);
        $this->assertSame([['req', GraphFont::Bold]], $runs[1]);
        $this->assertSame([['ID: 1', GraphFont::Regular], [\str_repeat(' ', times: 13), GraphFont::Regular]], $runs[2], 'fields pad to the widest, so they read left-aligned');
        $this->assertSame(
            [
                ['Verification: Test', GraphFont::Regular],
                ['',                   GraphFont::Regular],
            ],
            $runs[5],
        );
        $this->assertSame('rq-card:2', $scene->shapes[$req->id]);
        $divider = MermaidOutline::marks($req, 'rq-card:2')[1];
        $top = $req->y - ($req->h / 2);
        $this->assertSame([[$req->x - ($req->w / 2), $top + 6], [$req->x + ($req->w / 2), $top + 6]], $divider->points, 'beneath the two header lines');
        $this->assertSame("<<Element>>\nentity\nType: simulation\nDoc Ref: spec.md", $node(name: 'entity')->label);
        $this->assertSame('rect', $scene->shapes[$node(name: 'bare')->id], 'a card without fields has no divider');
    }

    public function testContainsDrawsACircledPlusAndOtherRelationshipsAnOpenArrowhead(): void
    {
        $scene = NativeFixtureScene::render(self::SOURCE);
        [$satisfies, $contains] = \array_values($scene->graph->relationships);
        $marks = \array_values(\array_filter($scene->graph->edges, static fn (GraphEdge $edge): bool => $edge->decoration));
        [$left, $right, $disc, $ring, $along, $across] = $marks;
        $tip = \array_last($satisfies->points);
        $this->assertSame([$tip, $tip], [$left->points[1], $right->points[1]], 'two strokes meet at the arrival');
        $target = \array_find($scene->graph->nodes, static fn (GraphNode $node): bool => $satisfies->head === $node->id);
        $this->assertEqualsWithDelta(0.0, \min(\abs($tip[1] - ($target->y - ($target->h / 2))), \abs($tip[1] - ($target->y + ($target->h / 2)))), 1e-9, 'the line runs on to the card it reaches');
        $this->assertEqualsWithDelta(\hypot(1.6, y: 0.8), \hypot($left->points[0][0] - $tip[0], $left->points[0][1] - $tip[1]), 1e-9);
        $this->assertEqualsWithDelta(1.6, \hypot($left->points[0][0] - $right->points[0][0], $left->points[0][1] - $right->points[0][1]), 1e-9, 'sixteen pinned pixels apart');
        $start = $contains->points[0];
        $center = [($disc->minX + $disc->maxX) / 2, ($disc->minY + $disc->maxY) / 2];
        $this->assertEqualsWithDelta(0.72, \hypot($center[0] - $start[0], $center[1] - $start[1]), 1e-9, 'the circle touches the container');
        $this->assertSame(['#ffffff', $disc->points], [$disc->color, $disc->ribbon], 'its disc, in the page background, hides the line beneath');
        $this->assertSame([null, $disc->points], [$ring->ribbon, $ring->points]);
        $this->assertEqualsWithDelta(1.44, \hypot($along->points[1][0] - $along->points[0][0], $along->points[1][1] - $along->points[0][1]), 1e-9);
        $this->assertEqualsWithDelta(
            0.0,
            (($along->points[1][0] - $along->points[0][0]) * ($across->points[1][0] - $across->points[0][0]))
            + (($along->points[1][1] - $along->points[0][1]) * ($across->points[1][1] - $across->points[0][1])),
            1e-9,
            'a plus: two perpendicular strokes',
        );
    }
}
