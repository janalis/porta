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

final class FlowchartClusterStylesTest extends TestCase
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

    public function testTitleDrawingAndTerminalPreserveContainerPresentation(): void
    {
        $scene = self::scene('flowchart TD; subgraph G[Team]; A-->B; end; style G fill:#eef,stroke:red,color:green,stroke-width:4px,stroke-dasharray:5');
        $title = MermaidTitle::apply($scene, new MermaidStatement('Caption', offset: 0, line: 0, column: 0));
        \iterator_to_array($title);
        $titled = $title->getReturn();
        $group = $titled->graph->clusters[0];
        $this->assertSame('#eeeeff', $group->fillColor);
        $this->assertSame('#008000', $group->textColor);
        $this->assertSame(0.4, $group->strokeWidth);
        $this->assertTrue($group->dashed);
        $this->assertGreaterThan($scene->graph->clusters[0]->y0, $group->y0);
        $drawing = MermaidDrawing::graph($titled->graph, $titled->shapes);
        \iterator_to_array($drawing);
        $marks = $drawing->getReturn()->marks;
        $this->assertSame('#eeeeff', $marks[0]->fill);
        $this->assertSame('#ff0000', $marks[0]->stroke);
        $this->assertSame(0.4, $marks[0]->strokeWidth);
        $this->assertTrue($marks[0]->dashed);
        $label = \array_find($marks, static fn (MermaidMark $mark): bool => 'Team' === $mark->text);
        $this->assertInstanceOf(MermaidMark::class, $label);
        $this->assertSame('#008000', $label->stroke);
        $view = new ZoomPanModel((int) \ceil($scene->graph->width), (int) \ceil($scene->graph->height), [1.0]);
        $rows = \implode("\n", GraphRenderer::render($scene->graph, $view, null, 80, 24, Styles::dark()));
        $this->assertStringContainsString('38;2;0;128;0', $rows);
        $this->assertStringContainsString('┄', $rows);
        $this->assertStringContainsString('┆', $rows);
    }

    public function testNativeSvgPngAndNarrowRowsIncludeSubgraphStyling(): void
    {
        $source = "---\ntitle: Styled groups\n---\nflowchart TD\nsubgraph G[Services]\nA-->B\nend\nstyle G fill:#eef,stroke:red,color:green,stroke-width:4px,stroke-dasharray:5";
        $svg = NativeFixtureExport::render($source, Format::Svg);
        foreach (['#eeeeff', '#ff0000', '#008000', 'stroke-width="0.4"', 'stroke-dasharray='] as $needle) {
            $this->assertStringContainsString($needle, $svg, $needle);
        }
        $this->assertStringStartsWith("\x89PNG", NativeFixtureExport::render($source, Format::Png));
        $rows = NativeFixtureRows::render($source, width: 24);
        $this->assertStringContainsString('Services', \implode("\n", $rows->rows));
        $this->assertContains(4, $rows->origins);
        $this->assertContains(5, $rows->origins);
    }
}
