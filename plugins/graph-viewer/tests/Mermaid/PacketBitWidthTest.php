<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use Janalis\Viz\Mermaid\MermaidConfiguration;
use Janalis\Viz\Mermaid\MermaidStatement;
use Janalis\Viz\Render\Format;
use PHPUnit\Framework\TestCase;

final class PacketBitWidthTest extends TestCase
{
    public function testFractionalWidthAndInitializationOverridesReachExportsAndCells(): void
    {
        $statement = new MermaidStatement('', offset: 0, line: 0, column: 0);
        $configuration = MermaidConfiguration::parse((object) ['packet' => (object) ['bitWidth' => 48.5]], $statement);
        $this->assertSame(48.5, $configuration->packetBitWidth);
        foreach ([1, 1000] as $width) {
            $this->assertSame((float) $width, MermaidConfiguration::parse((object) ['packet' => (object) ['bitWidth' => $width]], $statement)->packetBitWidth);
        }
        $prefix = "---\nconfig:\n  packet:\n    bitWidth: 64\n    bitsPerRow: 8\n    showBits: false\n---\n";
        $body = "packet\n0-3: \"Flag\"\n+4: \"Payload\"";
        $svg = NativeFixtureExport::render($prefix.$body, Format::Svg);
        $this->assertStringContainsString('viewBox="0 0 76 ', $svg);
        $reset = NativeFixtureExport::render($prefix."%%{init: {\"packet\": {\"bitWidth\": 32}}}%%\n".$body, Format::Svg);
        $this->assertStringContainsString('viewBox="0 0 44 ', $reset);
        $this->assertStringStartsWith("\x89PNG", NativeFixtureExport::render($prefix.$body, Format::Png));
        $rows = NativeFixtureRows::render($prefix.$body, width: 24);
        $this->assertStringContainsString('Payload', \implode("\n", $rows->rows));
        $this->assertContains(8, $rows->origins);
        $this->assertSame($configuration, $configuration->overlay(new MermaidConfiguration()));
        $this->assertSame(48.5, new MermaidConfiguration()->overlay($configuration)->packetBitWidth);
        $this->assertSame(32.0, $configuration->overlay(new MermaidConfiguration(packetBitWidth: 32.0))->packetBitWidth);
    }
}
