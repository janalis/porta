<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Graph\GraphEdge;
use Porta\GraphViewer\Graph\GraphExportFormat;
use Porta\GraphViewer\Graph\GraphNode;
use Porta\GraphViewer\Mermaid\FlowchartParser;
use Porta\GraphViewer\Mermaid\MermaidCurve;
use Porta\GraphViewer\Mermaid\MermaidCurves;
use Porta\GraphViewer\Mermaid\MermaidLexer;
use Porta\GraphViewer\Mermaid\MermaidScene;
use Porta\GraphViewer\Mermaid\MermaidSyntaxError;

final class FlowchartCurvesTest extends TestCase
{
    /**
     * @param list<array{float, float}> $points
     * @param list<array{float, float}> $expected
     */
    #[DataProvider('reference')]
    public function testSamplesMatchD3ShapeAndThePinnedRoundedCorners(string $curve, array $points, array $expected): void
    {
        $sampled = MermaidCurves::path($points, MermaidCurve::from($curve))->points;
        $this->assertCount(\count($expected), $sampled);
        foreach ($expected as $index => [$x, $y]) {
            $this->assertEqualsWithDelta($x, $sampled[$index][0], delta: 1e-9);
            $this->assertEqualsWithDelta($y, $sampled[$index][1], delta: 1e-9);
        }
    }

