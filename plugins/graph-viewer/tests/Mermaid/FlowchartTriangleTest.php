<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use Janalis\Viz\Render\Format;
use PHPUnit\Framework\TestCase;

final class FlowchartTriangleTest extends TestCase
{
    public function testNativeExportsTitlesAndNarrowRowsPreserveTriangleSources(): void
    {
        $source = "---\ntitle: Triangular steps\n---\nflowchart TD\nA@{shape: triangle, label: Extract} --> B@{shape: flipped-triangle, label: File}\nstyle A fill:yellow,stroke:red";
        $svg = NativeFixtureExport::render($source, Format::Svg);
        $this->assertStringContainsString('>Triangular steps</text>', $svg);
        $this->assertStringContainsString('stroke="#ff0000" fill="#ffff00"', $svg);
        $this->assertStringContainsString('>Extract</text>', $svg);
        $this->assertStringStartsWith("\x89PNG", NativeFixtureExport::render($source, Format::Png));
        $rows = NativeFixtureRows::render($source, width: 24);
        $this->assertStringContainsString('Extract', \implode("\n", $rows->rows));
        $this->assertContains(4, $rows->origins);
    }
}
