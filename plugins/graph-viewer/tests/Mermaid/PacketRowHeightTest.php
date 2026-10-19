<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use Janalis\Viz\Mermaid\MermaidConfiguration;
use Janalis\Viz\Mermaid\MermaidStatement;
use Janalis\Viz\Render\Format;
use PHPUnit\Framework\TestCase;

final class PacketRowHeightTest extends TestCase
{
    public function testFrontMatterAndDirectiveOverridesReachExportsAndCells(): void
    {
        $prefix = "---\nconfig:\n  packet:\n    rowHeight: 64\n    showBits: false\n---\n";
        $body = "packet\n0-31: \"Payload\"";
        $svg = NativeFixtureExport::render($prefix.$body, Format::Svg);
        $this->assertStringContainsString('viewBox="0 0 140 22"', $svg);
        $reset = NativeFixtureExport::render($prefix."%%{init: {\"packet\": {\"rowHeight\": 32}}}%%\n".$body, Format::Svg);
        $this->assertStringContainsString('viewBox="0 0 140 14"', $reset);
        $this->assertStringStartsWith("\x89PNG", NativeFixtureExport::render($prefix.$body, Format::Png));
        $rows = NativeFixtureRows::render($prefix.$body, width: 24);
        $this->assertStringContainsString('Payload', \implode("\n", $rows->rows));
        $this->assertContains(7, $rows->origins);
        $statement = new MermaidStatement('', offset: 0, line: 0, column: 0);
        foreach ([1, 65.5, 1000] as $value) {
            $configuration = MermaidConfiguration::parse((object) ['packet' => (object) ['rowHeight' => $value]], $statement);
            $this->assertSame((float) $value, $configuration->packetRowHeight);
            $this->assertSame($configuration, $configuration->overlay(new MermaidConfiguration()));
            $this->assertSame((float) $value, new MermaidConfiguration()->overlay($configuration)->packetRowHeight);
            $this->assertSame(32.0, $configuration->overlay(new MermaidConfiguration(packetRowHeight: 32.0))->packetRowHeight);
        }
    }
}
