<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use Janalis\Viz\Mermaid\FlowchartParser;
use Janalis\Viz\Mermaid\MermaidLexer;
use Janalis\Viz\Mermaid\RankedGraphLayout;
use Janalis\Viz\Render\Format;
use PHPUnit\Framework\TestCase;

final class FlowchartCutCornersTest extends TestCase
{
    public function testCompoundFlowchartsRouteIntoSlopedInputAndPreserveSourceGeometry(): void
    {
        $source = "flowchart TD\nsubgraph G[Forms]\nA@{shape:card,label:Card}-->B@{shape:manual-input,label:Input}\nend";
        $parser = new FlowchartParser();
        foreach (new MermaidLexer($source)->statements() as $statement) {
            $parser->accept($statement);
        }
        $work = new RankedGraphLayout()->layout($parser->finish());
        \iterator_to_array($work);
        $scene = $work->getReturn();
        [$a, $b] = $scene->graph->nodes;
        $this->assertSame([8.0, 6.0, 9.0, 6.0], [$a->w, $a->h, $b->w, $b->h]);
        $this->assertSame([2, 2], \array_values($scene->elements));
        $this->assertCount(1, $scene->graph->clusters);
        $this->assertSame($a->y + ($a->h / 2), $scene->graph->edges[0]->points[0][1]);
        [$tipX, $tipY] = $scene->graph->edges[0]->arrowTip;
        $this->assertEqualsWithDelta($b->y - ($b->h / 2) + (2 * (($b->x + ($b->w / 2) - $tipX) / $b->w)), $tipY, delta: 0.000_001);
        $configured = "---\ntitle: Forms\n---\n".$source."\nstyle A fill:yellow,stroke:red";
        $svg = NativeFixtureExport::render($configured, Format::Svg);
        $this->assertStringContainsString('stroke="#ff0000" fill="#ffff00"', $svg);
        $this->assertStringContainsString('>Forms</text>', $svg);
        $this->assertStringStartsWith("\x89PNG", NativeFixtureExport::render($configured, Format::Png));
        $rows = NativeFixtureRows::render($configured, width: 24);
        $this->assertStringContainsString('Input', \implode("\n", $rows->rows));
        $this->assertContains(5, $rows->origins);
    }
}
