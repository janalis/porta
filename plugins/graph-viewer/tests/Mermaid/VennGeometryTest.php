<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Graph\GraphExportFormat;
use Porta\GraphViewer\Graph\GraphNode;
use Porta\GraphViewer\Mermaid\MermaidScene;
use Porta\GraphViewer\Mermaid\MermaidSyntaxError;
use Porta\GraphViewer\Mermaid\VennDiagram;
use Porta\GraphViewer\Mermaid\VennGeometry;
use Porta\GraphViewer\Mermaid\VennLayout;
use Porta\GraphViewer\Mermaid\VennLexer;
use Porta\GraphViewer\Mermaid\VennParser;
use Porta\GraphViewer\Mermaid\VennRegion;
use Porta\GraphViewer\Mermaid\VennSymbols;

final class VennGeometryTest extends TestCase
{
    private static function parse(string $source): VennDiagram
    {
        $parser = new VennParser();
        foreach (new VennLexer($source)->statements() as $statement) {
            $parser->accept($statement);
        }

        return $parser->finish();
    }

    private static function scene(string $source): MermaidScene
    {
        $work = new VennLayout()->layout(self::parse($source));
        \iterator_to_array($work);

        return $work->getReturn();
    }

    public function testCircleAreaRatiosAndRequestedOverlapSurviveScaling(): void
    {
        $source = self::parse("venn-beta\nset A: 40\nset B: 10\nunion A,B: 5");
        $work = VennGeometry::layout($source);
        $this->assertNotEmpty(\iterator_to_array($work));
        $circles = $work->getReturn();
        $this->assertEqualsWithDelta(2, $circles['A']->w / $circles['B']->w, delta: 1.0e-9);
        $distance = \hypot($circles['A']->x - $circles['B']->x, $circles['A']->y - $circles['B']->y);
        $area = VennGeometry::overlap($circles['A']->w / 2, $circles['B']->w / 2, $distance);
        $this->assertEqualsWithDelta(0.5, $area / (\M_PI * (($circles['B']->w / 2) ** 2)), delta: 1.0e-6);
        $repeat = VennGeometry::layout($source);
        \iterator_to_array($repeat);
        $this->assertEquals($circles, $repeat->getReturn());
    }

    public function testOverlapBoundaryCasesAndInverse(): void
    {
        $this->assertSame(0.0, VennGeometry::overlap(2, 3, 6));
        $this->assertSame(0.0, VennGeometry::overlap(2, 3, 5));
        $this->assertEqualsWithDelta(4 * \M_PI, VennGeometry::overlap(2, 3, 0), delta: 1.0e-12);
        $this->assertSame(0.0, VennGeometry::overlap(0, 3, 2));
        $this->assertEqualsWithDelta(5, VennGeometry::distance(2, 3, 0), delta: 1.0e-12);
        $this->assertEqualsWithDelta(1, VennGeometry::distance(2, 3, 20), delta: 1.0e-12);
        $this->assertEqualsWithDelta(3, VennGeometry::overlap(2, 3, VennGeometry::distance(2, 3, 3)), delta: 1.0e-10);
    }

    public function testSyntheticPairsDoNotMutateTheAuthoredModel(): void
    {
        $source = self::parse("venn-beta\nset A\nset B\nset C\nunion A,B,C");
        $before = $source->subsets;
        $work = VennGeometry::layout($source);
        \iterator_to_array($work);
        $this->assertSame($before, $source->subsets);
        $circles = $work->getReturn();
        $distance = \hypot($circles['A']->x - $circles['B']->x, $circles['A']->y - $circles['B']->y);
        $this->assertLessThan($circles['A']->w, $distance);
        $this->assertGreaterThan($circles['A']->w / 2, $distance);
    }

    private static function circle(int $id, string $name, float $x, float $radius): GraphNode
    {
        return new GraphNode($id, $name, '', $x, 10, 2 * $radius, 2 * $radius, null, null, dashed: false);
    }

