<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use Janalis\Viz\Mermaid\MermaidConfiguration;
use Janalis\Viz\Mermaid\MermaidStatement;
use Janalis\Viz\Render\Format;
use PHPUnit\Framework\TestCase;

final class PacketPaddingTest extends TestCase
{
    public function testZeroSurvivesMergingAndNativeRenderingKeepsBitLabelSpace(): void
    {
        $prefix = "---\nconfig:\n  packet:\n    paddingY: 10\n    showBits: false\n---\n";
        $body = "packet\n0-31: \"First\"\n+32: \"Second\"";
        $this->assertStringContainsString('viewBox="0 0 140 34"', NativeFixtureExport::render($prefix.$body, Format::Svg));
        $zero = $prefix."%%{init: {\"packet\": {\"paddingY\": 0}}}%%\n".$body;
        $this->assertStringContainsString('viewBox="0 0 140 18"', NativeFixtureExport::render($zero, Format::Svg));
        $this->assertSame(NativeFixtureExport::render($body, Format::Svg), NativeFixtureExport::render("%%{init: {\"packet\": {\"paddingY\": 5}}}%%\n".$body, Format::Svg));
        $visible = "%%{init: {\"packet\": {\"paddingY\": 0}}}%%\n".$body;
        $svg = NativeFixtureExport::render($visible, Format::Svg);
        $this->assertStringContainsString('>31</text>', $svg);
        $this->assertStringContainsString('>32</text>', $svg);
        $this->assertStringContainsString('viewBox="0 0 140 26"', $svg);
        $this->assertStringStartsWith("\x89PNG", NativeFixtureExport::render($zero, Format::Png));
        $rows = NativeFixtureRows::render($zero, width: 24);
        $this->assertStringContainsString('Second', \implode("\n", $rows->rows));
        $this->assertContains(9, $rows->origins);
        $statement = new MermaidStatement('', offset: 0, line: 0, column: 0);
        foreach ([0, 2.5, 1000] as $value) {
            $configuration = MermaidConfiguration::parse((object) ['packet' => (object) ['paddingY' => $value]], $statement);
            $this->assertSame((float) $value, $configuration->packetPaddingY);
            $this->assertSame($configuration, $configuration->overlay(new MermaidConfiguration()));
            $this->assertSame((float) $value, new MermaidConfiguration()->overlay($configuration)->packetPaddingY);
            $this->assertSame(0.0, $configuration->overlay(new MermaidConfiguration(packetPaddingY: 0.0))->packetPaddingY);
        }
    }
}
