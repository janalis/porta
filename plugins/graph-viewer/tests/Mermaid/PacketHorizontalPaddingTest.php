<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Graph\GraphExportFormat;
use Porta\GraphViewer\Graph\GraphNode;
use Porta\GraphViewer\Mermaid\MermaidConfiguration;
use Porta\GraphViewer\Mermaid\MermaidLexer;
use Porta\GraphViewer\Mermaid\MermaidOutline;
use Porta\GraphViewer\Mermaid\MermaidScene;
use Porta\GraphViewer\Mermaid\MermaidStatement;
use Porta\GraphViewer\Mermaid\MermaidSyntaxError;
use Porta\GraphViewer\Mermaid\PacketLayout;
use Porta\GraphViewer\Mermaid\PacketParser;
use Porta\ImageViewer\ZoomPanModel;

final class PacketHorizontalPaddingTest extends TestCase
{
    private static function scene(MermaidConfiguration $configuration): MermaidScene
    {
        $parser = new PacketParser($configuration);
        foreach (new MermaidLexer("packet\n0-3: \"Longer label wraps\"\n+8: \"Payload\"", chart: true)->statements() as $statement) {
            $parser->accept($statement);
        }
        $work = new PacketLayout($configuration)->layout($parser->finish());
        \iterator_to_array($work);

        return $work->getReturn();
    }

    public function testPaddingShrinksOnlyPaintedFieldsAndTheirWrappingAndTickExtents(): void
    {
        $plain = self::scene(new MermaidConfiguration(packetBitsPerRow: 8, packetPaddingX: 0.0));
        $padded = self::scene(new MermaidConfiguration(packetBitsPerRow: 8, packetPaddingX: 64.0));
        $mirrored = self::scene(new MermaidConfiguration(packetBitOrder: 'descending', packetBitsPerRow: 8, packetPaddingX: 64.0));
        $this->assertSame($plain->elements, $padded->elements);
        $this->assertSame($plain->legend, $padded->legend);
        $this->assertSame($plain->graph->width, $padded->graph->width);
        $fields = $padded->selectionGraph->nodes;
        $this->assertSame([8.0, 8.0, 8.0], \array_column($fields, 'w'));
        $this->assertSame([10.0, 26.0, 10.0], \array_column($fields, 'x'));
        $this->assertSame([26.0, 10.0, 26.0], \array_column($mirrored->selectionGraph->nodes, 'x'));
        $view = new ZoomPanModel(44, (int) \ceil($padded->graph->height), [1.0]);
        $rows = (int) \ceil($padded->graph->height / 2);
        $this->assertNull(MermaidOutline::nodeAt($padded, $view, cols: 44, rows: $rows, row: (int) ($fields[0]->y / 2), col: 18));
        $this->assertSame($fields[0]->id, MermaidOutline::nodeAt($padded, $view, cols: 44, rows: $rows, row: (int) ($fields[0]->y / 2), col: 10));
        $this->assertSame(8.0, $fields[1]->x - ($fields[1]->w / 2) - ($fields[0]->x + ($fields[0]->w / 2)));
        $this->assertGreaterThan(\substr_count($plain->graph->nodes[0]->drawLabel, needle: "\n"), \substr_count($fields[0]->drawLabel, needle: "\n"));
        $end = \array_find($padded->graph->nodes, static fn (GraphNode $node): bool => '3' === $node->label);
        $this->assertInstanceOf(GraphNode::class, $end);
        $this->assertSame(12.5, $end->x);
        $minimum = self::scene(new MermaidConfiguration(packetBitsPerRow: 8, packetPaddingX: 1000.0));
        $this->assertSame([4.0, 4.0, 4.0], \array_column($minimum->selectionGraph->nodes, 'w'));
        $tiny = self::scene(new MermaidConfiguration(packetBitsPerRow: 8, packetBitWidth: 1.0, packetPaddingX: 1000.0));
        $this->assertSame([0.5, 0.5, 0.5], \array_column($tiny->selectionGraph->nodes, 'w'));
        $this->assertEquals(self::scene(new MermaidConfiguration()), self::scene(new MermaidConfiguration(packetPaddingX: 5.0)));
    }

    public function testZeroAndFractionalPaddingSurviveNativeConfigurationMerging(): void
    {
        $prefix = "---\nconfig:\n  packet:\n    paddingX: 64\n    showBits: false\n---\n";
        $body = "packet\n0-3: \"Flag\"\n+28: \"Payload\"";
        $svg = NativeFixtureExport::render($prefix.$body, GraphExportFormat::Svg);
        $this->assertStringContainsString('6,2 14,2 14,10 6,10', $svg);
        $zero = $prefix."%%{init: {\"packet\": {\"paddingX\": 0}}}%%\n".$body;
        $this->assertStringContainsString('6,2 22,2 22,10 6,10', NativeFixtureExport::render($zero, GraphExportFormat::Svg));
        $this->assertStringStartsWith("\x89PNG", NativeFixtureExport::render($prefix.$body, GraphExportFormat::Png));
        $rows = NativeFixtureRows::render($zero, width: 24);
        $this->assertStringContainsString('Payload', \implode("\n", $rows->rows));
        $this->assertContains(9, $rows->origins);
        $statement = new MermaidStatement('', offset: 0, line: 0, column: 0);
        foreach ([0, 2.5, 1000] as $value) {
            $configuration = MermaidConfiguration::parse((object) ['packet' => (object) ['paddingX' => $value]], $statement);
            $this->assertSame((float) $value, $configuration->packetPaddingX);
            $this->assertSame($configuration, $configuration->overlay(new MermaidConfiguration()));
            $this->assertSame((float) $value, new MermaidConfiguration()->overlay($configuration)->packetPaddingX);
            $this->assertSame(0.0, $configuration->overlay(new MermaidConfiguration(packetPaddingX: 0.0))->packetPaddingX);
        }
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalid(): iterable
    {
        yield 'negative' => [-0.5];
        yield 'large' => [1000.5];
        yield 'string' => ['5'];
        yield 'boolean' => [false];
        yield 'infinite' => [\INF];
        yield 'nan' => [\NAN];
    }

    #[DataProvider('invalid')]
    public function testInvalidHorizontalPaddingIsRejected(mixed $value): void
    {
        $this->expectException(MermaidSyntaxError::class);
        $this->expectExceptionMessageIsOrContains('Packet paddingX must be a finite number from 0 to 1000');
        MermaidConfiguration::parse((object) ['packet' => (object) ['paddingX' => $value]], new MermaidStatement('', offset: 0, line: 0, column: 0));
    }

    public function testZeroHorizontalPaddingCannotStyleAnotherFamily(): void
    {
        $this->expectException(MermaidSyntaxError::class);
        $this->expectExceptionMessageIsOrContains('does not apply');
        new MermaidConfiguration(packetPaddingX: 0.0)->validateFamily('other');
    }
}
