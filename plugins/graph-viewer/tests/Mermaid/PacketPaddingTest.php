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

final class PacketPaddingTest extends TestCase
{
    public function testRowGapsComposeWithSizingAndDescendingOrderWithoutChangingFields(): void
    {
        foreach ([0.0, 2.5, 10.0] as $padding) {
            $configuration = new MermaidConfiguration(packetShowBits: false, packetBitOrder: 'descending', packetBitsPerRow: 8, packetBitWidth: 48.0, packetRowHeight: 64.0, packetPaddingY: $padding);
            $parser = new PacketParser($configuration);
            foreach (new MermaidLexer("packet\n0-7: \"First\"\n+8: \"Second\"", chart: true)->statements() as $statement) {
                $parser->accept($statement);
            }
            $work = new PacketLayout($configuration)->layout($parser->finish());
            \iterator_to_array($work);
            $scene = $work->getReturn();
            [$first, $second] = $scene->graph->nodes;
            $gap = $padding * 0.8;
            $this->assertSame($gap, $second->y - ($second->h / 2) - ($first->y + ($first->h / 2)));
            $this->assertSame([47.375, 16.0, 29.6875], [$first->w, $first->h, $first->x]);
            $this->assertSame([$first->w, $first->h, $first->x], [$second->w, $second->h, $second->x]);
            $this->assertSame(34.0 + (2 * $gap), $scene->graph->height);
            $this->assertSame([1, 2], \array_values($scene->elements));
            $this->assertSame(1, $scene->origin($first->id));
            $this->assertSame(2, $scene->origin($second->id));
        }
    }

    public function testZeroSurvivesMergingAndNativeRenderingKeepsBitLabelSpace(): void
    {
        $prefix = "---\nconfig:\n  packet:\n    paddingY: 10\n    showBits: false\n---\n";
        $body = "packet\n0-31: \"First\"\n+32: \"Second\"";
        $this->assertStringContainsString('viewBox="0 0 140 34"', NativeFixtureExport::render($prefix.$body, GraphExportFormat::Svg));
        $zero = $prefix."%%{init: {\"packet\": {\"paddingY\": 0}}}%%\n".$body;
        $this->assertStringContainsString('viewBox="0 0 140 18"', NativeFixtureExport::render($zero, GraphExportFormat::Svg));
        $this->assertSame(NativeFixtureExport::render($body, GraphExportFormat::Svg), NativeFixtureExport::render("%%{init: {\"packet\": {\"paddingY\": 5}}}%%\n".$body, GraphExportFormat::Svg));
        $visible = "%%{init: {\"packet\": {\"paddingY\": 0}}}%%\n".$body;
        $svg = NativeFixtureExport::render($visible, GraphExportFormat::Svg);
        $this->assertStringContainsString('>31</text>', $svg);
        $this->assertStringContainsString('>32</text>', $svg);
        $this->assertStringContainsString('viewBox="0 0 140 26"', $svg);
        $this->assertStringStartsWith("\x89PNG", NativeFixtureExport::render($zero, GraphExportFormat::Png));
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
    public function testInvalidPaddingIsRejected(mixed $value): void
    {
        $this->expectException(MermaidSyntaxError::class);
        $this->expectExceptionMessageIsOrContains('Packet paddingY must be a finite number from 0 to 1000');
        MermaidConfiguration::parse((object) ['packet' => (object) ['paddingY' => $value]], new MermaidStatement('', offset: 0, line: 0, column: 0));
    }

    public function testZeroPaddingStillBelongsToThePacketFamily(): void
    {
        $this->expectException(MermaidSyntaxError::class);
        $this->expectExceptionMessageIsOrContains('does not apply');
        new MermaidConfiguration(packetPaddingY: 0.0)->validateFamily('other');
    }
}
