<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Graph\GraphExportFormat;
use Porta\GraphViewer\Graph\GraphNode;
use Porta\GraphViewer\Mermaid\FlowchartLayout;
use Porta\GraphViewer\Mermaid\FlowchartParser;
use Porta\GraphViewer\Mermaid\FlowchartShapeGeometry;
use Porta\GraphViewer\Mermaid\FlowchartShapes;
use Porta\GraphViewer\Mermaid\MermaidDrawing;
use Porta\GraphViewer\Mermaid\MermaidLexer;
use Porta\GraphViewer\Mermaid\MermaidMark;
use Porta\GraphViewer\Mermaid\MermaidOutline;
use Porta\GraphViewer\Mermaid\MermaidScene;
use Porta\GraphViewer\Mermaid\MermaidSyntaxError;

final class FlowchartModernShapesTest extends TestCase
{
    private static function scene(string $source): MermaidScene
    {
        $parser = new FlowchartParser();
        foreach (new MermaidLexer($source)->statements() as $statement) {
            $parser->accept($statement);
        }
        $work = new FlowchartLayout()->layout($parser->finish());
        \iterator_to_array($work);

        return $work->getReturn();
    }

    public static function shapes(): iterable
    {
        $rows = \json_decode(\file_get_contents(__DIR__.'/../fixtures/mermaid/flowchart-shapes.json'), associative: true, flags: \JSON_THROW_ON_ERROR);
        foreach ($rows as $row) {
            foreach (\array_unique([$row['name'], ...$row['aliases']]) as $alias) {
                yield $alias => [$row['name'], $alias];
            }
        }
    }

    #[DataProvider('shapes')]
    public function testEveryPinnedNameUsesItsRegisteredOutlineAndPreservesAuthoredPaint(string $name, string $alias): void
    {
        $this->assertTrue(FlowchartShapes::known($alias));
        $canonical = MermaidOutline::canonical($name);
        $this->assertSame($canonical, MermaidOutline::canonical($alias));
        $prefix = 'flowchart LR; A@{shape:';
        $suffix = ',label:"Sample"} --> B[End]; style A fill:#fff,stroke:#123456,stroke-width:3';
        $source = $prefix.$alias.$suffix;
        $scene = self::scene($source);
        $node = $scene->graph->nodes[0];
        $this->assertSame('Sample', $node->label);
        $this->assertSame(0, $scene->origin($node->id));
        $this->assertSame('#ffffff', $node->fillColor);
        $this->assertSame('#123456', $node->color);
        $this->assertEqualsWithDelta(0.3, $node->strokeWidth, delta: 1.0e-12);
        $marks = MermaidOutline::marks($node, $alias);
        $this->assertEquals(MermaidOutline::marks($node, $name), $marks);
        $this->assertSame(NativeFixtureExport::render($prefix.$name.$suffix, GraphExportFormat::Svg), NativeFixtureExport::render($source, GraphExportFormat::Svg));
        $this->assertFalse(MermaidOutline::contains($node, $alias, $node->x + $node->w, $node->y));
        $this->assertNotEmpty(NativeFixtureRows::render($source, width: 100)->rows);
    }

    public function testPrimitiveLabelsRemainSearchableWithoutPaintingOverTheirSymbols(): void
    {
        foreach (['hourglass', 'bolt', 'cross-circ', 'fork', 'sm-circ', 'f-circ', 'fr-circ'] as $shape) {
            $source = 'flowchart; A@{shape:'.$shape.',label:"Hidden semantic name"}';
            $scene = self::scene($source);
            $node = $scene->selectionGraph->nodes[0];
            $this->assertSame('Hidden semantic name', $node->label);
            $this->assertLessThanOrEqual(12, $node->w);
            $this->assertLessThanOrEqual(12, $node->h);
            $svg = NativeFixtureExport::render($source, GraphExportFormat::Svg);
            $this->assertStringNotContainsString('>Hidden semantic name</text>', $svg);
            $this->assertTrue(MermaidOutline::contains($node, $shape, $node->x, $node->y));
        }
        $horizontal = self::scene('flowchart LR; A@{shape:fork}')->graph->nodes[0];
        $this->assertGreaterThan($horizontal->w, $horizontal->h);
    }

