<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use Janalis\Viz\Mermaid\FlowchartParser;
use Janalis\Viz\Mermaid\MermaidGraph;
use Janalis\Viz\Mermaid\MermaidLexer;
use Janalis\Viz\Mermaid\RankedGraphLayout;
use Janalis\Viz\Render\Format;
use PHPUnit\Framework\TestCase;

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

    public function testPinnedStylingExamplesReachSvgPngAndNarrowRows(): void
    {
        $source = <<<'MMD'
            flowchart LR
            id1(Start)-->id2(Stop)
            style id1 fill:#f9f,stroke:#333,stroke-width:4px
            style id2 fill:#bbf,stroke:#f66,stroke-width:2px,color:#fff,stroke-dasharray: 5 5
            MMD;
        $svg = NativeFixtureExport::render($source, Format::Svg);
        foreach (['#ff99ff', '#333333', '#bbbbff', '#ff6666', '#ffffff'] as $color) {
            $this->assertStringContainsString($color, $svg, $color.' is exported');
        }
        $this->assertStringContainsString('stroke-dasharray=', $svg);
        $this->assertStringStartsWith("\x89PNG", NativeFixtureExport::render($source, Format::Png));
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
}
