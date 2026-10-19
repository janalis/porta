<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use Janalis\Viz\Render\Format;
use PHPUnit\Framework\TestCase;

final class FlowchartShapeAliasesTest extends TestCase
{
    public function testStyledAliasesReachPngAndNarrowSourceLinkedRows(): void
    {
        $source = "---\ntitle: Modern names\n---\nflowchart TD\nA@{shape:fr-rect,label:Frame}-->B@{shape:pill,label:Terminal}\nstyle A stroke:red,stroke-width:4px,stroke-dasharray:5 5";
        $svg = NativeFixtureExport::render($source, Format::Svg);
        $this->assertSame(3, \substr_count($svg, needle: 'stroke-dasharray='));
        $this->assertStringContainsString('>Modern names</text>', $svg);
        $this->assertStringStartsWith("\x89PNG", NativeFixtureExport::render($source, Format::Png));
        $rows = NativeFixtureRows::render($source, width: 24);
        $this->assertStringContainsString('Frame', \implode("\n", $rows->rows));
        $this->assertContains(4, $rows->origins);
    }
}
