<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use Janalis\Viz\Render\Format;
use PHPUnit\Framework\TestCase;

final class MermaidDashStylesTest extends TestCase
{
    public function testNodeGroupAndEdgeOverridesReachNativeExportsAndCells(): void
    {
        $source = <<<'MMD'
            flowchart TD
            subgraph G[Group]
            A[Dashed]:::frame --> B[Solid]:::frame
            end
            B -.-> A
            classDef frame stroke-dasharray:5\,5,stroke:red
            style B stroke-dasharray:none
            style G stroke-dasharray:3\,2,stroke:blue
            linkStyle default stroke-dasharray:4\,2,stroke:green
            linkStyle 1 stroke-dasharray:NONE
            MMD;
        $svg = NativeFixtureExport::render($source, Format::Svg);
        $this->assertSame(3, \substr_count($svg, needle: 'stroke-dasharray='));
        foreach (['stroke-dasharray="0.5 0.5"', 'stroke-dasharray="0.3 0.2"', 'stroke-dasharray="0.4 0.2"'] as $pattern) {
            $this->assertStringContainsString($pattern, $svg, 'authored lengths reach the picture');
        }
        foreach (['#ff0000', '#0000ff', '#008000', '>Dashed</text>', '>Solid</text>'] as $needle) {
            $this->assertStringContainsString($needle, $svg);
        }
        $this->assertStringStartsWith("\x89PNG", NativeFixtureExport::render($source, Format::Png));
        $rows = NativeFixtureRows::render($source, width: 30);
        $this->assertStringContainsString('Dashed', \implode("\n", $rows->rows));
        $this->assertContains(2, $rows->origins);
    }
}