    public function testClippedIntersectionStaysInsideEveryCircle(): void
    {
        $circles = ['A' => self::circle(0, 'A', 10, 5), 'B' => self::circle(1, 'B', 15, 5)];
        $work = VennRegion::polygon($circles, ['A', 'B']);
        $this->assertNotEmpty(\iterator_to_array($work));
        $points = $work->getReturn();
        $this->assertGreaterThan(8, \count($points));
        foreach ($points as [$x, $y]) {
            foreach ($circles as $circle) {
                $this->assertLessThanOrEqual(5.000_001, \hypot($x - $circle->x, $y - $circle->y));
            }
        }
        $area = 0.0;
        foreach ($points as $index => [$x, $y]) {
            $next = $points[($index + 1) % \count($points)];
            $area += ($x * $next[1]) - ($y * $next[0]);
        }
        $this->assertEqualsWithDelta(VennGeometry::overlap(5, 5, 5), \abs($area) / 2, delta: 0.2);
        $center = VennRegion::center($circles, ['A', 'B']);
        \iterator_to_array($center);
        $this->assertEqualsWithDelta(12.5, $center->getReturn()['x'], delta: 0.001);
        $exclusive = VennRegion::center($circles, ['A']);
        \iterator_to_array($exclusive);
        $this->assertLessThan(10, $exclusive->getReturn()['x']);
        $disjoint = VennRegion::polygon(['A' => $circles['A'], 'B' => self::circle(1, 'B', 30, 5)], ['A', 'B']);
        \iterator_to_array($disjoint);
        $this->assertSame([], $disjoint->getReturn());
    }

    public function testOnlyAuthoredCaptionsAreSelectableAndTitlesTranslateClippedGeometry(): void
    {
        $body = "set A [Alpha]\n  text item [First]\nset B\nunion A,B\nstyle A,B fill:#f008,stroke:#000,fill-opacity:0.5";
        $plain = self::scene("venn-beta\n".$body);
        $titled = self::scene("venn-beta\ntitle Example\n".$body);
        $this->assertCount(4, $plain->selectionGraph->nodes);
        $this->assertNotEmpty($plain->legend);
        $this->assertSame('A', $plain->selectionGraph->nodes[$plain->onLine(1)]->name);
        $this->assertSame('item', $plain->selectionGraph->nodes[$plain->onLine(2)]->name);
        $this->assertSame('A & B', $plain->selectionGraph->nodes[$plain->onLine(4)]->name);
        $hidden = $plain->onLine(4);
        $this->assertSame([], VennSymbols::marks($plain->graph->nodes[$hidden], $plain->shapes[$hidden]));
        $regions = [];
        foreach ([$plain, $titled] as $scene) {
            foreach ($scene->shapes as $id => $shape) {
                if (!\str_starts_with($shape, 'venn-region:')) {
                    continue;
                }
                $node = $scene->graph->nodes[$id];
                $this->assertNull($scene->selectable($id));
                $mark = VennSymbols::marks($node, $shape)[0];
                $this->assertEqualsWithDelta((136 / 255) * 0.5, $mark->fillOpacity, delta: 1.0e-12);
                $this->assertSame('#ff0000', $mark->fill);
                $regions[] = [$node, $mark];
            }
        }
        $this->assertCount(2, $regions);
        $dy = $regions[1][0]->y - $regions[0][0]->y;
        $this->assertGreaterThan(0, $dy);
        $this->assertEqualsWithDelta($dy, $regions[1][1]->points[0][1] - $regions[0][1]->points[0][1], delta: 1.0e-10);
    }

    public function testDegenerateRegionsUseReadableOutsideCaptionsAndIgnoreUnknownTextTargets(): void
    {
        $source = "venn-beta\nset A:0\nset B\nunion A,B:0\ntext Missing item [Ignored]\nstyle A,B fill:red";
        $scene = self::scene($source);
        $this->assertCount(3, $scene->selectionGraph->nodes);
        $this->assertNotEmpty($scene->graph->edges);
        foreach ($scene->graph->edges as $edge) {
            $this->assertTrue($edge->decoration);
        }
        foreach ($scene->shapes as $shape) {
            $this->assertStringStartsNotWith('venn-region:', $shape);
        }
        $empty = self::scene('venn-beta');
        $this->assertSame([], $empty->graph->nodes);
    }

    public function testStylesMergeByPropertyAndRgbAlphaIsPreservedInExport(): void
    {
        $source = "venn-beta\nset A\n  text message [Hello]\nstyle A fill:rgba(10,20,30,0.5),stroke-width:2px\nstyle A stroke:#1234\nstyle message color:transparent";
        $scene = self::scene($source);
        $circle = $scene->graph->nodes[0];
        $mark = VennSymbols::marks($circle, $scene->shapes[0])[0];
        $this->assertSame('#0a141e', $mark->fill);
        $this->assertEqualsWithDelta(0.05, $mark->fillOpacity, delta: 1.0e-12);
        $this->assertEqualsWithDelta(0.2, $mark->strokeWidth, delta: 1.0e-12);
        $this->assertSame('#11223341', $mark->stroke);
        $this->assertStringContainsString('Hello', NativeFixtureExport::render($source, GraphExportFormat::Svg));
    }

