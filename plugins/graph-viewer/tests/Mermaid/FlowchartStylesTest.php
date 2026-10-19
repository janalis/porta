<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Graph\GraphExportFormat;
use Porta\GraphViewer\Mermaid\FlowchartParser;
use Porta\GraphViewer\Mermaid\MermaidGraph;
use Porta\GraphViewer\Mermaid\MermaidLexer;
use Porta\GraphViewer\Mermaid\MermaidSyntaxError;
use Porta\GraphViewer\Mermaid\RankedGraphLayout;

final class FlowchartStylesTest extends TestCase
{
    private static function parse(string $source): MermaidGraph
    {
        $parser = new FlowchartParser();
        foreach (new MermaidLexer($source)->statements() as $statement) {
            $parser->accept($statement);
        }

        return $parser->finish();
    }

    public function testStylesResolveDefaultsClassesAndInlineOverridesWithoutAddingNodes(): void
    {
        $source = <<<'MMD'
            flowchart LR
            classDef default fill:#eeeeee,stroke:#222222,color:black
            class A,B accent
            style A fill:green,stroke-width:4px,stroke-dasharray:5 5
            A[Alpha]:::accent & B{Beta}:::outline --> C((Gamma))
            classDef accent fill:red,color:white
            classDef outline stroke:blue
            style A stroke:none
            style Missing fill:purple
            class Missing accent
            MMD;
        $diagram = self::parse($source);
        $this->assertSame(['A', 'B', 'C'], \array_keys($diagram->nodes));
        $this->assertCount(2, $diagram->edges);
        $work = new RankedGraphLayout()->layout($diagram);
        \iterator_to_array($work);
        $scene = $work->getReturn();
        $nodes = \array_column($scene->graph->nodes, null, 'name');
        $this->assertSame('#008000', $nodes['A']->fillColor);
        $this->assertSame('none', $nodes['A']->color);
        $this->assertSame('#ffffff', $nodes['A']->textColor);
        $this->assertSame(0.4, $nodes['A']->strokeWidth);
        $this->assertTrue($nodes['A']->dashed);
        $this->assertSame('#ff0000', $nodes['B']->fillColor);
        $this->assertSame('#0000ff', $nodes['B']->color);
        $this->assertSame('#eeeeee', $nodes['C']->fillColor);
        $this->assertSame('#222222', $nodes['C']->color);
        $this->assertSame('#000000', $nodes['C']->textColor);
        $this->assertSame(['rect', 'diamond', 'circle'], \array_values($scene->shapes));
        foreach ($nodes as $node) {
            $this->assertSame(4, $scene->origin($node->id));
        }
        $this->assertSame('Alpha', $nodes['A']->label);
        $this->assertSame('LR', $diagram->direction);
    }

    public function testPinnedStylingExamplesReachSvgPngAndNarrowRows(): void
    {
        $source = <<<'MMD'
            flowchart LR
            id1(Start)-->id2(Stop)
            style id1 fill:#f9f,stroke:#333,stroke-width:4px
            style id2 fill:#bbf,stroke:#f66,stroke-width:2px,color:#fff,stroke-dasharray: 5 5
            MMD;
        $svg = NativeFixtureExport::render($source, GraphExportFormat::Svg);
        foreach (['#ff99ff', '#333333', '#bbbbff', '#ff6666', '#ffffff'] as $color) {
            $this->assertStringContainsString($color, $svg, $color.' is exported');
        }
        $this->assertStringContainsString('stroke-dasharray=', $svg);
        $this->assertStringStartsWith("\x89PNG", NativeFixtureExport::render($source, GraphExportFormat::Png));
        $rows = NativeFixtureRows::render($source, width: 18);
        $this->assertStringContainsString('Start', \implode("\n", $rows->rows));
        $this->assertContains(1, $rows->origins);
        $this->assertStringNotContainsString('style', \implode("\n", $rows->rows));
        $grouped = self::parse('flowchart LR; subgraph G; A:::foo & B:::bar --> C:::foobar; end; classDef foo stroke:red; classDef bar stroke:green; classDef foobar stroke:blue');
        $work = new RankedGraphLayout()->layout($grouped);
        \iterator_to_array($work);
        $scene = $work->getReturn();
        $this->assertCount(1, $scene->graph->clusters);
        $this->assertSame(['#ff0000', '#008000', '#0000ff'], \array_column($scene->graph->nodes, 'color'));
    }

    public function testUnicodeMultipleClassesAndDeclarationMerging(): void
    {
        $graph = self::parse('graph TD; classDef first,second fill:red; classDef first stroke:blue; α[First]:::first --> 0[Zero]; class α,0 second; style 0 fill:white; style α color:green;');
        $work = new RankedGraphLayout()->layout($graph);
        \iterator_to_array($work);
        $nodes = \array_column($work->getReturn()->graph->nodes, null, 'name');
        $this->assertSame('#ff0000', $nodes['α']->fillColor);
        $this->assertSame('#0000ff', $nodes['α']->color);
        $this->assertSame('#008000', $nodes['α']->textColor);
        $this->assertSame('#ffffff', $nodes[0]->fillColor);
        $this->assertSame([], self::parse('flowchart TD; style Missing fill:red; class Missing unknown')->nodes);
    }

    public function testCssColorNamesKeepTheirWebMeaning(): void
    {
        foreach (['green' => '#008000', 'gray' => '#808080', 'grey' => '#808080', 'maroon' => '#800000', 'purple' => '#800080'] as $name => $expected) {
            $diagram = self::parse('flowchart TD; A; style A fill:'.$name);
            $this->assertSame($expected, $diagram->styles->forNode('A')['fill']);
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidStyles(): iterable
    {
        yield 'invalid click' => ['click A', 'Invalid style, class or click declaration'];
        yield 'invalid link style' => ['linkStyle nonsense', 'Expected flowchart linkStyle'];
        yield 'property' => ['style A filter:blur(2px)', 'Unsupported flowchart CSS property'];
        yield 'color' => ['classDef x fill:bogus', 'Invalid flowchart style color'];
        yield 'width' => ['style A stroke-width:1001px', 'stroke width must be'];
        yield 'class syntax' => ['class A', 'Expected flowchart style'];
        yield 'missing value' => ['style A fill:', 'Expected flowchart style'];
    }

    #[DataProvider('invalidStyles')]
    public function testUnsupportedStylesAreLocatedInsteadOfSilentlyIgnored(string $statement, string $error): void
    {
        $this->expectException(MermaidSyntaxError::class);
        $this->expectExceptionMessageIsOrContains($error);
        self::parse("flowchart TD\nA\n".$statement);
    }
}
