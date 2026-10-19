<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use Janalis\Viz\Render\Format;
use PHPUnit\Framework\TestCase;

final class PacketConfigurationTest extends TestCase
{
    public function testFrontMatterAndDirectivesMergeExplicitFalseAndTrue(): void
    {
        $prefix = "---\ntitle: Packet title\nconfig:\n  packet:\n    showBits: false\n---\n";
        $body = "packet-beta\n0-31: \"Payload\"\n+8: \"Trailer\"";
        $hidden = NativeFixtureExport::render($prefix.$body, Format::Svg);
        $this->assertStringNotContainsString('>0</text>', $hidden);
        $this->assertStringNotContainsString('>31</text>', $hidden);
        $this->assertStringContainsString('>Payload</text>', $hidden);
        $this->assertStringContainsString('>Packet title</text>', $hidden);
        $visible = NativeFixtureExport::render($prefix."%%{init: {\"packet\": {\"showBits\": true}}}%%\n".$body, Format::Svg);
        $this->assertStringContainsString('>0</text>', $visible);
        $this->assertStringContainsString('>31</text>', $visible);
        $directives = "%%{init: {\"packet\": {\"showBits\": true}}}%%\n%%{initialize: {\"packet\": {\"showBits\": false}}}%%\n".$body;
        $this->assertStringNotContainsString('>0</text>', NativeFixtureExport::render($directives, Format::Svg));
        $this->assertStringStartsWith("\x89PNG", NativeFixtureExport::render($prefix.$body, Format::Png));
        $rows = NativeFixtureRows::render($prefix.$body, width: 24);
        $this->assertStringContainsString('Payload', \implode("\n", $rows->rows));
        $this->assertContains(7, $rows->origins);
    }
}
