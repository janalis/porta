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

final class PacketRowHeightTest extends TestCase
{
    private static function scene(MermaidConfiguration $configuration): MermaidScene
    {
        $parser = new PacketParser($configuration);
        foreach (new MermaidLexer("packet\n0-11: \"Payload\"\n+4: \"Trailer\"", chart: true)->statements() as $statement) {
            $parser->accept($statement);
        }
        $work = new PacketLayout($configuration)->layout($parser->finish());
        \iterator_to_array($work);

        return $work->getReturn();
    }

    public function testRowHeightScalesFieldsAndFollowingRowsWithoutChangingTheirIdentity(): void
    {
        $normal = self::scene(new MermaidConfiguration(packetShowBits: false, packetBitsPerRow: 8));
        $tall = self::scene(new MermaidConfiguration(packetShowBits: false, packetBitsPerRow: 8, packetRowHeight: 64.0));
        $this->assertSame([16.0, 16.0, 16.0], \array_column($tall->graph->nodes, 'h'));
        $this->assertSame([10.0, 30.0, 30.0], \array_column($tall->graph->nodes, 'y'));
        $this->assertSame(42.0, $tall->graph->height);
        $this->assertSame($normal->graph->width, $tall->graph->width);
        $this->assertSame($normal->elements, $tall->elements);
        $this->assertSame($normal->legend, $tall->legend);
        foreach ($tall->graph->nodes as $node) {
            $before = $normal->graph->nodes[$node->id];
            $this->assertSame([$before->x, $before->w, $before->name, $before->label], [$node->x, $node->w, $node->name, $node->label]);
            $this->assertSame($normal->origin($before->id), $tall->origin($node->id));
        }
        $this->assertEquals(self::scene(new MermaidConfiguration()), self::scene(new MermaidConfiguration(packetRowHeight: 32.0)));
        $fractional = self::scene(new MermaidConfiguration(packetRowHeight: 65.5));
        $this->assertSame(16.375, $fractional->graph->nodes[0]->h);
        $small = self::scene(new MermaidConfiguration(packetBitsPerRow: 1, packetRowHeight: 1.0));
        foreach ($small->graph->nodes as $node) {
            if (!\array_key_exists($node->id, $small->elements)) {
                continue;
            }
            $this->assertSame((float) (2 * (\substr_count($node->drawLabel ?? '', needle: "\n") + 3)), $node->h);
        }
    }

    public function testFrontMatterAndDirectiveOverridesReachExportsAndCells(): void
    {
        $prefix = "---\nconfig:\n  packet:\n    rowHeight: 64\n    showBits: false\n---\n";
        $body = "packet\n0-31: \"Payload\"";
        $svg = NativeFixtureExport::render($prefix.$body, GraphExportFormat::Svg);
        $this->assertStringContainsString('viewBox="0 0 140 22"', $svg);
        $reset = NativeFixtureExport::render($prefix."%%{init: {\"packet\": {\"rowHeight\": 32}}}%%\n".$body, GraphExportFormat::Svg);
        $this->assertStringContainsString('viewBox="0 0 140 14"', $reset);
        $this->assertStringStartsWith("\x89PNG", NativeFixtureExport::render($prefix.$body, GraphExportFormat::Png));
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

    /** @return iterable<string, array{mixed}> */
    public static function invalid(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
        yield 'large' => [1000.5];
        yield 'string' => ['32'];
        yield 'boolean' => [true];
        yield 'infinite' => [\INF];
        yield 'nan' => [\NAN];
    }

    #[DataProvider('invalid')]
    public function testInvalidHeightsAreRejected(mixed $value): void
    {
        $this->expectException(MermaidSyntaxError::class);
        $this->expectExceptionMessageIsOrContains('Packet rowHeight must be a finite number from 1 to 1000');
        MermaidConfiguration::parse((object) ['packet' => (object) ['rowHeight' => $value]], new MermaidStatement('', offset: 0, line: 0, column: 0));
    }

    public function testHeightCannotApplyToAnotherFamily(): void
    {
        $this->expectException(MermaidSyntaxError::class);
        $this->expectExceptionMessageIsOrContains('does not apply');
        new MermaidConfiguration(packetRowHeight: 32.0)->validateFamily('other');
    }
}
