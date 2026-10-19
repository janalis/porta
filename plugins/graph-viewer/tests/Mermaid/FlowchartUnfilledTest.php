<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use Janalis\Viz\Mermaid\FlowchartParser;
use Janalis\Viz\Mermaid\MermaidLexer;
use Janalis\Viz\Mermaid\RankedGraphLayout;
use Janalis\Viz\Render\Format;
use PHPUnit\Framework\TestCase;

final class FlowchartUnfilledTest extends TestCase
{
    public function testUnfilledNodesAndNestedGroupsPreserveTheirUnderlyingPaint(): void
    {
        $source = <<<'MMD'
            flowchart TD
            subgraph Outer
            subgraph Inner
            A[Unfilled]:::clear
            end
            end
            classDef clear fill:none,stroke:red
            style Outer fill:yellow
            style Inner fill:NONE,stroke:blue
            MMD;
        $parser = new FlowchartParser();
        foreach (new MermaidLexer($source)->statements() as $statement) {
            $parser->accept($statement);
        }
        $work = new RankedGraphLayout()->layout($parser->finish());
        \iterator_to_array($work);
        $scene = $work->getReturn();
        $node = $scene->graph->nodes[0];
        $groups = \array_column($scene->graph->clusters, null, 'name');
        $this->assertSame('none', $node->fillColor);
        $this->assertSame('none', $groups['Inner']->fillColor);
        $this->assertSame('#ffff00', $groups['Outer']->fillColor);
        $this->assertSame(3, $scene->origin($node->id));
        $svg = NativeFixtureExport::render($source, Format::Svg);
        $this->assertSame(2, \substr_count($svg, needle: 'fill="none"'));
        $this->assertStringContainsString('stroke="#ff0000" fill="none"', $svg);
        $this->assertStringContainsString('stroke="#0000ff" fill="none"', $svg);
        $png = NativeFixtureExport::render($source, Format::Png);
        $image = \imagecreatefromstring($png);
        $this->assertInstanceOf(\GdImage::class, $image);
        // The native export scale is ten pixels per logical unit. Sample away from text and borders.
        $this->assertSame(0xff_ff00, \imagecolorat($image, (int) \round(($node->x - ($node->w / 2) + 0.8) * 10), (int) \round(($node->y + ($node->h / 2) - 0.5) * 10)));
        $inner = $groups['Inner'];
        $this->assertSame(0xff_ff00, \imagecolorat($image, (int) \round(($inner->x0 + 0.5) * 10), (int) \round(($inner->y1 - 0.5) * 10)));
        $this->assertSame(0x00_00ff, \imagecolorat($image, (int) \round($inner->x0 * 10), (int) \round(($inner->y1 - 0.5) * 10)));
        $rows = NativeFixtureRows::render($source, width: 24);
        $this->assertStringContainsString('Unfilled', \implode("\n", $rows->rows));
        $this->assertContains(3, $rows->origins);
    }
}