    public static function reference(): iterable
    {
        $reference = \json_decode((string) \file_get_contents(__DIR__.'/../fixtures/mermaid/flowchart-curves-reference.json'), associative: true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($reference);
        self::assertSame('21f72f07ea22c0af48a3149c550654e80d8e40cb', $reference['mermaidCommit']);
        self::assertSame(32, $reference['steps']);
        self::assertIsArray($reference['cases']);
        foreach ($reference['cases'] as $case) {
            self::assertIsArray($case);
            $points = \array_map(static fn (array $point): array => [(float) $point[0], (float) $point[1]], $case['points']);
            $expected = \array_map(static fn (array $point): array => [(float) $point[0], (float) $point[1]], $case['expected']);
            yield (string) $case['name'] => [(string) $case['curve'], $points, $expected];
        }
    }

    #[DataProvider('directions')]
    public function testUnconfiguredEdgesUseBasisThroughOneMidRankWaypoint(string $direction): void
    {
        $body = 'flowchart '.$direction."\nA-->B\nA-->C";
        $horizontal = \in_array($direction, ['LR', 'RL'], strict: true);
        $linear = NativeFixtureScene::render(self::configured('linear', $body));
        $a = self::node($linear, 'A');
        $c = self::node($linear, 'C');
        $edge = $linear->graph->edges[1];
        $this->assertCount(3, $edge->points);
        $middle = $horizontal
            ? [($a->x + ($c->x > $a->x ? $a->w / 2 : -$a->w / 2) + $c->x - ($c->x > $a->x ? $c->w / 2 : -$c->w / 2)) / 2, $c->y]
            : [$c->x, ($a->y + ($c->y > $a->y ? $a->h / 2 : -$a->h / 2) + $c->y - ($c->y > $a->y ? $c->h / 2 : -$c->h / 2)) / 2];
        $this->assertEqualsWithDelta($middle[0], $edge->points[1][0], delta: 1e-9);
        $this->assertEqualsWithDelta($middle[1], $edge->points[1][1], delta: 1e-9);
        $basis = NativeFixtureScene::render($body)->graph->edges[1];
        $this->assertGreaterThan(3, \count($basis->points));
        $this->assertSame($edge->points[0], $basis->points[0]);
        // The final basis piece enters the target along the rank axis.
        [$before, $last] = \array_slice($basis->points, offset: -2);
        $cross = $horizontal ? 1 : 0;
        $this->assertEqualsWithDelta($horizontal ? $c->y : $c->x, $before[$cross], delta: 1e-9);
        $this->assertEqualsWithDelta($horizontal ? $c->y : $c->x, $last[$cross], delta: 1e-9);
        $this->assertNotNull($basis->arrowTip);
        $this->assertEqualsWithDelta($horizontal ? $c->y : $c->x, $basis->arrowTip[$cross], delta: 1e-9);
    }

    public static function directions(): iterable
    {
        foreach (['TD', 'BT', 'LR', 'RL'] as $direction) {
            yield $direction => [$direction];
        }
    }

    public function testEdgesWithoutARankGapStayStraight(): void
    {
        $scene = NativeFixtureScene::render("flowchart TD\nsubgraph S\nA\nend\nA-->S");
        $this->assertCount(2, $scene->graph->edges[0]->points);
    }

    public function testEdgeInterpolationPrecedesTheDefaultLinkStyleWhichPrecedesConfiguration(): void
    {
        $plain = "flowchart TD\nA-->B & C & D\nB e2@--> E";
        $body = $plain."\nlinkStyle default interpolate stepAfter\nlinkStyle 1 interpolate linear stroke:red\ne2@{ curve: \"bumpY\" }";
        $scene = NativeFixtureScene::render(self::configured('step', $body));
        $expected = [0 => 'stepAfter', 1 => 'linear', 2 => 'stepAfter', 3 => 'bumpY'];
        foreach ($expected as $index => $curve) {
            $this->assertSame(self::points(self::configured($curve, $plain), $index), $scene->graph->edges[$index]->points, $curve);
        }
        $this->assertSame('#ff0000', $scene->graph->edges[1]->color);
        $this->assertNull($scene->graph->edges[0]->color);
        $this->assertNotSame(self::points(self::configured('step', "flowchart TD\nA-->B & C & D"), 2), $scene->graph->edges[2]->points);
        $configured = NativeFixtureScene::render(self::configured('stepBefore', "flowchart TD\nA-->B & C"));
        $this->assertSame(self::points(self::configured('stepBefore', "flowchart TD\nA-->B & C\nlinkStyle 0 stroke:red"), 1), $configured->graph->edges[1]->points);
    }

    public function testEdgeMetadataNeverCreatesAVertexAndUnknownIdsRemainVertices(): void
    {
        $scene = NativeFixtureScene::render("flowchart TD\nA e1@--> B\ne1@{ curve: linear }\nlate@{ shape: circle }");
        $names = \array_map(static fn (GraphNode $node): string => $node->name, $scene->graph->nodes);
        $this->assertEqualsCanonicalizing(['A', 'B', 'late'], \array_values($names));
        $this->assertCount(3, $scene->graph->edges[0]->points);
    }

    #[DataProvider('fallbacks')]
    public function testUnknownAbsentAndNonStringCurvesUseTheBasisDefault(string $source): void
    {
        $plain = NativeFixtureScene::render("flowchart TD\nA-->B\nA-->C");
        $this->assertSame($plain->graph->edges[1]->points, NativeFixtureScene::render($source)->graph->edges[1]->points);
    }

    public static function fallbacks(): iterable
    {
        yield 'configured name' => [self::configured('nope', "flowchart TD\nA-->B\nA-->C")];
        yield 'non-string configuration' => ["---\nconfig:\n  flowchart: {curve: 5}\n---\nflowchart TD\nA-->B\nA-->C"];
        yield 'later non-string replaces' => [self::configured('linear', "%%{init: {\"flowchart\": {\"curve\": false}}}%%\nflowchart TD\nA-->B\nA-->C")];
        yield 'interpolated name' => ["flowchart TD\nA-->B\nA-->C\nlinkStyle 1 interpolate nope"];
        yield 'metadata name' => ["flowchart TD\nA-->B\nA e@-->C\ne@{curve: nope}"];
    }

    public function testEmptyInterpolationFallsThroughToTheConfiguredCurve(): void
    {
        $configured = NativeFixtureScene::render(self::configured('linear', "flowchart TD\nA-->B\nA-->C"));
        $empty = NativeFixtureScene::render(self::configured('linear', "flowchart TD\nA-->B\nA e@-->C\ne@{curve: \"\"}"));
        $this->assertSame($configured->graph->edges[1]->points, $empty->graph->edges[1]->points);
    }

    public function testInterpolationAloneKeepsEarlierEdgeCss(): void
    {
        $scene = NativeFixtureScene::render("flowchart TD\nA-->B\nlinkStyle 0 stroke:red\nlinkStyle 0 interpolate linear\nlinkStyle default interpolate step");
        $this->assertSame('#ff0000', $scene->graph->edges[0]->color);
    }

    public function testZeroRankSpacingKeepsATwoPointEdge(): void
    {
        $scene = NativeFixtureScene::render("---\nconfig:\n  flowchart: {rankSpacing: 0}\n---\nflowchart TD\nA-->B");
        $this->assertCount(2, $scene->graph->edges[0]->points);
    }

    public function testSubgraphRoutesFollowTheirLocalDirection(): void
    {
        $scene = NativeFixtureScene::render(self::configured('linear', "flowchart TD\nsubgraph S\ndirection LR\nA-->B\nA-->C\nend"));
        $a = self::node($scene, 'A');
        $c = self::node($scene, 'C');
        $this->assertEqualsWithDelta([($a->x + ($a->w / 2) + $c->x - ($c->w / 2)) / 2, $c->y], $scene->graph->edges[1]->points[1], delta: 1e-9);
    }

    public function testInterpolationTargetsAreBoundsChecked(): void
    {
        $this->expectException(MermaidSyntaxError::class);
        $this->expectExceptionMessageIsOrContains('out of bounds');
        $parser = new FlowchartParser();
        foreach (new MermaidLexer("flowchart TD\nA-->B\nlinkStyle 1 interpolate basis")->statements() as $statement) {
            $parser->accept($statement);
        }
    }

    #[DataProvider('directions')]
    public function testEdgesAcrossRanksReserveALaneThroughEveryIntermediateRank(string $direction): void
    {
        $body = 'flowchart '.$direction."\nA-->B-->C-->D\nA-->D";
        $scene = NativeFixtureScene::render(self::configured('linear', $body));
        $edge = $scene->graph->edges[3];
        $b = self::node($scene, 'B');
        $c = self::node($scene, 'C');
        $d = self::node($scene, 'D');
        // Start, two points per intermediate rank band, the half rank before the target, the end.
        $this->assertCount(7, $edge->points);
        $horizontal = \in_array($direction, ['LR', 'RL'], strict: true);
        $axis = $horizontal ? 0 : 1;
        $sign = \in_array($direction, ['BT', 'RL'], strict: true) ? -1 : 1;
        for ($index = 1; $index < 7; ++$index) {
            $this->assertGreaterThan(0.0, ($edge->points[$index][$axis] - $edge->points[$index - 1][$axis]) * $sign);
        }
        foreach ([1 => $b, 3 => $c] as $index => $node) {
            [$center, $half] = $horizontal ? [$node->x, $node->w / 2] : [$node->y, $node->h / 2];
            $this->assertEqualsWithDelta($center - ($sign * $half), $edge->points[$index][$axis], delta: 1e-9);
            $this->assertEqualsWithDelta($center + ($sign * $half), $edge->points[$index + 1][$axis], delta: 1e-9);
        }
        $this->assertEqualsWithDelta($horizontal ? $d->y : $d->x, $edge->points[5][1 - $axis], delta: 1e-9);
        // The lane sits one unit inside its two-unit reservation, after the rank's ordinary node gap.
        $this->assertEqualsWithDelta(($horizontal ? $b->y + ($b->h / 2) : $b->x + ($b->w / 2)) + 7, $edge->points[1][1 - $axis], delta: 1e-9);
        foreach (MermaidCurves::path($edge->points, MermaidCurve::Linear)->points as $point) {
            foreach ([$b, $c] as $node) {
                $inside = \abs($point[0] - $node->x) < ($node->w / 2) && \abs($point[1] - $node->y) < ($node->h / 2);
                $this->assertFalse($inside, 'The reserved lane crosses '.$node->name);
            }
        }
        $basis = NativeFixtureScene::render($body)->graph->edges[3];
        foreach ($basis->points as $point) {
            foreach ([$b, $c] as $node) {
                $this->assertFalse(\abs($point[0] - $node->x) < ($node->w / 2) && \abs($point[1] - $node->y) < ($node->h / 2));
            }
        }
    }

    public function testFanInsKeepTheirSourcesCrossPositionWhileFanOutsKeepTheTargets(): void
    {
        $fanIn = NativeFixtureScene::render(self::configured('linear', "flowchart TD\nA-->E\nB-->E\nC-->E"));
        foreach (['A', 'B', 'C'] as $index => $name) {
            $this->assertSame(self::node($fanIn, $name)->x, $fanIn->graph->edges[$index]->points[1][0]);
        }
        $fanOut = NativeFixtureScene::render(self::configured('linear', "flowchart TD\nA-->B\nA-->C\nA-->D"));
        foreach (['B', 'C', 'D'] as $index => $name) {
            $this->assertSame(self::node($fanOut, $name)->x, $fanOut->graph->edges[$index]->points[1][0]);
        }
        $balanced = NativeFixtureScene::render(self::configured('linear', "flowchart TD\nA-->B\nA-->C\nD-->C"));
        $this->assertSame(self::node($balanced, 'C')->x, $balanced->graph->edges[1]->points[1][0]);
    }

    #[DataProvider('directions')]
    public function testSwimlanesRouteOrthogonallyWithRoundedBasisCorners(string $direction): void
    {
        $body = 'swimlane-beta '.$direction."\nsubgraph One\nA\nend\nsubgraph Two\nB\nend\nA-->B";
        $linear = NativeFixtureScene::render($body."\nlinkStyle 0 interpolate linear");
        $a = self::node($linear, 'A');
        $b = self::node($linear, 'B');
        $points = $linear->graph->edges[0]->points;
        $this->assertCount(4, $points);
        $horizontal = \in_array($direction, ['LR', 'RL'], strict: true);
        $cross = $horizontal ? 1 : 0;
        $this->assertSame([$horizontal ? $a->y : $a->x, $horizontal ? $a->y : $a->x], [$points[0][$cross], $points[1][$cross]]);
        $this->assertSame([$horizontal ? $b->y : $b->x, $horizontal ? $b->y : $b->x], [$points[2][$cross], $points[3][$cross]]);
        $this->assertSame($points[1][1 - $cross], $points[2][1 - $cross]);
        $rounded = NativeFixtureScene::render($body)->graph->edges[0]->points;
        $this->assertSame(MermaidCurves::path([$points[0], $points[1], $points[2], $linear->graph->edges[0]->arrowTip ?? $points[3]], MermaidCurve::Rounded)->points[1], $rounded[1]);
        $this->assertGreaterThan(4, \count($rounded));
        $this->assertSame($rounded, NativeFixtureScene::render($body."\nlinkStyle 0 interpolate rounded")->graph->edges[0]->points);
        $this->assertNotSame($rounded, NativeFixtureScene::render($body."\nlinkStyle 0 interpolate stepBefore")->graph->edges[0]->points);
    }

    public function testCurvedEdgesExportAsSampledPolylines(): void
    {
        $svg = NativeFixtureExport::render("flowchart TD\nA-->B\nA-->C", GraphExportFormat::Svg);
        $polylines = [];
        \preg_match_all('/<polyline points="([^"]+)"/', $svg, $polylines);
        $this->assertNotEmpty($polylines[1]);
        $this->assertGreaterThan(3, \max(\array_map(static fn (string $points): int => \count(\explode(' ', \trim($points))), $polylines[1])));
        $this->assertStringStartsWith("\x89PNG", NativeFixtureExport::render("flowchart TD\nA-->B\nA-->C", GraphExportFormat::Png));
    }

    /** @return list<array{float, float}> */
    private static function points(string $source, int $index): array
    {
        $edge = NativeFixtureScene::render($source)->graph->edges[$index];
        self::assertInstanceOf(GraphEdge::class, $edge);

        return $edge->points;
    }

    private static function configured(string $curve, string $body): string
    {
        return "---\nconfig:\n  flowchart: {curve: ".\json_encode($curve, flags: \JSON_THROW_ON_ERROR)."}\n---\n".$body;
    }

    private static function node(MermaidScene $scene, string $name): GraphNode
    {
        $node = \array_find($scene->graph->nodes, static fn (GraphNode $node): bool => $name === $node->name);
        self::assertInstanceOf(GraphNode::class, $node);

        return $node;
    }
}