    public function testInternalBordersAndMarkersUseAuthoredStrokeAndNoPaintSurvivesFillNone(): void
    {
        foreach (['lin-rect', 'div-rect', 'win-pane', 'lin-cyl', 'h-cyl', 'tag-doc', 'tag-rect', 'browser', 'console', 'bucket', 'fr-circ'] as $shape) {
            $source = 'flowchart; A@{shape:'.$shape.',label:"Sample"}; style A fill:none,stroke:#654321,stroke-width:4,stroke-dasharray:4';
            $scene = self::scene($source);
            $marks = MermaidOutline::marks($scene->graph->nodes[0], $shape);
            $this->assertGreaterThanOrEqual(2, \count($marks));
            foreach ($marks as $mark) {
                $this->assertSame('#654321', $mark->stroke);
                $this->assertTrue($mark->dashed);
                $this->assertSame('none', $mark->fill);
                $this->assertEqualsWithDelta(0.4, $mark->strokeWidth, delta: 1.0e-12);
            }
        }
    }

    public function testStackedLayersStayInTheirHitBoundsAndRetainThreePaintedDocuments(): void
    {
        foreach (['docs', 'st-rect'] as $shape) {
            $scene = self::scene('flowchart; A@{shape:'.$shape.',label:"Sample"}');
            $node = $scene->graph->nodes[0];
            $marks = MermaidOutline::marks($node, $shape);
            $this->assertCount(3, $marks);
            foreach ($marks as $mark) {
                foreach ($mark->points as [$x, $y]) {
                    $this->assertGreaterThanOrEqual($node->x - ($node->w / 2) - 0.001, $x);
                    $this->assertLessThanOrEqual($node->x + ($node->w / 2) + 0.001, $x);
                    $this->assertGreaterThanOrEqual($node->y - ($node->h / 2) - 0.001, $y);
                    $this->assertLessThanOrEqual($node->y + ($node->h / 2) + 0.001, $y);
                }
            }
        }
    }

    public function testNewCurvedAndNotchedSilhouettesRejectEmptyCornersAndReceiveEdgesAtTheirBoundary(): void
    {
        foreach (['delay', 'curv-trap', 'h-cyl', 'lin-cyl', 'bucket', 'folder', 'person', 'notch-pent', 'doc', 'bow-rect'] as $shape) {
            $node = new GraphNode(0, 'A', 'Text', 20, 20, 16, 12, null, null, dashed: false);
            $points = MermaidOutline::points($node, $shape);
            $this->assertNotEmpty($points);
            [$cornerX, $cornerY] = match ($shape) {
                'doc' => [28.0, 26.0],
                'bow-rect' => [12.0, 14.0],
                default => [28.0, 14.0],
            };
            $this->assertFalse(MermaidOutline::contains($node, $shape, $cornerX, $cornerY));
            [$x, $y] = MermaidOutline::boundary($node, $shape, 40, 20);
            $this->assertEqualsWithDelta(20, $y, delta: 1.0e-9);
            $this->assertGreaterThan(20, $x);
            $this->assertLessThanOrEqual(28.001, $x);
        }
    }

    public function testWindowAndPersonTextStayClearOfTheirHeaders(): void
    {
        foreach (['folder', 'bucket', 'browser', 'console', 'person'] as $shape) {
            $scene = self::scene('flowchart; A@{shape:'.$shape.',label:"Text"}');
            $drawing = MermaidDrawing::graph($scene->graph, $scene->shapes);
            \iterator_to_array($drawing);
            $text = \array_find($drawing->getReturn()->marks, static fn ($mark): bool => 'text' === $mark->kind && 'Text' === $mark->text);
            $this->assertInstanceOf(MermaidMark::class, $text);
            $this->assertGreaterThan($scene->graph->nodes[0]->y, $text->points[0][1]);
        }
    }

    public function testCylinderCaptionsClearRingsAndCapsMeetTheirOwnSilhouette(): void
    {
        foreach (['Text', 'First<br/>Second<br/>Third<br/>Fourth'] as $label) {
            $scene = self::scene('flowchart; A@{shape:lin-cyl,label:"'.$label.'"}');
            $node = $scene->graph->nodes[0];
            $outline = MermaidOutline::marks($node, 'lin-cyl');
            $ringBottom = \max(\array_column($outline[3]->points, 1));
            $work = MermaidDrawing::graph($scene->graph, $scene->shapes);
            \iterator_to_array($work);
            $labels = \array_filter($work->getReturn()->marks, static fn (MermaidMark $mark): bool => 'text' === $mark->kind);
            foreach ($labels as $text) {
                $this->assertGreaterThan($ringBottom, $text->points[0][1] - 1.5);
            }
        }
        $horizontal = self::scene('flowchart; A@{shape:h-cyl,label:"Cylinder"}')->graph->nodes[0];
        $marks = MermaidOutline::marks($horizontal, 'h-cyl');
        $left = \min(\array_column($marks[0]->points, 0));
        $capLeft = \min(\array_column($marks[1]->points, 0));
        $this->assertEqualsWithDelta($left, $capLeft, delta: 1.0e-12);
    }

