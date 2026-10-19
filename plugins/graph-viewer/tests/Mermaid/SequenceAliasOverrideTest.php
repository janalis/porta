<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use Janalis\Viz\Render\Format;
use PHPUnit\Framework\TestCase;

final class SequenceAliasOverrideTest extends TestCase
{
    public function testOverriddenMetadataUsesTheSameNativeRowsAndExports(): void
    {
        $source = "sequenceDiagram\nactor A@{alias: true} as Visible\nA->>B: after";
        $svg = NativeFixtureExport::render($source, Format::Svg);
        $this->assertStringContainsString('>Visible</text>', $svg);
        $this->assertStringContainsString('>after</text>', $svg);
        $this->assertStringStartsWith("\x89PNG", NativeFixtureExport::render($source, Format::Png));
        $rows = NativeFixtureRows::render($source, width: 30);
        $this->assertStringContainsString('Visible', \implode("\n", $rows->rows));
        $this->assertContains(1, $rows->origins);
    }
}
