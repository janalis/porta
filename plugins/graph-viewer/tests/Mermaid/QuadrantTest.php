<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use Janalis\Viz\Mermaid\MermaidPalette;
use Janalis\Viz\Mermaid\MermaidScene;
use Janalis\Viz\Mermaid\QuadrantDiagram;
use Janalis\Viz\Mermaid\QuadrantLayout;
use Janalis\Viz\Mermaid\QuadrantLexer;
use Janalis\Viz\Mermaid\QuadrantParser;
use Janalis\Viz\Render\Format;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Mermaid\MermaidHitTest;
use Porta\GraphViewer\Mermaid\MermaidSceneRows;
use Porta\GraphViewer\Mermaid\MermaidTask;
use Porta\GraphViewer\Mermaid\NativeMermaidRenderer;
use Porta\ImageViewer\ZoomPanModel;
use Porta\Tests\Support\FakeScheduler;
use Porta\Ui\Theme\Styles;

final class QuadrantTest extends TestCase
{
    private static function parse(string $source): QuadrantDiagram
    {
        $parser = new QuadrantParser();
        foreach (new QuadrantLexer($source)->statements() as $statement) {
            $parser->accept($statement);
        }

        return $parser->finish();
    }

    private static function scene(string $source): MermaidScene
    {
        $work = new QuadrantLayout()->layout(self::parse($source));
        \iterator_to_array($work);

        return $work->getReturn();
    }

    public function testUnitSquareCoordinatesUseCartesianYAndDecorationsDoNotOccludeSelection(): void
    {
        $scene = self::scene("quadrantChart\nquadrant-1 Decoration\nNW: [0, 1]\nSE: [1, 0]\nOverlap: [0.75, 0.93]");
        [$nw, $se, $overlap] = \array_values($scene->selectionGraph->nodes);
        $this->assertSame(100.0, $se->x - $nw->x);
        $this->assertSame(100.0, $se->y - $nw->y);
        $this->assertSame($nw->x + 75.0, $overlap->x);
        $this->assertEqualsWithDelta($nw->y + 7.0, $overlap->y, delta: 0.000_001);
        // Pinned quadrants: a 1px internal cross under a 2px external frame.
        $this->assertSame([0.1, 0.1, 0.1, 0.1, 0.2], \array_column($scene->graph->clusters, 'strokeWidth'));
        $this->assertSame(['@quadrantInternalBorderStrokeFill', '@quadrantExternalBorderStrokeFill', 'none'], [
            $scene->graph->clusters[0]->color,
            $scene->graph->clusters[4]->color,
            $scene->graph->clusters[4]->fillColor,
        ]);
        $frame = $scene->graph->clusters[4];
        $this->assertSame([$scene->graph->clusters[1]->x0, $scene->graph->clusters[1]->y0, $scene->graph->clusters[3]->x1, $scene->graph->clusters[3]->y1], [
            $frame->x0,
            $frame->y0,
            $frame->x1,
            $frame->y1,
        ]);
        $configured = NativeFixtureScene::render(
            "---\nconfig:\n  quadrantChart:\n    quadrantInternalBorderStrokeWidth: 3\n    quadrantExternalBorderStrokeWidth: -4\n---\nquadrantChart\nA: [0.5, 0.5]",
        );
        $this->assertSame([0.3, 0.0], [$configured->graph->clusters[0]->strokeWidth, $configured->graph->clusters[4]->strokeWidth]);
        $this->assertCount(3, $scene->elements);
        $this->assertNull($scene->selectable(0));
        $this->assertSame(4, $scene->origin($overlap->id));
        $view = new ZoomPanModel((int) $scene->graph->width, (int) $scene->graph->height, [1.0]);
        $cols = (int) $scene->graph->width;
        $rows = (int) \ceil($scene->graph->height / 2);
        $this->assertSame($overlap->id, MermaidHitTest::nodeAt($scene, $view, $cols, $rows, (int) ($overlap->y / 2), (int) $overlap->x));
        $this->assertNull(MermaidHitTest::nodeAt($scene, $view, $cols, $rows, row: 0, col: 0));
        $rowsWork = MermaidSceneRows::rows($scene->themed(MermaidPalette::defaults('quadrantChart')), width: 1, styles: Styles::dark());
        \iterator_to_array($rowsWork);
        $result = $rowsWork->getReturn();
        $this->assertStringContainsString('Overlap:[0.75,0.93]', \implode('', $result->rows));
        $this->assertCount(\count($result->rows), $result->origins);
        $this->assertContains(4, $result->origins);
    }

    #[DataProvider('upstreamFixtures')]
    public function testPinnedUpstreamExamplesRenderWithoutCli(string $file): void
    {
        $result = NativeFixtureRows::render((string) \file_get_contents($file), 30);
        $this->assertNotEmpty($result->rows);
        $this->assertCount(\count($result->rows), $result->origins);
    }

    /** @return iterable<string, array{string}> */
    public static function upstreamFixtures(): iterable
    {
        $files = \glob(__DIR__.'/../fixtures/mermaid/quadrant/*.mmd');
        foreach (false === $files ? [] : $files as $file) {
            yield \basename($file) => [$file];
        }
    }

    public function testCancellationSuppressesLongLayoutPublication(): void
    {
        $scheduler = new FakeScheduler();
        $cancel = new NativeMermaidRenderer(new MermaidTask($scheduler))->render(
            "quadrantChart\n".\str_repeat("P: [0.5, 0.5]\n", times: 2000),
            static function (): never {
                self::fail('Cancelled request published a chart');
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
    public function testNativeExportCarriesPointGeometryAndStyles(Format $format): void
    {
        $scheduler = new FakeScheduler();
        $result = null;
        new NativeMermaidRenderer(new MermaidTask($scheduler))->export(
            "quadrantChart\nA: [0.5, 0.5] color: #ff0000, radius: 10, stroke-width: 0px, stroke-color: #00ff00",
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
            $this->assertStringContainsString('fill="#ff0000" stroke-width="0"', $result);
            $this->assertStringContainsString('>A</text>', $result);

            return;
        }
        $image = \imagecreatefromstring($result);
        $this->assertInstanceOf(\GdImage::class, $image);
        $scene = self::scene("quadrantChart\nA: [0.5, 0.5] color: #ff0000, radius: 10, stroke-width: 0px, stroke-color: #00ff00");
        $point = \array_first($scene->graph->nodes);
        $this->assertSame(0xff_0000, \imagecolorat($image, (int) ($point->x * 10), (int) ($point->y * 10)));
        $this->assertSame(0xff_0000, \imagecolorat($image, (int) (($point->x + ($point->w / 2)) * 10), (int) ($point->y * 10)), 'zero stroke must not draw a one pixel outline');
    }

    /** @return iterable<array{Format}> */
    public static function exportFormats(): iterable
    {
        yield [Format::Svg];
        yield [Format::Png];
    }
}