    public function testIndentationCommentsUnicodeNamesAndImplicitTextStaySourceLinked(): void
    {
        $source = "venn-beta\r\nset \"😀\" [Emoji]\r\n  %% comment\r\n  text \"0\" [Zero]\r\nset \"\"\r\nunion \"\",\"😀\" [Both]\r\ntext \"😀\" named [Explicit]";
        $this->assertNotEmpty(NativeFixtureRows::render($source, width: 80)->rows);
        $diagram = self::parse($source);
        $this->assertSame(['😀', ''], $diagram->subsets[2]['sets']);
        $this->assertSame(['😀'], $diagram->texts[0]['sets']);
        $this->assertSame('0', $diagram->texts[0]['id']);
        $this->assertSame(3, $diagram->texts[0]['statement']->line);
        $this->assertSame('named', $diagram->texts[1]['id']);
    }

    public function testUnknownUnionIsRejectedBeforeAnyLayoutWork(): void
    {
        $this->expectException(MermaidSyntaxError::class);
        $this->expectExceptionMessageIsOrContains('Unknown Venn set identifier B');
        self::parse("venn-beta\nset A\nunion A,B");
    }

    public function testQuotedNumericSetNamesSurvivePhpArrayKeyCoercion(): void
    {
        $scene = self::scene('venn-beta'."\n".'set "0"'."\n".'set "1"'."\n".'union "0","1"');
        $this->assertCount(3, $scene->selectionGraph->nodes);
        $this->assertSame(['0', '1', '0 & 1'], \array_column($scene->selectionGraph->nodes, 'name'));
    }

    public static function invalid(): iterable
    {
        yield 'header' => ['set A', 'header'];
        yield 'empty' => ['', 'header'];
        yield 'repeat header' => ["venn-beta\nvenn-beta", 'header'];
        yield 'unknown keyword' => ["venn-beta\nother", 'statement'];
        yield 'unknown union' => ["venn-beta\nunion A,B", 'Unknown'];
        yield 'one member' => ["venn-beta\nset A\nunion A", 'multiple'];
        yield 'negative' => ["venn-beta\nset A: -1", 'negative'];
        yield 'oversize' => ["venn-beta\nset A: 1000000000001", 'trillion'];
        yield 'missing id' => ["venn-beta\nset", 'Incomplete'];
        yield 'invalid id' => ["venn-beta\nset 22", 'identifier'];
        yield 'missing size' => ["venn-beta\nset A: x", 'finite'];
        yield 'invalid token' => ["venn-beta\n@", 'token'];
        yield 'bad style key' => ["venn-beta\nstyle A 33:red", 'property'];
        yield 'bad style value' => ["venn-beta\nstyle A fill:[red]", 'value'];
        yield 'unsupported css' => ["venn-beta\nset A\nstyle A foo:red", 'Unsupported'];
        yield 'bad opacity' => ["venn-beta\nset A\nstyle A fill-opacity:2", 'opacity'];
        yield 'bad rgb' => ["venn-beta\nset A\nstyle A fill:rgb(1,2,300)", 'channels'];
        yield 'bad rgba' => ["venn-beta\nset A\nstyle A fill:rgba(1,2,3,2)", 'alpha'];
        yield 'text id' => ["venn-beta\ntext A [invalid]", 'identifier'];
        yield 'token length' => ["venn-beta\nset \"".\str_repeat('x', times: 65_537).'"', 'limit'];
        yield 'rgb components' => ["venn-beta\nset A\nstyle A fill:\"rgb(1,2)\"", 'RGB'];
        yield 'rgb numeric' => ["venn-beta\nset A\nstyle A fill:\"rgb(a,2,3)\"", 'RGB'];
        yield 'hex eight' => ["venn-beta\nset A\nstyle A stroke-width:bad", 'stroke width'];
        yield 'trailing' => ["venn-beta\nset A extra", 'Unexpected'];
        yield 'field bound' => ["venn-beta\nstyle ".\str_repeat('A,', times: 4096).'A fill:red', 'field'];
        yield 'declaration bound' => ["venn-beta\n".\str_repeat("set A\n", times: 4097), 'declaration'];
        yield 'token bound' => ['venn-beta'.\str_repeat(" \n", times: 65_537), 'limit'];
        yield 'circles' => ["venn-beta\n".\implode("\n", \array_map(static fn (int $i): string => 'set A'.$i, \range(0, end: 16))), 'sixteen'];
    }

    #[DataProvider('invalid')]
    public function testInvalidSourcesHaveLocatedDiagnostics(string $source, string $message): void
    {
        $this->expectException(MermaidSyntaxError::class);
        $this->expectExceptionMessageIsOrContains($message);
        self::scene($source);
    }
}