    public function testBracesHaveOneCentralCuspAndMirrorTheirComment(): void
    {
        $node = new GraphNode(0, 'A', 'Text', 20, 20, 16, 12, null, null, dashed: false);
        $left = MermaidOutline::marks($node, 'brace')[0]->points;
        $right = MermaidOutline::marks($node, 'brace-r')[0]->points;
        $cusp = \array_find($left, static fn (array $point): bool => 20.0 === $point[1]);
        $this->assertNotNull($cusp);
        $this->assertSame(\min(\array_column($left, 0)), $cusp[0]);
        foreach ($left as $index => [$x, $y]) {
            $this->assertEqualsWithDelta(40 - $x, $right[$index][0], delta: 1.0e-12);
            $this->assertSame($y, $right[$index][1]);
        }
    }

    public function testPersonHeadsStayCircularAndLongStyledLabelsStayInTheBody(): void
    {
        foreach ([12.0, 40.0] as $height) {
            $node = new GraphNode(0, 'A', 'Text', 20, 30, 16, $height, null, null, dashed: false);
            $head = \array_slice(MermaidOutline::points($node, 'person'), offset: 0, length: 25);
            $centerY = 30 - ($height / 2) + 3.68;
            foreach ($head as [$x, $y]) {
                $this->assertEqualsWithDelta(3.68, \hypot($x - 20, $y - $centerY), delta: 1.0e-10);
            }
        }
        $source = 'flowchart; A@{shape:person,label:"'.\implode('<br/>', \array_fill(0, count: 12, value: 'Line')).'"}; style A font-size:24px';
        $scene = self::scene($source);
        $node = $scene->graph->nodes[0];
        $work = MermaidDrawing::graph($scene->graph, $scene->shapes);
        \iterator_to_array($work);
        $bodyTop = $node->y - ($node->h / 2) + FlowchartShapeGeometry::headBand($node);
        foreach ($work->getReturn()->marks as $mark) {
            if ('text' !== $mark->kind) {
                continue;
            }
            $this->assertGreaterThan($bodyTop, $mark->points[0][1] - (1.5 * $mark->fontScale));
            $this->assertLessThan($node->y + ($node->h / 2), $mark->points[0][1] + (0.5 * $mark->fontScale));
        }
    }

    public function testDocumentsAndPaperTapeKeepDistinctWaveEdges(): void
    {
        $node = new GraphNode(0, 'A', 'Text', 20, 20, 16, 12, null, null, dashed: false);
        $document = MermaidOutline::points($node, 'doc');
        $tape = MermaidOutline::points($node, 'flag');
        $this->assertGreaterThan(4, \count(\array_unique(\array_column($document, 1))));
        $this->assertEqualsWithDelta(0, $document[0][1] - $document[16][1], delta: 1.0e-10);
        $this->assertGreaterThan(0.5, \abs($tape[0][1] - $tape[8][1]));
        $this->assertFalse(MermaidOutline::contains($node, 'bow-rect', 28, 20));
        $this->assertTrue(MermaidOutline::contains($node, 'bow-rect', 12, 20));
    }

    public function testJunctionAndForkDefaultsStayFilledWhileUnfilledStylesRemainExplicit(): void
    {
        foreach (['sm-circ', 'f-circ', 'fork'] as $shape) {
            $scene = self::scene('flowchart; A@{shape:'.$shape.'}');
            $this->assertSame('#333333', MermaidOutline::marks($scene->graph->nodes[0], $shape)[0]->fill);
        }
    }

    public function testUnknownShapeIsRejectedRatherThanSubstituted(): void
    {
        $this->expectException(MermaidSyntaxError::class);
        $this->expectExceptionMessageIsOrContains('Unknown flowchart shape imaginary');
        self::scene('flowchart; A@{shape:imaginary}');
    }
}
