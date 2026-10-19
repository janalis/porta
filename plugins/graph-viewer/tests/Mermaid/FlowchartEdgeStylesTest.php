<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Graph\GraphExportFormat;
use Porta\GraphViewer\Mermaid\FlowchartEdgeStyles;
use Porta\GraphViewer\Mermaid\FlowchartParser;
use Porta\GraphViewer\Mermaid\MermaidDrawing;
use Porta\GraphViewer\Mermaid\MermaidLexer;
use Porta\GraphViewer\Mermaid\MermaidMark;
use Porta\GraphViewer\Mermaid\MermaidScene;
use Porta\GraphViewer\Mermaid\MermaidStatement;
use Porta\GraphViewer\Mermaid\MermaidSyntaxError;
use Porta\GraphViewer\Mermaid\MermaidTitle;
use Porta\GraphViewer\Mermaid\RankedGraphLayout;
use Porta\GraphViewer\Render\GraphRenderer;
use Porta\ImageViewer\ZoomPanModel;
use Porta\Ui\Theme\Styles;

final class FlowchartEdgeStylesTest extends TestCase
{
    private static function scene(string $source): MermaidScene
    {
        $parser = new FlowchartParser();
        foreach (new MermaidLexer($source)->statements() as $statement) {
            $parser->accept($statement);
        }
        $work = new RankedGraphLayout()->layout($parser->finish());
        \iterator_to_array($work);

        return $work->getReturn();
    }

    public function testIndexedStylesOverrideDefaultsAndRepeatedStylesReplaceProperties(): void
    {
        $source = "flowchart LR\nA & B -->|Work| C\nC -.-> A\nlinkStyle default stroke:blue,stroke-width:2px,color:purple\nlinkStyle 0,1 stroke:red,stroke-dasharray:5 5\nlinkStyle 1 color:green\nlinkStyle 2 stroke-dasharray:0";
        $scene = self::scene($source);
        $edges = $scene->graph->edges;
        $this->assertCount(3, $edges);
        $this->assertSame(['#ff0000', '#0000ff', '#0000ff'], \array_column($edges, 'color'));
        $this->assertSame(['#800080', '#008000', '#800080'], \array_column($edges, 'textColor'));
        $this->assertSame([true, false, false], \array_column($edges, 'dashed'));
        $this->assertSame([0.2, 0.2, 0.2], \array_column($edges, 'strokeWidth'));
        $this->assertSame('Work', $edges[0]->label);
        $plain = self::scene("flowchart LR\nA & B -->|Work| C\nC -.-> A");
        foreach ($edges as $index => $edge) {
            $original = $plain->graph->edges[$index];
            $this->assertSame([$original->tail, $original->head, $original->points, $original->arrowTip, $original->labelPos, $original->minX, $original->maxY, $original->ribbon], [
                $edge->tail,
                $edge->head,
                $edge->points,
                $edge->arrowTip,
                $edge->labelPos,
                $edge->minX,
                $edge->maxY,
                $edge->ribbon,
            ]);
        }
        $this->assertSame($plain->origins, $scene->origins);
        $this->assertSame($plain->elements, $scene->elements);
    }

    public function testUnfilledEdgeStylesPreserveArrowsRoutesAndDefaultPrecedence(): void
    {
        $body = 'flowchart LR; A -->|Work| B; B --> C';
        $plain = self::scene($body);
        $unfilled = self::scene($body.'; linkStyle default fill:none; linkStyle 0 fill:NONE');
        $this->assertEquals($plain->graph, $unfilled->graph);
        $this->assertSame($plain->origins, $unfilled->origins);
        $this->assertSame($plain->elements, $unfilled->elements);
        $scene = self::scene($body.'; linkStyle default fill:none,stroke:blue,color:green; linkStyle 0 stroke:red; linkStyle 0 fill:none');
        $this->assertSame(['#0000ff', '#0000ff'], \array_column($scene->graph->edges, 'color'));
        $this->assertSame(['#008000', '#008000'], \array_column($scene->graph->edges, 'textColor'));
        foreach ($scene->graph->edges as $index => $edge) {
            $this->assertSame($plain->graph->edges[$index]->points, $edge->points);
            $this->assertSame($plain->graph->edges[$index]->arrowTip, $edge->arrowTip);
        }
        $source = $body.'; linkStyle default fill:none,stroke:blue,color:green';
        $svg = NativeFixtureExport::render($source, GraphExportFormat::Svg);
        $this->assertStringContainsString('stroke="#0000ff" fill="none"', $svg);
        $this->assertStringContainsString('fill="#0000ff"', $svg);
        $this->assertStringContainsString('fill="#008000">Work</text>', $svg);
        $this->assertStringStartsWith("\x89PNG", NativeFixtureExport::render($source, GraphExportFormat::Png));
        $rows = NativeFixtureRows::render($source, width: 18);
        $this->assertStringContainsString('Work', \implode("\n", $rows->rows));
    }

