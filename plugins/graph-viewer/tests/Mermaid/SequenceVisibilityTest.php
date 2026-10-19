<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use Janalis\Viz\Render\Format;
use PHPUnit\Framework\TestCase;

final class SequenceVisibilityTest extends TestCase
{
    public function testNativeExportsRowsOverridesAndEmptyDiagrams(): void
    {
        $body = "sequenceDiagram\nparticipant Hidden\nparticipant A as Alice\nparticipant B as Bob\nA->>B: Hello";
        $prefix = "---\nconfig: {sequence: {hideUnusedParticipants: true}}\n---\n";
        $source = $prefix.$body;
        $svg = NativeFixtureExport::render($source, Format::Svg);
        $this->assertStringNotContainsString('Hidden', $svg);
        $this->assertStringContainsString('Alice', $svg);
        $this->assertStringStartsWith("\x89PNG", NativeFixtureExport::render($source, Format::Png));
        $rows = NativeFixtureRows::render($source, width: 35);
        $this->assertStringNotContainsString('Hidden', \implode("\n", $rows->rows));
        $this->assertContains(5, $rows->origins);
        $this->assertContains(7, $rows->origins);
        $override = $prefix."%%{init: {sequence: {hideUnusedParticipants: false}}}%%\n".$body;
        $this->assertStringContainsString('Hidden', NativeFixtureExport::render($override, Format::Svg));
        $enabled = "%%{initialize: {sequence: {hideUnusedParticipants: true}}}%%\n".$body;
        $this->assertStringNotContainsString('Hidden', NativeFixtureExport::render($enabled, Format::Svg));
        $empty = $prefix."sequenceDiagram\nbox Empty\nparticipant Hidden\nend";
        $this->assertStringNotContainsString('Hidden', NativeFixtureExport::render($empty, Format::Svg));
        $this->assertStringNotContainsString('Empty', NativeFixtureExport::render($empty, Format::Svg));
    }
}
