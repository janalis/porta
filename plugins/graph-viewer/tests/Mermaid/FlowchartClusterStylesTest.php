<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Graph\GraphExportFormat;
use Porta\GraphViewer\Mermaid\FlowchartParser;
use Porta\GraphViewer\Mermaid\MermaidDrawing;
use Porta\GraphViewer\Mermaid\MermaidLexer;
use Porta\GraphViewer\Mermaid\MermaidMark;
use Porta\GraphViewer\Mermaid\MermaidScene;
use Porta\GraphViewer\Mermaid\MermaidStatement;
use Porta\GraphViewer\Mermaid\MermaidTitle;
use Porta\GraphViewer\Mermaid\RankedGraphLayout;
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

    public function testNestedGroupStylesPreserveGeometryAndIgnoreTheImplicitNodeDefault(): void
    {
        $body = "flowchart TD\nsubgraph Outer[Services]\nsubgraph Inner[Workers]\nA --> B\nend\nC\nend\nsubgraph Plain\nD\nend";
        $plain = self::scene($body);
        $scene = self::scene(
            $body
            .'; classDef default fill:yellow,stroke:red; class Outer frame; Inner:::warning; classDef frame fill:#eef,stroke:blue,color:green; classDef warning fill:pink; style Inner stroke:none,stroke-width:4px,stroke-dasharray:5 5,color:purple',
        );
        $this->assertCount(4, $scene->graph->nodes);
        $this->assertCount(3, $scene->graph->clusters);
        $groups = \array_column($scene->graph->clusters, null, 'name');
        $this->assertSame('#eeeeff', $groups['Outer']->fillColor);
        $this->assertSame('#0000ff', $groups['Outer']->color);
        $this->assertSame('#008000', $groups['Outer']->textColor);
        $this->assertSame('#ffc0cb', $groups['Inner']->fillColor);
        $this->assertSame('none', $groups['Inner']->color);
        $this->assertSame('#800080', $groups['Inner']->textColor);
        $this->assertSame(0.4, $groups['Inner']->strokeWidth);
        $this->assertTrue($groups['Inner']->dashed);
        // Unstyled groups carry their color theme slot, which other themes resolve to their roles.
        $this->assertSame('@slot:fill:2', $groups['Plain']->fillColor);
        $this->assertSame('@slot:stroke:2', $groups['Plain']->color);
        $this->assertFalse($groups['Plain']->dashed);
        $original = \array_column($plain->graph->clusters, null, 'name');
        foreach ($groups as $name => $group) {
            $before = $original[$name];
            $this->assertSame([$before->x0, $before->y0, $before->x1, $before->y1, $before->label, $before->labelPos], [
                $group->x0,
                $group->y0,
                $group->x1,
                $group->y1,
                $group->label,
                $group->labelPos,
            ]);
        }
        $this->assertSame($plain->origins, $scene->origins);
        $this->assertSame($plain->elements, $scene->elements);
        $this->assertEquals($plain->graph->edges, $scene->graph->edges);
        $this->assertSame('#ffff00', $scene->graph->nodes[0]->fillColor);
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

    public function testExplicitDefaultClassAndStylePrecedenceApplyToGroups(): void
    {
        $scene = self::scene('graph LR; subgraph G; A; end; classDef default fill:yellow,stroke:red; class G default; style G fill:white; style G color:blue; style G stroke-dasharray:0');
        $group = $scene->graph->clusters[0];
        $this->assertSame('#ffffff', $group->fillColor);
        $this->assertSame('#ff0000', $group->color);
        $this->assertSame('#0000ff', $group->textColor);
        $this->assertSame(0.12, $group->strokeWidth);
        $this->assertFalse($group->dashed);
    }

    public function testNativeSvgPngAndNarrowRowsIncludeSubgraphStyling(): void
    {
        $source = "---\ntitle: Styled groups\n---\nflowchart TD\nsubgraph G[Services]\nA-->B\nend\nstyle G fill:#eef,stroke:red,color:green,stroke-width:4px,stroke-dasharray:5";
        $svg = NativeFixtureExport::render($source, GraphExportFormat::Svg);
        foreach (['#eeeeff', '#ff0000', '#008000', 'stroke-width="0.4"', 'stroke-dasharray='] as $needle) {
            $this->assertStringContainsString($needle, $svg, $needle);
        }
        $this->assertStringStartsWith("\x89PNG", NativeFixtureExport::render($source, GraphExportFormat::Png));
        $rows = NativeFixtureRows::render($source, width: 24);
        $this->assertStringContainsString('Services', \implode("\n", $rows->rows));
        $this->assertContains(4, $rows->origins);
        $this->assertContains(5, $rows->origins);
    }
}
