<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Graph\GraphExportFormat;
use Porta\GraphViewer\Mermaid\MermaidConfiguration;
use Porta\GraphViewer\Mermaid\MermaidLexer;
use Porta\GraphViewer\Mermaid\MermaidStatement;
use Porta\GraphViewer\Mermaid\MermaidSyntaxError;
use Porta\GraphViewer\Mermaid\PacketLayout;
use Porta\GraphViewer\Mermaid\PacketParser;

final class PacketRowSizeTest extends TestCase
{
    public function testConfiguredRowsSplitFieldsAndScaleMetadataWithoutLosingOrigins(): void
    {
        $configuration = new MermaidConfiguration(packetBitOrder: 'descending', packetBitsPerRow: 8);
        $parser = new PacketParser($configuration);
        foreach (new MermaidLexer("packet\ntitle Header\n0-11: \"Payload\"\n+4: \"Tail\"", chart: true)->statements() as $statement) {
            $parser->accept($statement);
        }
        $diagram = $parser->finish();
        $this->assertSame(8, $diagram->bitsPerRow);
        $work = new PacketLayout($configuration)->layout($diagram);
        \iterator_to_array($work);
        $scene = $work->getReturn();
        $this->assertSame(44.0, $scene->graph->width);
        $header = $scene->graph->nodes[0];
        $this->assertSame([22.0, 32.0], [$header->x, $header->w]);
        $fields = [];
        foreach ($scene->graph->nodes as $node) {
            if (!\array_key_exists($node->id, $scene->elements)) {
                continue;
            }
            $fields[] = [$node->name, $node->x, $node->w, $scene->origin($node->id)];
        }
        $this->assertSame(
            [
                ['packet-0',  21.6875, 31.375, 2],
                ['packet-8',  29.6875, 15.375, 2],
                ['packet-12', 13.6875, 15.375, 3],
            ],
            $fields,
        );
        $this->assertStringContainsString('0–7: Payload', $scene->legend[1]['text']);
        $this->assertStringContainsString('8–11: Payload', $scene->legend[2]['text']);
    }

    public function testNativeFrontMatterAndDirectiveOverridesReachAllOutputs(): void
    {
        $prefix = "---\nconfig:\n  packet:\n    bitsPerRow: 8\n    showBits: false\n---\n";
        $body = "packet\n0-11: \"Payload\"";
        $svg = NativeFixtureExport::render($prefix.$body, GraphExportFormat::Svg);
        $this->assertSame(2, \substr_count($svg, needle: '>Payload</text>'));
        $this->assertStringContainsString('viewBox="0 0 44 ', $svg);
        $override = $prefix."%%{init: {\"packet\": {\"bitsPerRow\": 16}}}%%\n".$body;
        $svg = NativeFixtureExport::render($override, GraphExportFormat::Svg);
        $this->assertSame(1, \substr_count($svg, needle: '>Payload</text>'));
        $this->assertStringContainsString('viewBox="0 0 76 ', $svg);
        $this->assertStringStartsWith("\x89PNG", NativeFixtureExport::render($prefix.$body, GraphExportFormat::Png));
        $rows = NativeFixtureRows::render($prefix.$body, width: 24);
        $this->assertContains(7, $rows->origins);
        $this->assertStringContainsString('Payload', \implode("\n", $rows->rows));
        $configuration = new MermaidConfiguration(packetBitsPerRow: 8);
        $this->assertSame($configuration, $configuration->overlay(new MermaidConfiguration()));
        $this->assertSame(8, new MermaidConfiguration()->overlay($configuration)->packetBitsPerRow);
        $this->assertSame(16, $configuration->overlay(new MermaidConfiguration(packetBitsPerRow: 16))->packetBitsPerRow);
    }

    public function testMinimumAndMaximumRowsAreAccepted(): void
    {
        foreach ([1, 1024] as $size) {
            $configuration = MermaidConfiguration::parse((object) ['packet' => (object) ['bitsPerRow' => $size]], new MermaidStatement('', offset: 0, line: 0, column: 0));
            $this->assertSame($size, $configuration->packetBitsPerRow);
            $parser = new PacketParser($configuration);
            $parser->accept(new MermaidStatement('packet', offset: 0, line: 0, column: 0));
            $parser->accept(new MermaidStatement('0-1: "X"', offset: 7, line: 1, column: 0));
            $work = new PacketLayout($configuration)->layout($parser->finish());
            \iterator_to_array($work);
            $this->assertSame((float) (12 + (4 * $size)), $work->getReturn()->graph->width);
            $this->assertCount(1 === $size ? 2 : 1, $work->getReturn()->elements);
        }
    }

    public function testFragmentBudgetUsesConfiguredRowSizeBeforeLayout(): void
    {
        $parser = new PacketParser(new MermaidConfiguration(packetBitsPerRow: 1));
        $parser->accept(new MermaidStatement('packet', offset: 0, line: 0, column: 0));
        $parser->accept(new MermaidStatement('0-4095: "At limit"', offset: 7, line: 1, column: 0));
        $this->assertCount(1, $parser->finish()->fields);
        $this->expectException(MermaidSyntaxError::class);
        $this->expectExceptionMessageIsOrContains('packet field or bit limit');
        $parser->accept(new MermaidStatement('4096: "Over"', offset: 30, line: 2, column: 0));
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalid(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
        yield 'large' => [1025];
        yield 'fraction' => [8.5];
        yield 'string' => ['8'];
        yield 'boolean' => [true];
    }

    #[DataProvider('invalid')]
    public function testInvalidRowSizesAreRejected(mixed $value): void
    {
        $this->expectException(MermaidSyntaxError::class);
        $this->expectExceptionMessageIsOrContains('Packet bitsPerRow must be an integer from 1 to 1024');
        MermaidConfiguration::parse((object) ['packet' => (object) ['bitsPerRow' => $value]], new MermaidStatement('', offset: 0, line: 0, column: 0));
    }

    public function testRowSizeCannotApplyToAnotherFamily(): void
    {
        $this->expectException(MermaidSyntaxError::class);
        $this->expectExceptionMessageIsOrContains('does not apply');
        new MermaidConfiguration(packetBitsPerRow: 8)->validateFamily('other');
    }
}
