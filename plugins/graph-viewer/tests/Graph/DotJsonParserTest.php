<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Graph;

use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Graph\DotJsonParser;
use Porta\GraphViewer\Graph\GraphParseException;

final class DotJsonParserTest extends TestCase
{
    private static function fixture(string $name): string
    {
        $json = file_get_contents(\dirname(__DIR__, levels: 4).'/tests/Support/fixtures/graph/'.$name);
        \assert(false !== $json, description: 'fixture files are committed beside the suite');

        return $json;
    }

    public function testParsesARealSmallGraph(): void
    {
        // small.json: digraph G { rankdir=LR; A -> B [label="calls"]; B -> C; }, bb 0,0,259.75,36
        $layout = DotJsonParser::parse(self::fixture('small.json'));

        $this->assertSame(3, $layout->nodeCount());
        $this->assertSame(2, $layout->edgeCount());
        $this->assertSame([], $layout->clusters);
        $this->assertEqualsWithDelta(259.75, $layout->width, 0.001);
        $this->assertEqualsWithDelta(36.0, $layout->height, 0.001);

        $a = $layout->nodes[0];
        $this->assertSame('A', $a->name);
        $this->assertSame('A', $a->label, '\N resolves to the node name');
        $this->assertEqualsWithDelta(27.0, $a->x, 0.001);
        $this->assertEqualsWithDelta(18.0, $a->y, 0.001, 'y = 36 - 18: flipped to screen-down');
        $this->assertEqualsWithDelta(54.0, $a->w, 0.001, '0.75 inches = 54 points');
        $this->assertEqualsWithDelta(36.0, $a->h, 0.001);
        $this->assertNull($a->color);
        $this->assertFalse($a->dashed);

        $ab = $layout->edges[0];
        $this->assertSame(0, $ab->tail);
        $this->assertSame(1, $ab->head);
        $this->assertSame('calls', $ab->label);
        $this->assertNotNull($ab->labelPos);
        $this->assertNotNull($ab->arrowTip);
        $this->assertEqualsWithDelta(114.48, $ab->arrowTip[0], 0.01);
        $this->assertEqualsWithDelta(18.0, $ab->arrowTip[1], 0.01);
        $this->assertGreaterThanOrEqual(4, \count($ab->points), 'the cubic was flattened into samples');
        $this->assertEqualsWithDelta(54.435, $ab->points[0][0], 0.01, 'polyline starts at the spline start point');
        $this->assertLessThanOrEqual(min(array_column($ab->points, 0)), $ab->minX, 'bbox contains every polyline point');
        $this->assertGreaterThanOrEqual($ab->arrowTip[0], $ab->maxX, 'bbox contains the arrow tip beyond the last spline point');

        $bc = $layout->edges[1];
        $this->assertNull($bc->label, 'an empty label attribute means unlabeled');
        $this->assertNull($bc->labelPos);
    }

    public function testParsesClustersColorsAndStyles(): void
    {
        $layout = DotJsonParser::parse(self::fixture('clustered.json'));

        $this->assertSame(4, $layout->nodeCount());
        $this->assertSame(4, $layout->edgeCount());
        $this->assertCount(2, $layout->clusters);

        $app = $layout->clusters[0];
        $this->assertSame('cluster_app', $app->name);
        $this->assertSame('App', $app->label);
        $this->assertSame('#0000ff', $app->color);
        $this->assertLessThan($app->y1, $app->y0, 'bb normalized to y0 < y1 after the flip');
        $this->assertNotNull($app->labelPos);

        $api = $layout->nodes[2];
        $this->assertSame('#add8e6', $api->color, 'X11 lightblue');
        $this->assertSame('#ffcc00', $api->fillColor);
        $this->assertFalse($api->dashed);

        $repository = $layout->nodes[4];
        $this->assertSame('#ff0000', $repository->color);
        $this->assertTrue($repository->dashed, 'style=dashed');

        $uses = null;
        $dotted = null;
        foreach ($layout->edges as $edge) {
            if ('uses' === $edge->label) {
                $uses = $edge;
            }
            if ($edge->dashed) {
                $dotted = $edge;
            }
        }
        $this->assertNotNull($uses);
        $this->assertSame('#00aa00', $uses->color);
        $this->assertNotNull($dotted, 'style=dotted renders dashed');
    }

    public function testParsesTheEmptyGraph(): void
    {
        // empty.json has no objects/edges keys at all
        $layout = DotJsonParser::parse(self::fixture('empty.json'));

        $this->assertSame(0, $layout->nodeCount());
        $this->assertSame(0, $layout->edgeCount());
        $this->assertSame([], $layout->clusters);
        $this->assertSame(0.0, $layout->width);
    }

    public function testParsesASelfLoop(): void
    {
        $layout = DotJsonParser::parse(self::fixture('selfloop.json'));

        $edge = $layout->edges[0];
        $this->assertSame($edge->tail, $edge->head);
        $this->assertGreaterThan(2, \count($edge->points));
    }

    public function testNonNumericPositionThrows(): void
    {
        $this->expectException(GraphParseException::class);
        $this->expectExceptionMessageMatches('/non-numeric node X pos/');
        DotJsonParser::parse(self::fixture('malformed-pos.json'));
    }

    public function testGarbageInputThrows(): void
    {
        $this->expectException(GraphParseException::class);
        $this->expectExceptionMessageMatches('/invalid JSON/');
        DotJsonParser::parse(self::fixture('garbage.json'));
    }

