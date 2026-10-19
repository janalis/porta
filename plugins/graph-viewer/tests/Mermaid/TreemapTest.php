<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use Janalis\Viz\Mermaid\MermaidLexer;
use Janalis\Viz\Mermaid\MermaidScene;
use Janalis\Viz\Mermaid\TreemapDiagram;
use Janalis\Viz\Mermaid\TreemapLayout;
use Janalis\Viz\Mermaid\TreemapParser;
use Janalis\Viz\Render\Format;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Mermaid\MermaidHitTest;
use Porta\GraphViewer\Mermaid\MermaidTask;
use Porta\GraphViewer\Mermaid\NativeMermaidRenderer;
use Porta\ImageViewer\ZoomPanModel;
use Porta\Tests\Support\FakeScheduler;
use Symfony\Component\Tui\Ansi\AnsiUtils;

final class TreemapTest extends TestCase
{
    private static function parse(string $source): TreemapDiagram
    {
        $parser = new TreemapParser();
        foreach (new MermaidLexer($source, chart: true)->statements() as $statement) {
            $parser->accept($statement);
        }

        return $parser->finish();
    }

    private static function scene(string $source): MermaidScene
    {
        $work = new TreemapLayout()->layout(self::parse($source));
        \iterator_to_array($work);

        return $work->getReturn();
    }

    public function testNestedHeadersDoNotOccludeLeafSelection(): void
    {
        $scene = self::scene("treemap\n\"Root\"\n  \"Group\"\n    \"Leaf\":10\n  \"Other\":10");
        $this->assertCount(4, $scene->elements);
        $this->assertCount(6, $scene->graph->nodes);
        [$root, $group, $leaf] = $scene->graph->nodes;
        $this->assertSame(6.0, $root->h);
        $this->assertGreaterThan($root->y + ($root->h / 2), $group->y - ($group->h / 2));
        $this->assertGreaterThan($group->y + ($group->h / 2), $leaf->y - ($leaf->h / 2));
        $this->assertNull($scene->selectable(4), 'full section backgrounds are decorative');
        $this->assertSame(3, $scene->origin(2));
        $rows = (int) \ceil($scene->graph->height / 2);
        $view = new ZoomPanModel(224, (int) $scene->graph->height, [1.0]);
        $this->assertSame(2, MermaidHitTest::nodeAt($scene, $view, cols: 224, rows: $rows, row: (int) ($leaf->y / 2), col: (int) $leaf->x));
        $this->assertSame(0, MermaidHitTest::nodeAt($scene, $view, cols: 224, rows: $rows, row: (int) ($root->y / 2), col: (int) $root->x));
        $this->assertSame([], $scene->graph->edges);
        $this->assertCount(0, self::scene('treemap')->elements);
    }

    #[DataProvider('upstreamExamples')]
    public function testPinnedUpstreamExamplesRenderNatively(string $path): void
    {
        $source = \file_get_contents($path);
        $this->assertIsString($source);
        $result = NativeFixtureRows::render($source, width: 30);
        $this->assertNotEmpty($result->rows);
        $this->assertCount(\count($result->rows), $result->origins);
        foreach ($result->rows as $row) {
            $this->assertLessThanOrEqual(30, AnsiUtils::visibleWidth($row));
        }
    }

    /** @return iterable<array{string}> */
    public static function upstreamExamples(): iterable
    {
        foreach (new \GlobIterator(__DIR__.'/../fixtures/mermaid/treemap/*.mmd') as $file) {
            yield [$file->getPathname()];
        }
    }

    public function testCancellationPreventsPublicationDuringHierarchyWork(): void
    {
        $scheduler = new FakeScheduler();
        $cancel = new NativeMermaidRenderer(new MermaidTask($scheduler))->render(
            "treemap\n\"Root\"\n".\str_repeat('  "Leaf":1'."\n", times: 3000),
            static function (): never {
                self::fail('Cancelled treemap published');
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
    public function testNativeExportsUseProportionalTilesAndEscapedColoredText(Format $format): void
    {
        $scheduler = new FakeScheduler();
        $result = null;
        new NativeMermaidRenderer(new MermaidTask($scheduler))->export(
            "treemap\n\"<script>\":4:::dark\n\"Other\":1\nclassDef dark fill:black,color:white,stroke:none;",
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
            $this->assertMatchesRegularExpression('/<text[^>]*fill="#ffffff"[^>]*>&lt;script&gt;<\/text>/', $result);

            return;
        }
        $this->assertStringStartsWith("\x89PNG", $result);
        $image = \imagecreatefromstring($result);
        $this->assertInstanceOf(\GdImage::class, $image);
        $this->assertSame(0, \imagecolorat($image, x: 100, y: 100));
    }

    /** @return iterable<array{Format}> */
    public static function exportFormats(): iterable
    {
        yield [Format::Svg];
        yield [Format::Png];
    }
}
