<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use Janalis\Viz\Mermaid\MermaidConfiguration;
use Janalis\Viz\Render\Format;
use PHPUnit\Framework\TestCase;

final class PacketRowSizeTest extends TestCase
{
    public function testNativeFrontMatterAndDirectiveOverridesReachAllOutputs(): void
    {
        $prefix = "---\nconfig:\n  packet:\n    bitsPerRow: 8\n    showBits: false\n---\n";
        $body = "packet\n0-11: \"Payload\"";
        $svg = NativeFixtureExport::render($prefix.$body, Format::Svg);
        $this->assertSame(2, \substr_count($svg, needle: '>Payload</text>'));
        $this->assertStringContainsString('viewBox="0 0 44 ', $svg);
        $override = $prefix."%%{init: {\"packet\": {\"bitsPerRow\": 16}}}%%\n".$body;
        $svg = NativeFixtureExport::render($override, Format::Svg);
        $this->assertSame(1, \substr_count($svg, needle: '>Payload</text>'));
        $this->assertStringContainsString('viewBox="0 0 76 ', $svg);
        $this->assertStringStartsWith("\x89PNG", NativeFixtureExport::render($prefix.$body, Format::Png));
        $rows = NativeFixtureRows::render($prefix.$body, width: 24);
        $this->assertContains(7, $rows->origins);
        $this->assertStringContainsString('Payload', \implode("\n", $rows->rows));
        $configuration = new MermaidConfiguration(packetBitsPerRow: 8);
        $this->assertSame($configuration, $configuration->overlay(new MermaidConfiguration()));
        $this->assertSame(8, new MermaidConfiguration()->overlay($configuration)->packetBitsPerRow);
        $this->assertSame(16, $configuration->overlay(new MermaidConfiguration(packetBitsPerRow: 16))->packetBitsPerRow);
    }
}
