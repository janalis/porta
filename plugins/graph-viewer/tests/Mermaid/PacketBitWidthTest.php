<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Graph\GraphExportFormat;
use Porta\GraphViewer\Mermaid\MermaidConfiguration;
use Porta\GraphViewer\Mermaid\MermaidLexer;
use Porta\GraphViewer\Mermaid\MermaidScene;
use Porta\GraphViewer\Mermaid\MermaidStatement;
use Porta\GraphViewer\Mermaid\MermaidSyntaxError;
use Porta\GraphViewer\Mermaid\PacketLayout;
use Porta\GraphViewer\Mermaid\PacketParser;

final class PacketBitWidthTest extends TestCase
{
    private static function scene(MermaidConfiguration $configuration): MermaidScene
    {
        $parser = new PacketParser($configuration);
        foreach (new MermaidLexer("packet\ntitle Header\n0-3: \"Longer label wraps\"\n+8: \"Payload\"", chart: true)->statements() as $statement) {
            $parser->accept($statement);
        }
        $work = new PacketLayout($configuration)->layout($parser->finish());
        \iterator_to_array($work);

        return $work->getReturn();
    }

    public function testWidthsWrappingMetadataAndDescendingTickPositionsScaleTogether(): void
    {
        $normal = self::scene(new MermaidConfiguration(packetBitsPerRow: 8));
        $wide = self::scene(new MermaidConfiguration(packetBitOrder: 'descending', packetBitsPerRow: 8, packetBitWidth: 64.0));
        $this->assertSame(76.0, $wide->graph->width);
        $this->assertSame([38.0, 64.0], [$wide->graph->nodes[0]->x, $wide->graph->nodes[0]->w]);
        $this->assertSame($normal->elements, $wide->elements);
        $this->assertSame($normal->legend, $wide->legend);
        $fields = [];
        $ticks = [];
        foreach ($wide->graph->nodes as $node) {
            if (!\array_key_exists($node->id, $wide->elements)) {
                $ticks[$node->label] = $node->x;
                continue;
            }
            $old = $normal->graph->nodes[$node->id];
            $this->assertSame((2 * $old->w) + 0.625, $node->w);
            $this->assertSame($normal->origin($old->id), $wide->origin($node->id));
            $fields[] = $node;
        }
        $this->assertSame([53.6875, 21.6875, 53.6875], \array_column($fields, 'x'));
        $this->assertStringNotContainsString("\n", $fields[0]->drawLabel);
        $this->assertStringContainsString("\n", $normal->graph->nodes[1]->drawLabel);
        $this->assertSame(39.5, $ticks[3]);
        $this->assertSame(67.875, $ticks[0]);
        $this->assertEquals(self::scene(new MermaidConfiguration()), self::scene(new MermaidConfiguration(packetBitWidth: 32.0)));
    }

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
        $svg = NativeFixtureExport::render($prefix.$body, GraphExportFormat::Svg);
        $this->assertStringContainsString('viewBox="0 0 76 ', $svg);
        $reset = NativeFixtureExport::render($prefix."%%{init: {\"packet\": {\"bitWidth\": 32}}}%%\n".$body, GraphExportFormat::Svg);
        $this->assertStringContainsString('viewBox="0 0 44 ', $reset);
        $this->assertStringStartsWith("\x89PNG", NativeFixtureExport::render($prefix.$body, GraphExportFormat::Png));
        $rows = NativeFixtureRows::render($prefix.$body, width: 24);
        $this->assertStringContainsString('Payload', \implode("\n", $rows->rows));
        $this->assertContains(8, $rows->origins);
        $this->assertSame($configuration, $configuration->overlay(new MermaidConfiguration()));
        $this->assertSame(48.5, new MermaidConfiguration()->overlay($configuration)->packetBitWidth);
        $this->assertSame(32.0, $configuration->overlay(new MermaidConfiguration(packetBitWidth: 32.0))->packetBitWidth);
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalid(): iterable
    {
        yield 'zero' => [0];
        yield 'below minimum' => [0.5];
        yield 'above maximum' => [1000.5];
        yield 'string' => ['32'];
        yield 'boolean' => [true];
        yield 'infinite' => [\INF];
        yield 'nan' => [\NAN];
    }

    #[DataProvider('invalid')]
    public function testInvalidWidthsAreRejected(mixed $value): void
    {
        $this->expectException(MermaidSyntaxError::class);
        $this->expectExceptionMessageIsOrContains('Packet bitWidth must be a finite number from 1 to 1000');
        MermaidConfiguration::parse((object) ['packet' => (object) ['bitWidth' => $value]], new MermaidStatement('', offset: 0, line: 0, column: 0));
    }

    public function testWidthCannotApplyToAnotherFamily(): void
    {
        $this->expectException(MermaidSyntaxError::class);
        $this->expectExceptionMessageIsOrContains('does not apply');
        new MermaidConfiguration(packetBitWidth: 32.0)->validateFamily('other');
    }
}
