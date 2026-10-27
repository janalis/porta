<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use Janalis\Viz\Mermaid\MermaidScene;
use Janalis\Viz\Mermaid\XyDiagram;
use Janalis\Viz\Mermaid\XyLayout;
use Janalis\Viz\Mermaid\XyLexer;
use Janalis\Viz\Mermaid\XyParser;
use Janalis\Viz\Render\Format;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Mermaid\MermaidHitTest;
use Porta\GraphViewer\Mermaid\MermaidTask;
use Porta\GraphViewer\Mermaid\NativeMermaidRenderer;
use Porta\ImageViewer\ZoomPanModel;
use Porta\Tests\Support\FakeScheduler;

final class XyTest extends TestCase
{
    private static function parse(string $source): XyDiagram
    {
        $parser = new XyParser();
        foreach (new XyLexer($source)->statements() as $statement) {
            $parser->accept($statement);
        }

        return $parser->finish();
    }

    private static function scene(string $source): MermaidScene
    {
        $work = new XyLayout()->layout(self::parse($source));
        \iterator_to_array($work);

        return $work->getReturn();
    }

    public function testLineMarkersCanBeSelectedOverBarsAndAxesAreNotSelectable(): void
    {
        $scene = self::scene("xychart\nx-axis [A, B]\ny-axis 0 --> 10\nline [5, 10]\nbar [10, 10]");
        $point = \array_find($scene->graph->nodes, static fn ($node): bool => 'xy-0-0' === $node->name);
        $cols = 234;
        $rows = (int) \ceil($scene->graph->height / 2);
        $view = new ZoomPanModel($cols, (int) \ceil($scene->graph->height), [1.0]);
        $this->assertSame($point->id, MermaidHitTest::nodeAt($scene, $view, $cols, $rows, (int) ($point->y / 2), (int) $point->x));
        $this->assertNull($scene->selectable(0));
        $this->assertNull(MermaidHitTest::nodeAt($scene, $view, $cols, $rows, row: 0, col: 0));
        $barOnly = self::scene('xychart; bar [1, 2]');
        $this->assertCount(2, $barOnly->elements);
    }

    #[DataProvider('upstreamFixtures')]
    public function testPinnedUpstreamExamplesRenderWithoutCli(string $file): void
    {
        $result = NativeFixtureRows::render((string) \file_get_contents($file), 28);
        $this->assertNotEmpty($result->rows);
        $this->assertCount(\count($result->rows), $result->origins);
    }

    /** @return iterable<string, array{string}> */
    public static function upstreamFixtures(): iterable
    {
        $files = \glob(__DIR__.'/../fixtures/mermaid/xy/*.mmd');
        foreach (false === $files ? [] : $files as $file) {
            yield \basename($file) => [$file];
        }
    }

    public function testMultilineLabelsAndCancellationKeepSourceMappingAndPublicationSafe(): void
    {
        $source = "xychart\n  title \"漢\n字\"; line [1 \"First\nsecond\", 2]\n%% comment at end";
        $statements = \iterator_to_array(new XyLexer($source)->statements());
        $this->assertSame(2, $statements[2]->line);
        $this->assertSame(\strpos($source, needle: 'line'), $statements[2]->offset);
        $this->assertSame(4, $statements[2]->column);
        $scheduler = new FakeScheduler();
        $cancel = new NativeMermaidRenderer(new MermaidTask($scheduler))->render(
            'xychart; line ['.\implode(',', \array_fill(0, count: 3000, value: '1')).']',
            static function (): never {
                self::fail('Cancelled XY scene published');
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

    #[DataProvider('formats')]
    public function testNativeExportUsesDataGeometryAndEscapesLabels(Format $format): void
    {
        $scheduler = new FakeScheduler();
        $result = null;
        new NativeMermaidRenderer(new MermaidTask($scheduler))->export(
            'xychart; title "<script>"; line [1 "First", 2 "Last"]',
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
            $this->assertStringContainsString('>Last</text>', $result);
            $this->assertStringNotContainsString('<script>', $result);

            return;
        }
        $this->assertStringStartsWith("\x89PNG", $result);
    }

    /** @return iterable<array{Format}> */
    public static function formats(): iterable
    {
        yield [Format::Svg];
        yield [Format::Png];
    }
}
