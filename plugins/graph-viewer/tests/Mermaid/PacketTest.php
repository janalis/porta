<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use Janalis\Viz\Layout\GraphNode;
use Janalis\Viz\Mermaid\MermaidLexer;
use Janalis\Viz\Mermaid\MermaidScene;
use Janalis\Viz\Mermaid\PacketDiagram;
use Janalis\Viz\Mermaid\PacketLayout;
use Janalis\Viz\Mermaid\PacketParser;
use Janalis\Viz\Render\Format;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Mermaid\MermaidHitTest;
use Porta\GraphViewer\Mermaid\MermaidTask;
use Porta\GraphViewer\Mermaid\NativeMermaidRenderer;
use Porta\ImageViewer\ZoomPanModel;
use Porta\Tests\Support\FakeScheduler;
use Symfony\Component\Tui\Ansi\AnsiUtils;

final class PacketTest extends TestCase
{
    private static function parse(string $source): PacketDiagram
    {
        $parser = new PacketParser();
        foreach (new MermaidLexer($source, chart: true)->statements() as $statement) {
            $parser->accept($statement);
        }

        return $parser->finish();
    }

    private static function scene(string $source): MermaidScene
    {
        $work = new PacketLayout()->layout(self::parse($source));
        \iterator_to_array($work);

        return $work->getReturn();
    }

    public function testBitLabelsAreDecorativeAndFieldsHaveProportionalHitRegions(): void
    {
        $scene = self::scene("packet\n0: \"A\"\n1-31: \"B\"");
        $nodes = \array_values($scene->selectionGraph->nodes);
        $this->assertSame(4.0, $nodes[0]->w);
        $this->assertSame(123.375, $nodes[1]->w);
        $this->assertSame(8.0, $nodes[0]->x);
        $this->assertSame(71.6875, $nodes[1]->x);
        $ticks = \array_values(\array_filter($scene->graph->nodes, static fn (GraphNode $node): bool => \str_starts_with($node->name, 'bit-')));
        $this->assertSame(['0', '1', '31'], \array_column($ticks, 'label'));
        $this->assertNull($scene->selectable($ticks[0]->id));
        $rows = (int) \ceil($scene->graph->height / 2);
        $view = new ZoomPanModel(140, (int) $scene->graph->height, [1.0]);
        $this->assertSame($nodes[0]->id, MermaidHitTest::nodeAt($scene, $view, cols: 140, rows: $rows, row: (int) ($nodes[0]->y / 2), col: 8));
        $this->assertSame($nodes[1]->id, MermaidHitTest::nodeAt($scene, $view, cols: 140, rows: $rows, row: (int) ($nodes[1]->y / 2), col: 100));
        $this->assertNull(MermaidHitTest::nodeAt($scene, $view, cols: 140, rows: $rows, row: 1, col: 8));
    }

    #[DataProvider('upstreamExamples')]
    public function testPinnedUpstreamSourcesRenderWithoutCli(string $path): void
    {
        $source = \file_get_contents($path);
        $this->assertIsString($source);
        $result = NativeFixtureRows::render($source, 28);
        $this->assertNotEmpty($result->rows);
        $this->assertCount(\count($result->rows), $result->origins);
        foreach ($result->rows as $row) {
            $this->assertLessThanOrEqual(28, AnsiUtils::visibleWidth($row));
        }
    }

    /** @return iterable<array{string}> */
    public static function upstreamExamples(): iterable
    {
        foreach (new \GlobIterator(__DIR__.'/../fixtures/mermaid/packet/*.mmd') as $file) {
            yield [$file->getPathname()];
        }
    }

    public function testCancelledLargeLayoutNeverPublishes(): void
    {
        $scheduler = new FakeScheduler();
        $cancel = new NativeMermaidRenderer(new MermaidTask($scheduler))->render(
            "packet\n+32768: \"Continued\"",
            static function (): never {
                self::fail('Cancelled packet published');
            },
            static function (string $error): never {
                self::fail($error);
            },
        );
        $scheduler->fire();
        $this->assertNotEmpty($scheduler->scheduled);
        $cancel();
        $this->assertSame([], $scheduler->scheduled);
    }

    #[DataProvider('exportFormats')]
    public function testExportDrawsPacketFieldsAndEscapesLabels(Format $format): void
    {
        $scheduler = new FakeScheduler();
        $result = null;
        new NativeMermaidRenderer(new MermaidTask($scheduler))->export(
            "packet\n0-15: \"<script>\"\n+16: \"Destination\"",
            $format,
            static function (string $value) use (&$result): void {
                $result = $value;
            },
            static function (string $error): never {
                self::fail($error);
            },
        );
        while ([] !== $scheduler->scheduled) {
            $scheduler->fire();
        }
        $this->assertIsString($result);
        if (Format::Svg === $format) {
            $this->assertStringContainsString('&lt;script&gt;', $result);
            $this->assertStringNotContainsString('<script>', $result);
            $this->assertStringContainsString('>31</text>', $result);
            $this->assertStringNotContainsString('0–15: ', $result);

            return;
        }
        $this->assertStringStartsWith("\x89PNG", $result);
        $image = \imagecreatefromstring($result);
        $this->assertInstanceOf(\GdImage::class, $image);
        $this->assertSame(0xef_efef, \imagecolorat($image, x: 100, y: 80));
    }

    /** @return iterable<array{Format}> */
    public static function exportFormats(): iterable
    {
        yield [Format::Svg];
        yield [Format::Png];
    }
}
