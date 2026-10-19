<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use Janalis\Viz\Mermaid\MermaidConfiguration;
use Janalis\Viz\Render\Format;
use PHPUnit\Framework\TestCase;

final class PacketBitOrderTest extends TestCase
{
    public function testFrontMatterDirectivesAndHiddenNumbersUseTheSameOrientation(): void
    {
        $prefix = "---\nconfig:\n  packet:\n    bitOrder: descending\n---\n";
        $body = "packet\n0-7: \"Low\"\n+24: \"High\"";
        $svg = NativeFixtureExport::render($prefix.$body, Format::Svg);
        $this->assertStringContainsString('x="117.6875" y=', $svg);
        $this->assertStringContainsString('>Low</text>', $svg);
        $reset = NativeFixtureExport::render($prefix."%%{init: {\"packet\": {\"bitOrder\": \"ascending\"}}}%%\n".$body, Format::Svg);
        $this->assertStringContainsString('x="21.6875" y=', $reset);
        $hidden = $prefix."%%{initialize: {\"packet\": {\"showBits\": false}}}%%\n".$body;
        $noTicks = NativeFixtureExport::render($hidden, Format::Svg);
        $this->assertStringContainsString('x="117.6875" y=', $noTicks);
        $this->assertStringNotContainsString('>0</text>', $noTicks);
        $this->assertStringStartsWith("\x89PNG", NativeFixtureExport::render($hidden, Format::Png));
        $rows = NativeFixtureRows::render($hidden, width: 30);
        $this->assertStringContainsString('Low', \implode("\n", $rows->rows));
        $this->assertContains(7, $rows->origins);
        $configuration = new MermaidConfiguration(packetBitOrder: 'descending');
        $this->assertSame($configuration, $configuration->overlay(new MermaidConfiguration()));
        $this->assertSame('descending', new MermaidConfiguration()->overlay($configuration)->packetBitOrder);
        $this->assertSame('ascending', $configuration->overlay(new MermaidConfiguration(packetBitOrder: 'ascending'))->packetBitOrder);
    }
}
