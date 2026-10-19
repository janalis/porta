<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use Janalis\Viz\Mermaid\MermaidFrontMatter;
use Janalis\Viz\Mermaid\MermaidInitialization;
use Janalis\Viz\Mermaid\MermaidSyntaxError;
use Janalis\Viz\Render\Format;
use PHPUnit\Framework\TestCase;

final class SequenceWrapTest extends TestCase
{
    public function testFrontMatterDirectiveOverridesRowsAndExports(): void
    {
        $label = \trim(\str_repeat('word ', times: 20));
        $front = "---\nconfig: {sequence: {wrap: true}}\n---\n";
        $body = "sequenceDiagram\nparticipant A as ".$label."\nA->>B: ".$label;
        $rows = NativeFixtureRows::render($front.$body, width: 70);
        $this->assertContains(4, $rows->origins);
        $this->assertContains(5, $rows->origins);
        $svg = NativeFixtureExport::render($front.$body, Format::Svg);
        $this->assertStringNotContainsString('>'.$label.'<', $svg);
        $this->assertStringStartsWith("\x89PNG", NativeFixtureExport::render($front.$body, Format::Png));
        $disabled = $front."%%{init: {sequence: {wrap: false}}}%%\n".$body;
        $this->assertStringContainsString('>'.$label.'<', NativeFixtureExport::render($disabled, Format::Svg));
        $metadata = MermaidFrontMatter::parse($front.$body);
        $work = MermaidInitialization::parse('%%{init: {sequence: {showSequenceNumbers: true}}}%%', $metadata->configuration);
        \iterator_to_array($work);
        $this->assertTrue($work->getReturn()->configuration->sequenceWrap);
        $this->assertTrue($work->getReturn()->configuration->sequenceNumbers);
        $this->expectException(MermaidSyntaxError::class);
        $metadata->configuration->validateFamily('pie');
    }

    public function testRootWrappingFlowsThroughNativeRowsAndExports(): void
    {
        $label = \str_repeat('x', times: 61);
        $body = "sequenceDiagram\nparticipant A as ".$label."\nA->>B: ".$label;
        $front = "---\nconfig: {wrap: true, sequence: {wrap: false}}\n---\n";
        $rows = NativeFixtureRows::render($front.$body, width: 70);
        $this->assertContains(4, $rows->origins);
        $this->assertContains(5, $rows->origins);
        $svg = NativeFixtureExport::render($front.$body, Format::Svg);
        $this->assertStringNotContainsString('>'.$label.'<', $svg);
        $this->assertStringStartsWith("\x89PNG", NativeFixtureExport::render($front.$body, Format::Png));
        $override = $front."%%{init: {wrap: false, sequence: {wrap: true}}}%%\n".$body;
        $this->assertStringContainsString('>'.$label.'<', NativeFixtureExport::render($override, Format::Svg));
    }
}