    public function testNonObjectDocumentThrows(): void
    {
        $this->expectException(GraphParseException::class);
        $this->expectExceptionMessageMatches('/non-object/');
        DotJsonParser::parse('"just a string"');
    }

    public function testMissingBbThrows(): void
    {
        $this->expectException(GraphParseException::class);
        $this->expectExceptionMessageMatches('/missing graph bb/');
        DotJsonParser::parse('{"name": "G"}');
    }

    public function testWrongArityBbThrows(): void
    {
        $this->expectException(GraphParseException::class);
        $this->expectExceptionMessageMatches('/malformed graph bb/');
        DotJsonParser::parse('{"bb": "0,0,10"}');
    }

    public function testNonObjectGraphObjectThrows(): void
    {
        $this->expectException(GraphParseException::class);
        $this->expectExceptionMessageMatches('/object is not a JSON object/');
        DotJsonParser::parse('{"bb": "0,0,10,10", "objects": ["scalar"]}');
    }

    public function testNodeWithoutGvidThrows(): void
    {
        $this->expectException(GraphParseException::class);
        $this->expectExceptionMessageMatches('/lacks _gvid or name/');
        DotJsonParser::parse('{"bb": "0,0,10,10", "objects": [{"name": "A", "pos": "5,5", "width": "1", "height": "1"}]}');
    }

    public function testNonObjectEdgeThrows(): void
    {
        $this->expectException(GraphParseException::class);
        $this->expectExceptionMessageMatches('/edge is not a JSON object/');
        DotJsonParser::parse('{"bb": "0,0,10,10", "edges": ["scalar"]}');
    }

    public function testEdgeWithTooFewSplinePointsThrows(): void
    {
        $json =
            '{"bb": "0,0,10,10", "objects": ['
            .'{"_gvid": 0, "name": "A", "pos": "2,2", "width": "0.1", "height": "0.1"},'
            .'{"_gvid": 1, "name": "B", "pos": "8,8", "width": "0.1", "height": "0.1"}],'
            .'"edges": [{"tail": 0, "head": 1, "pos": "5,5"}]}';
        $this->expectException(GraphParseException::class);
        $this->expectExceptionMessageMatches('/fewer than two points/');
        DotJsonParser::parse($json);
    }

    public function testEdgeReferencingAMissingNodeIsSkipped(): void
    {
        // A compound edge clipped at a cluster boundary references the cluster's gvid.
        $json = '{"bb": "0,0,10,10", "objects": [{"_gvid": 0, "name": "A", "pos": "2,2", "width": "0.1", "height": "0.1"}], "edges": [{"tail": 0, "head": 99, "pos": "2,2 5,5"}]}';
        $layout = DotJsonParser::parse($json);

        $this->assertSame(0, $layout->edgeCount());
    }

    public function testNonSplineArityPointsAreUsedVerbatim(): void
    {
        // 3 points is not 3n+1 — the raw points become the polyline.
        $json =
            '{"bb": "0,0,10,10", "objects": ['
            .'{"_gvid": 0, "name": "A", "pos": "2,2", "width": "0.1", "height": "0.1"},'
            .'{"_gvid": 1, "name": "B", "pos": "8,8", "width": "0.1", "height": "0.1"}],'
            .'"edges": [{"tail": 0, "head": 1, "pos": "2,2 5,6 8,8", "label": "raw"}]}';
        $layout = DotJsonParser::parse($json);

        $edge = $layout->edges[0];
        $this->assertCount(3, $edge->points);
        $this->assertNull($edge->arrowTip);
        $this->assertSame('raw', $edge->label);
    }

    public function testTailArrowPrefixIsIgnoredAndPinSuffixTolerated(): void
    {
        $json =
            '{"bb": "0,0,20,20", "objects": ['
            .'{"_gvid": 0, "name": "A", "pos": "5,5!", "width": "0.1", "height": "0.1"},'
            .'{"_gvid": 1, "name": "B", "pos": "15,15", "width": "0.1", "height": "0.1"}],'
            .'"edges": [{"tail": 0, "head": 1, "pos": "s,5,5 e,15,15 6,6 14,14"}]}';
        $layout = DotJsonParser::parse($json);

        $this->assertEqualsWithDelta(5.0, $layout->nodes[0]->x, 0.001, 'the ! pin suffix is stripped');
        $edge = $layout->edges[0];
        $this->assertNotNull($edge->arrowTip);
        $this->assertCount(2, $edge->points, 's,x,y contributes no polyline point');
    }

    public function testMultilineLabelEscapesNormalize(): void
    {
        $json = '{"bb": "0,0,20,20", "objects": ['.'{"_gvid": 0, "name": "A", "label": "one\\\\ntwo\\\\lthree", "pos": "5,5", "width": "0.1", "height": "0.1"}]}';
        $layout = DotJsonParser::parse($json);

        $this->assertSame("one\ntwo\nthree", $layout->nodes[0]->label);
    }

    public function testNonClusterSubgraphAndClusterWithoutBbAreSkipped(): void
    {
        $json =
            '{"bb": "0,0,20,20", "_subgraph_cnt": 2, "objects": ['
            .'{"_gvid": 0, "name": "plain_group"},'
            .'{"_gvid": 1, "name": "cluster_nobb"},'
            .'{"_gvid": 2, "name": "A", "pos": "5,5", "width": "0.1", "height": "0.1"}]}';
        $layout = DotJsonParser::parse($json);

        $this->assertSame([], $layout->clusters);
        $this->assertSame(1, $layout->nodeCount());
    }
}
