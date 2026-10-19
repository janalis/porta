<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use Janalis\Viz\Mermaid\FlowchartParser;
use Janalis\Viz\Mermaid\MermaidDrawing;
use Janalis\Viz\Mermaid\MermaidLexer;
use Janalis\Viz\Mermaid\MermaidMark;
use Janalis\Viz\Mermaid\MermaidScene;
use Janalis\Viz\Mermaid\MermaidStatement;
use Janalis\Viz\Mermaid\MermaidTitle;
use Janalis\Viz\Mermaid\RankedGraphLayout;
use Janalis\Viz\Render\Format;
use PHPUnit\Framework\TestCase;
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
        $svg = NativeFixtureExport::render($source, Format::Svg);
        $this->assertStringContainsString('stroke="#0000ff" fill="none"', $svg);
        $this->assertStringContainsString('fill="#0000ff"', $svg);
        $this->assertStringContainsString('fill="#008000">Work</text>', $svg);
        $this->assertStringStartsWith("\x89PNG", NativeFixtureExport::render($source, Format::Png));
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

    public function testNativeExportsAndNarrowRowsIncludeEdgeStyles(): void
    {
        $source = "---\ntitle: Styled links\n---\nflowchart LR\nA -->|Hello| B\nlinkStyle 0 stroke:red,stroke-width:4px,color:green,stroke-dasharray:5 5";
        $svg = NativeFixtureExport::render($source, Format::Svg);
        foreach (['#ff0000', '#008000', 'stroke-width="0.4"', 'stroke-dasharray='] as $needle) {
            $this->assertStringContainsString($needle, $svg, $needle);
        }
        $this->assertStringStartsWith("\x89PNG", NativeFixtureExport::render($source, Format::Png));
        $rows = NativeFixtureRows::render($source, width: 18);
        $this->assertContains(4, $rows->origins);
        $this->assertStringContainsString('Hello', \implode("\n", $rows->rows));
    }
}