    public function testTitleAndDrawingPreserveWidthsLabelsAndArrowColors(): void
    {
        $scene = self::scene('flowchart TD; A -->|Hello| B; linkStyle 0 stroke:red,stroke-width:4px,color:green,stroke-dasharray:5');
        $work = MermaidTitle::apply($scene, new MermaidStatement('Caption', offset: 0, line: 0, column: 0));
        \iterator_to_array($work);
        $titled = $work->getReturn();
        $edge = $titled->graph->edges[0];
        $this->assertSame(0.4, $edge->strokeWidth);
        $this->assertSame('#008000', $edge->textColor);
        $this->assertGreaterThan($scene->graph->edges[0]->minY, $edge->minY);
        $drawing = MermaidDrawing::graph($titled->graph, $titled->shapes);
        \iterator_to_array($drawing);
        $marks = $drawing->getReturn()->marks;
        $line = \array_find($marks, static fn (MermaidMark $mark): bool => 'polyline' === $mark->kind);
        $this->assertInstanceOf(MermaidMark::class, $line);
        $this->assertSame(0.4, $line->strokeWidth);
        $this->assertSame('#ff0000', $line->stroke);
        $this->assertTrue($line->dashed);
        $label = \array_find($marks, static fn (MermaidMark $mark): bool => 'Hello' === $mark->text);
        $this->assertInstanceOf(MermaidMark::class, $label);
        $this->assertSame('#008000', $label->stroke);
        $view = new ZoomPanModel((int) \ceil($scene->graph->width), (int) \ceil($scene->graph->height), [1.0]);
        $rows = GraphRenderer::render($scene->graph, $view, null, 80, 24, Styles::dark());
        $this->assertStringContainsString('38;2;0;128;0', \implode("\n", $rows));
        $this->assertNotEmpty(\array_filter($marks, static fn (MermaidMark $mark): bool => 'polygon' === $mark->kind && '#ff0000' === $mark->fill));
    }

    public function testDefaultsCanPrecedeEdgesAndDoNotAffectNodesOrUnstyledDiagrams(): void
    {
        $scene = self::scene('flowchart LR; linkStyle default stroke:red; A --> B; linkStyle default color:green;');
        $edge = $scene->graph->edges[0];
        $this->assertNull($edge->color);
        $this->assertSame('#008000', $edge->textColor);
        $this->assertSame(0.12, $edge->strokeWidth);
        $this->assertNull($scene->graph->nodes[0]->color);
        $plain = self::scene('flowchart LR; A --> B');
        $this->assertNull($plain->graph->edges[0]->textColor);
        $this->assertSame(0.12, $plain->graph->edges[0]->strokeWidth);
        $this->assertSame('#ff0000', self::scene('flowchart LR; A --> B; linkStyle 00 stroke:red')->graph->edges[0]->color);
    }

    public function testNativeExportsAndNarrowRowsIncludeEdgeStyles(): void
    {
        $source = "---\ntitle: Styled links\n---\nflowchart LR\nA -->|Hello| B\nlinkStyle 0 stroke:red,stroke-width:4px,color:green,stroke-dasharray:5 5";
        $svg = NativeFixtureExport::render($source, GraphExportFormat::Svg);
        foreach (['#ff0000', '#008000', 'stroke-width="0.4"', 'stroke-dasharray='] as $needle) {
            $this->assertStringContainsString($needle, $svg, $needle);
        }
        $this->assertStringStartsWith("\x89PNG", NativeFixtureExport::render($source, GraphExportFormat::Png));
        $rows = NativeFixtureRows::render($source, width: 18);
        $this->assertContains(4, $rows->origins);
        $this->assertStringContainsString('Hello', \implode("\n", $rows->rows));
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalid(): iterable
    {
        yield 'forward index' => ['linkStyle 1 stroke:red', 'out of bounds'];
        yield 'negative' => ['linkStyle -1 stroke:red', 'Expected flowchart linkStyle'];
        yield 'overflow' => ['linkStyle 99999999999999999999999 stroke:red', 'out of bounds'];
        yield 'fill' => ['linkStyle 0 fill:red', 'fill requires the Mermaid CLI'];
        yield 'unknown property' => ['linkStyle 0 filter:blur(2px)', 'Unsupported flowchart edge CSS'];
        yield 'color' => ['linkStyle 0 stroke:bogus', 'Invalid flowchart edge style color'];
    }

    #[DataProvider('invalid')]
    public function testInvalidStylesAreRejected(string $style, string $message): void
    {
        $this->expectException(MermaidSyntaxError::class);
        $this->expectExceptionMessageIsOrContains($message);
        self::scene("flowchart LR\nA-->B\n".$style);
    }

    public function testStatementBudget(): void
    {
        $styles = new FlowchartEdgeStyles();
        $statement = new MermaidStatement('linkStyle default stroke:red', offset: 0, line: 0, column: 0);
        for ($index = 0; $index < 4096; ++$index) {
            $styles->accept($statement, edges: 0);
        }
        $this->expectException(MermaidSyntaxError::class);
        $this->expectExceptionMessageIsOrContains('edge style limit');
        $styles->accept($statement, edges: 0);
    }

    public function testAssignmentBudget(): void
    {
        $styles = new FlowchartEdgeStyles();
        $statement = new MermaidStatement('linkStyle '.\str_repeat('0,', times: 1023).'0 stroke:red', offset: 0, line: 0, column: 0);
        for ($index = 0; $index < 64; ++$index) {
            $styles->accept($statement, edges: 1);
        }
        $this->expectException(MermaidSyntaxError::class);
        $this->expectExceptionMessageIsOrContains('edge style-assignment limit');
        $styles->accept($statement, edges: 1);
    }

    public function testNamedClassesAndDirectEdgeStylesPrecedeIndexedOverrides(): void
    {
        $scene = self::scene("flowchart LR\nA e@-->|Work| B\nclassDef named stroke:red,color:green,font-size:32px\nclass e named\nstyle e stroke:purple\nlinkStyle default stroke:blue\n");
        $edge = $scene->graph->edges[0];
        $this->assertSame('#800080', $edge->color);
        $this->assertSame('#008000', $edge->textColor);
        $this->assertSame(2.0, $edge->fontScale);
        $override = self::scene("flowchart LR\nA e@-->|Work| B\nclassDef named stroke:red\nclass e named\nlinkStyle 0 stroke:blue\n");
        $this->assertSame('#0000ff', $override->graph->edges[0]->color);
    }
}
