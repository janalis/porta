<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Graph\GraphExportFormat;
use Porta\GraphViewer\Graph\GraphNode;
use Porta\GraphViewer\Mermaid\MermaidDrawing;
use Porta\GraphViewer\Mermaid\MermaidMark;
use Porta\GraphViewer\Mermaid\MermaidOutline;
use Porta\GraphViewer\Mermaid\MermaidPalette;
use Porta\GraphViewer\Mermaid\MermaidPng;
use Porta\GraphViewer\Mermaid\MermaidScene;
use Porta\GraphViewer\Mermaid\MermaidSceneRows;
use Porta\GraphViewer\Mermaid\MermaidSyntaxError;
use Porta\GraphViewer\Mermaid\MermaidTask;
use Porta\GraphViewer\Mermaid\NativeMermaidRenderer;
use Porta\GraphViewer\Mermaid\QuadrantDiagram;
use Porta\GraphViewer\Mermaid\QuadrantLayout;
use Porta\GraphViewer\Mermaid\QuadrantLexer;
use Porta\GraphViewer\Mermaid\QuadrantParser;
use Porta\Help\Fence\FenceResult;
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

    public function testMetadataAxesUnicodeQuotedArrowsAndStatementSourceLocations(): void
    {
        $source = "%% intro\nQUADRANTCHART; x-Axis \"低い --> literal\" ---> \"高い\"; y-axis Bottom -->\n title Raw # ; %% literal unmatched [\naccTitle: accessible # ; %% literal\naccDescr {\nmultiline ; %% literal\n}\nquadrant-1 \"Top #amp; right\"\n  \"Point ; %% : 中文\": [0.25, 0.75]\n  Same: [0, 1]\n  Same: [1, 0]";
        $diagram = self::parse($source);
        $this->assertSame(1, $diagram->origin);
        $this->assertSame('低い --> literal', $diagram->labels['x-axis-low']['label']);
        $this->assertSame('高い', $diagram->labels['x-axis-high']['label']);
        $this->assertSame('Bottom ⟶', $diagram->labels['y-axis-low']['label']);
        $this->assertArrayNotHasKey('y-axis-high', $diagram->labels);
        $this->assertSame('Raw # ; %% literal unmatched [', $diagram->labels['title']['label']);
        $this->assertSame('accessible # ; %% literal', $diagram->labels['acctitle']['label']);
        $this->assertSame('multiline ; %% literal', $diagram->labels['accdescr']['label']);
        $this->assertSame('Top & right', $diagram->labels['quadrant-1']['label']);
        $this->assertSame(['Point ; %% : 中文', 'Same', 'Same'], \array_column($diagram->points, 'label'));
        $this->assertSame([8, 9, 10], \array_column($diagram->points, 'origin'));
        $tokens = \iterator_to_array(new QuadrantLexer($source)->statements());
        $point = \array_find($tokens, static fn ($statement): bool => \str_starts_with($statement->text, '"Point'));
        $this->assertSame(\strpos($source, needle: '"Point'), $point->offset);
        $this->assertSame(8, $point->line);
        $this->assertSame(2, $point->column);
        $this->assertSame('Only', self::parse("quadrantChart\ny-axis Only")->labels['y-axis-low']['label']);
        $this->assertSame('end', self::parse("quadrantChart\naccDescr: end")->labels['accdescr']['label']);
    }

    public function testClassDefinitionKeywordsAreCaseInsensitive(): void
    {
        $diagram = self::parse("quadrantChart\nA:::later: [0, 1]\nCLASSDEF later radius: 10\ncLaSsDeF later radius: 20, color: #f00");
        $this->assertSame(['radius' => 20.0, 'color' => '#ff0000'], $diagram->classes['later']);
        $this->assertSame('later', $diagram->points[0]['class']);
    }

    public function testSingleEndedAxisLabelsDecodeEntitiesOnlyOnce(): void
    {
        $diagram = self::parse("quadrantChart\nx-axis \"&amp;gt;\" -->");
        $this->assertSame('&gt; ⟶', $diagram->labels['x-axis-low']['label']);
        $this->assertArrayNotHasKey('x-axis-high', $diagram->labels);
    }

    public function testClassesResolveForwardReferencesAndInlineStylesOverrideOnlyTheirOwnProperties(): void
    {
        $source = "quadrantChart\nA:::later: [0.25, 0.75] radius: 20, color: #f00\nB:::later: [0.75, 0.25]\nC:::missing: [0.5, 0.5]\nclassDef later radius: 10, color: #123456, stroke-color: #ABC, stroke-width: 3px\nclassDef later radius: 15, color: #123456, stroke-color: #ABC, stroke-width: 3px";
        $diagram = self::parse($source);
        $this->assertSame('later', $diagram->points[0]['class']);
        $this->assertSame(['radius' => 20.0, 'color' => '#ff0000'], $diagram->points[0]['style']);
        $scene = self::scene($source);
        [$a, $b, $c] = \array_values($scene->graph->nodes);
        $this->assertSame(4.0, $a->w);
        $this->assertSame(3.0, $b->w);
        $this->assertSame(1.0, $c->w);
        $this->assertSame('#ff0000', $a->fillColor);
        $this->assertSame('#123456', $b->fillColor);
        $this->assertSame('#aabbcc', $a->color);
        $this->assertSame(0.3, $a->strokeWidth);
        $this->assertSame(0.0, $c->strokeWidth);
        $this->assertSame('@quadrantPointFill|textColor', $c->fillColor);
        $this->assertSame('@quadrantPointFill|textColor', $c->color);
        $drawing = MermaidDrawing::graph($scene->graph, $scene->shapes);
        \iterator_to_array($drawing);
        $marks = $drawing->getReturn()->marks;
        $point = \array_find_key($marks, static fn (MermaidMark $mark): bool => 'polygon' === $mark->kind && '#ff0000' === $mark->fill);
        $this->assertIsInt($point);
        $this->assertSame(0.3, $marks[$point]->strokeWidth);
        $this->assertSame('text', $marks[$point + 1]->kind);
        $this->assertGreaterThan($a->y + ($a->h / 2), $marks[$point + 1]->points[0][1]);
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
        $this->assertSame($overlap->id, MermaidOutline::nodeAt($scene, $view, $cols, $rows, (int) ($overlap->y / 2), (int) $overlap->x));
        $this->assertNull(MermaidOutline::nodeAt($scene, $view, $cols, $rows, row: 0, col: 0));
        $rowsWork = MermaidSceneRows::rows($scene->themed(MermaidPalette::defaults('quadrantChart')), width: 1, styles: Styles::dark());
        \iterator_to_array($rowsWork);
        $result = $rowsWork->getReturn();
        $this->assertStringContainsString('Overlap:[0.75,0.93]', \implode('', $result->rows));
        $this->assertCount(\count($result->rows), $result->origins);
        $this->assertContains(4, $result->origins);
    }

    public function testLongLabelsAndLargeStylesKeepEveryNodeWithinTheCanvasAndYield(): void
    {
        $scene = self::scene(
            "quadrantChart\ntitle "
            .\str_repeat('long ', times: 200)
            ."\nx-axis "
            .\str_repeat('axis ', times: 100)
            ."\ny-axis Bottom --> Top\nquadrant-1 "
            .\str_repeat('quadrant ', times: 100)
            ."\nA: [0, 1] radius: 1000, stroke-width: 1000px\nB: [1, 0] radius: 0, stroke-width: 0px",
        );
        foreach ($scene->graph->nodes as $node) {
            $this->assertGreaterThanOrEqual(0, $node->x - ($node->w / 2));
            $this->assertGreaterThanOrEqual(0, $node->y - ($node->h / 2));
            $this->assertLessThanOrEqual($scene->graph->width, $node->x + ($node->w / 2));
            $this->assertLessThanOrEqual($scene->graph->height, $node->y + ($node->h / 2));
        }
        $empty = self::scene('quadrantChart');
        $this->assertSame([], $empty->elements);
        $this->assertSame([], $empty->graph->nodes);
        $this->assertCount(5, $empty->graph->clusters);
    }

    public function testPointStrokeWidthReachesTheRasterBackend(): void
    {
        $scene = self::scene("quadrantChart\nA: [0.5, 0.5] radius: 10, color: #f00, stroke-color: #0f0, stroke-width: 10px");
        $drawing = MermaidDrawing::graph($scene->graph, $scene->shapes);
        \iterator_to_array($drawing);
        $work = new MermaidPng()->render($drawing->getReturn());
        \iterator_to_array($work);
        $image = \imagecreatefromstring($work->getReturn());
        $point = \array_first($scene->graph->nodes);
        $this->assertInstanceOf(\GdImage::class, $image);
        $this->assertSame(0x00_ff00, \imagecolorat($image, (int) ($point->x * 10) + 13, (int) ($point->y * 10)));
        $this->assertSame(0xff_0000, \imagecolorat($image, (int) ($point->x * 10), (int) ($point->y * 10)));
    }

    public function testEmptyChartsCenterQuadrantCaptionsAndPlaceTheXAxisAboveThePlot(): void
    {
        $scene = self::scene("quadrantChart\nx-axis Low --> High\nquadrant-1 Caption");
        $nodes = \array_column($scene->graph->nodes, column_key: null, index_key: 'name');
        $this->assertSame($scene->graph->clusters[0]->y0 + 25.0, $nodes['quadrant-1']->y);
        $this->assertLessThan($scene->graph->clusters[0]->y0, $nodes['x-axis-low']->y);
        $this->assertCount(0, $scene->selectionGraph->nodes);
    }

    public function testMultilineQuotedLabelsKeepTheFollowingStatementsLocations(): void
    {
        $source = "quadrantChart\n  \"First\nsecond\": [0.25, 0.75]; Other: [0.75, 0.25]";
        $statements = \iterator_to_array(new QuadrantLexer($source)->statements());
        $this->assertCount(3, $statements);
        $this->assertSame(2, $statements[2]->line);
        $this->assertSame(\strpos($source, needle: 'Other') - \strrpos($source, needle: "\n") - 1, $statements[2]->column);
        $this->assertSame(\strpos($source, needle: 'Other'), $statements[2]->offset);
        $this->assertSame(["First\nsecond", 'Other'], \array_column(self::parse($source)->points, 'label'));
    }

    #[DataProvider('upstreamFixtures')]
    public function testPinnedUpstreamExamplesRenderWithoutCli(string $file): void
    {
        $scheduler = new FakeScheduler();
        $result = null;
        new NativeMermaidRenderer(new MermaidTask($scheduler))->rows(
            (string) \file_get_contents($file),
            width: 30,
            success: static function (FenceResult $value) use (&$result): void {
                $result = $value;
            },
            error: static function (string $error): never {
                self::fail($error);
            },
        );
        while ([] !== $scheduler->scheduled) {
            $scheduler->fire();
        }
        $this->assertInstanceOf(FenceResult::class, $result);
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

    #[DataProvider('invalidSources')]
    public function testMalformedSyntaxAndBounds(string $source, string $message): void
    {
        $this->expectException(MermaidSyntaxError::class);
        $this->expectExceptionMessageIsOrContains($message);
        self::parse($source);
    }

    /** @return iterable<array{string, string}> */
    public static function invalidSources(): iterable
    {
        yield ['', 'Expected quadrantChart'];
        yield ['wrong', 'Expected quadrantChart'];
        yield ["quadrantChart\nx-axis --> High", 'axis requires a low label'];
        yield ["quadrantChart\nP: [", 'Invalid quadrant statement'];
        yield ["quadrantChart\nnot a point", 'Expected quadrant point'];
        yield ["quadrantChart\nP: [2, 0]", 'between zero and one'];
        yield ["quadrantChart\nP: [0, -1]", 'between zero and one'];
        yield ["quadrantChart\nP: [0, 2]", 'between zero and one'];
        yield ["quadrantChart\nP: [-1, 0]", 'between zero and one'];
        yield ["quadrantChart\nP: [".\str_repeat('9', times: 400).', 0]', 'finite'];
        yield ["quadrantChart\nP: [0, ".\str_repeat('9', times: 400).']', 'finite'];
        yield ["quadrantChart\nP: [0, 0] unsupported: yes", 'unsupported quadrant point style'];
        yield ["quadrantChart\nP: [0, 0] color: red", 'hex color'];
        yield ["quadrantChart\nP: [0, 0] stroke-color: #12345", 'hex color'];
        yield ["quadrantChart\nP: [0, 0] radius: -1", 'requires an integer'];
        yield ["quadrantChart\nP: [0, 0] stroke-width: 3", 'integer pixels'];
        yield ["quadrantChart\nP: [0, 0] radius: 1001", '1000 pixel limit'];
        yield ["quadrantChart\nP: [0, 0] stroke-width: 1001px", '1000 pixel limit'];
        yield ["quadrantChart\n".\str_repeat('x', times: 65_537), 'Statement exceeds'];
        yield ["quadrantChart\ntitle Literal %%".\str_repeat('x', times: 65_537), 'Statement exceeds'];
        yield ["quadrantChart\n".\str_repeat("P: [0, 0]\n", times: 4097), 'quadrant point limit'];
        $classes = 'quadrantChart';
        for ($index = 0; $index < 4097; ++$index) {
            $classes .= "\nclassDef c".$index.' radius: 1';
        }
        yield [$classes, 'quadrant class limit'];
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
    public function testNativeExportCarriesPointGeometryAndStyles(GraphExportFormat $format): void
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
        if (GraphExportFormat::Svg === $format) {
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

    /** @return iterable<array{GraphExportFormat}> */
    public static function exportFormats(): iterable
    {
        yield [GraphExportFormat::Svg];
        yield [GraphExportFormat::Png];
    }

    public function testCaptionKindsTakeTheirPinnedFontSizes(): void
    {
        $body = "quadrantChart\ntitle T\nx-axis Low --> High\ny-axis Down --> Up\nquadrant-1 Q\nA: [0.5, 0.5]";
        $scales = static fn (MermaidScene $scene): array => \array_column(\array_map(static fn (GraphNode $node): array => [$node->name, $node->fontScale], $scene->graph->nodes), 1, 0);
        $plain = $scales(NativeFixtureScene::render($body));
        $this->assertSame([1.0], \array_values(\array_unique($plain)));
        $sized = NativeFixtureScene::render("---\nconfig:\n  quadrantChart:\n    titleFontSize: 40\n    quadrantLabelFontSize: 8\n    xAxisLabelFontSize: 24\n    yAxisLabelFontSize: 32\n    pointLabelFontSize: 18\n---\n"
        .$body);
        $scaled = $scales($sized);
        $this->assertSame([2.0, 0.5, 1.5, 1.5, 2.0, 2.0], [$scaled['title'], $scaled['quadrant-1'], $scaled['x-axis-low'], $scaled['x-axis-high'], $scaled['y-axis-low'], $scaled['y-axis-high']]);
        $point = \array_find($sized->graph->nodes, static fn (GraphNode $node): bool => \str_starts_with($node->name, 'point-'));
        $this->assertSame(1.5, $point?->fontScale);
        $title = \array_find($sized->graph->nodes, static fn (GraphNode $node): bool => 'title' === $node->name);
        $this->assertSame(8.0, $title?->h, 'the scaled caption sizes its box');
        $zero = $scales(NativeFixtureScene::render("---\nconfig:\n  quadrantChart:\n    titleFontSize: 0\n---\n".$body));
        $this->assertSame(1.0, $zero['title']);
        $text = $scales(NativeFixtureScene::render("---\nconfig:\n  quadrantChart:\n    titleFontSize: '40'\n---\n".$body));
        $this->assertSame(2.0, $text['title'], 'numeric text reads as its number, as pinned arithmetic does');
    }
}
