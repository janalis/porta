<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Graph\GraphExportFormat;
use Porta\GraphViewer\Mermaid\MermaidDrawing;
use Porta\GraphViewer\Mermaid\MermaidOutline;
use Porta\GraphViewer\Mermaid\MermaidScene;
use Porta\GraphViewer\Mermaid\MermaidStatement;
use Porta\GraphViewer\Mermaid\MermaidSyntaxError;
use Porta\GraphViewer\Mermaid\MermaidTask;
use Porta\GraphViewer\Mermaid\NativeMermaidRenderer;
use Porta\GraphViewer\Mermaid\XyDiagram;
use Porta\GraphViewer\Mermaid\XyGeometry;
use Porta\GraphViewer\Mermaid\XyLayout;
use Porta\GraphViewer\Mermaid\XyLexer;
use Porta\GraphViewer\Mermaid\XyParser;
use Porta\GraphViewer\Mermaid\XyScale;
use Porta\GraphViewer\Mermaid\XyValues;
use Porta\Help\Fence\FenceResult;
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

    public function testAliasesOrientationsMetadataQuotedListsAndSourceLocations(): void
    {
        $source = "%% intro\nXYCHART-BETA HORIZONTAL; title \"Sales; %% 漢\"\naccTitle: literal ; %% \"[\naccDescr {\nbody ; %% \"[\n}x-axis \"When [literal]\" [\"A, one\", \"B; #amp; two\"]\ny-axis \"Revenue --> literal\" -2.4 --> +3.5\n  LINE \"Named [series]\" [+1.3 \"First, label\", -.34 \"Second<br/>line\"] %% trailing\n bar [0, .6]";
        $chart = self::parse($source);
        $this->assertSame('horizontal', $chart->orientation);
        $this->assertSame(1, $chart->origin);
        $this->assertSame('Sales; %% 漢', $chart->metadata['title']['label']);
        $this->assertSame('literal ; %% "[', $chart->metadata['acctitle']['label']);
        $this->assertSame('body ; %% "[', $chart->metadata['accdescr']['label']);
        $this->assertSame(['A, one', 'B; & two'], $chart->categories);
        $this->assertSame([-2.4, 3.5], $chart->yRange);
        $this->assertSame('When [literal]', $chart->metadata['x-axis']['label']);
        $this->assertSame('Revenue --> literal', $chart->metadata['y-axis']['label']);
        $this->assertSame('Named [series]', $chart->series[0]['title']);
        $this->assertSame([1.3, -0.34], \array_column($chart->series[0]['values'], 'value'));
        $this->assertSame(['First, label', "Second\nline"], \array_column($chart->series[0]['values'], 'label'));
        $this->assertSame([7, 8], \array_column($chart->series, 'origin'));
        $statements = \iterator_to_array(new XyLexer($source)->statements());
        $plot = \array_find($statements, static fn ($statement): bool => \str_starts_with($statement->text, 'LINE'));
        $this->assertSame(7, $plot->line);
        $this->assertSame(2, $plot->column);
        $this->assertSame(\strpos($source, needle: 'LINE'), $plot->offset);
        $this->assertSame('vertical', self::parse('xychart vertical; line [1]')->orientation);
    }

    public function testNumericAxesAutoRangesCategoriesAndTruncation(): void
    {
        $chart = self::parse("xychart\nx-axis \"Time\" -2 --> 2\ny-axis Units\nline [10, 50]\nbar [25, 100, -10]");
        $this->assertNull($chart->categories);
        $this->assertSame([-2.0, 2.0], $chart->xRange);
        $this->assertSame([-10.0, 100.0], $chart->yRange);
        $this->assertSame('Units', $chart->metadata['y-axis']['label']);
        $truncated = self::parse("xychart\nx-axis [A, B, C, D]\nline [10, 50, 999, 800, 10000]\nbar [20, 30]");
        $this->assertCount(4, $truncated->series[0]['values']);
        $this->assertCount(2, $truncated->series[1]['values'], 'missing values remain blank, never fabricated zeroes');
        $this->assertSame([10.0, 999.0], $truncated->yRange);
        $this->assertSame([1.0, 3.0], self::parse("xychart\nline [1, 2, 3]\nline [4, 5]")->xRange);
        $redefined = self::parse("xychart\nx-axis [A, B]\nx-axis 10 --> 20\nx-axis Label\ny-axis 5 --> 5\nline [5]");
        $this->assertNull($redefined->categories);
        $this->assertSame([10.0, 20.0], $redefined->xRange);
        $this->assertSame([5.0, 5.0], $redefined->yRange);
        $geometry = new XyGeometry($redefined, left: 0.0, top: 0.0);
        $this->assertSame(['10', 0.0], $geometry->category(index: 0, count: 1));
        $this->assertSame('Go --> home', self::parse('xychart; x-axis "Go --> home"; line [1]')->metadata['x-axis']['label']);
    }

    public function testFiniteExtremeRangesConstantRangesAndClipping(): void
    {
        $this->assertSame(0.5, XyScale::fraction(2, [2.0, 2.0]));
        $this->assertSame(0.5, XyScale::fraction(0, [-1.0e308, 1.0e308]));
        $this->assertSame(1.0, XyScale::fraction(1.0e308, [0.0, 1.0e-300]));
        $this->assertSame(0.0, XyScale::fraction(-1.0e308, [0.0, 1.0]));
        $this->assertSame(0.0, XyScale::fraction(20, [10.0, 0.0]));
        $this->assertSame(1.0, XyScale::fraction(-10, [10.0, 0.0]));
        $this->assertSame(0.25, XyScale::fraction(7.5, [10.0, 0.0]));
        $this->assertSame(0.0, XyScale::value(0.5, [-1.0e308, 1.0e308]));
        $this->assertSame(5.0, XyScale::value(0.5, [5.0, 5.0]));
    }

    #[DataProvider('invalidDecimals')]
    public function testDecimalValidatorRejectsInvalidValuesAtTheAuthoredLocation(string $value): void
    {
        try {
            XyValues::number($value, new MermaidStatement($value, offset: 12, line: 3, column: 5));
            $this->fail('Invalid XY decimal accepted');
        } catch (MermaidSyntaxError $error) {
            $this->assertStringContainsString('Expected an XY decimal number', $error->getMessage());
            $this->assertSame(12, $error->offset);
            $this->assertSame(3, $error->sourceLine);
            $this->assertSame(5, $error->column);
        }
    }

    /** @return iterable<array{string}> */
    public static function invalidDecimals(): iterable
    {
        yield ['not a number'];
        yield ['1e2'];
    }

    #[DataProvider('orientations')]
    public function testBarBaselineLinePositionsAndPointLabels(string $orientation): void
    {
        $scene = self::scene('xychart '.$orientation."\nx-axis [A, B, C]\ny-axis -10 --> 10\nline \"Trend\" [-10, 0 \"Middle\", 10]\nbar \"Bars\" [-10, 0, 10]");
        $data = \array_column($scene->selectionGraph->nodes, column_key: null, index_key: 'name');
        $lineFirst = $data['xy-0-0'];
        $middle = $data['xy-0-1'];
        $lineLast = $data['xy-0-2'];
        $barFirst = $data['xy-1-0'];
        $barMiddle = $data['xy-1-1'];
        $barLast = $data['xy-1-2'];
        $this->assertSame('Trend · B: 0 (Middle)', $middle->label);
        $this->assertSame('Middle', $middle->drawLabel);
        $this->assertSame('', $lineFirst->drawLabel);
        $this->assertSame('', $barMiddle->drawLabel);
        $this->assertCount(6, $scene->elements);
        $this->assertSame(3, $scene->origin($middle->id));
        if ('vertical' === $orientation) {
            $this->assertSame(0.0, $barFirst->h);
            $this->assertSame(45.0, $barMiddle->h);
            $this->assertSame(90.0, $barLast->h);
            $this->assertSame($lineFirst->y, $barLast->y + ($barLast->h / 2));
            $this->assertGreaterThan($lineFirst->x, $lineLast->x);
            $this->assertLessThan($lineFirst->y, $lineLast->y);
        }
        if ('horizontal' === $orientation) {
            $this->assertSame(0.0, $barFirst->w);
            $this->assertSame(70.0, $barMiddle->w);
            $this->assertSame(140.0, $barLast->w);
            $this->assertSame($lineFirst->x, $barLast->x - ($barLast->w / 2));
            $this->assertGreaterThan($lineFirst->x, $lineLast->x);
            $this->assertGreaterThan($lineFirst->y, $lineLast->y);
        }
        $this->assertCount(4, $scene->graph->edges, 'two axes plus two connected line segments');
        $this->assertSame('@xyChart.plotColorPalette[0]', \array_last($scene->graph->edges)->color);
        $drawing = MermaidDrawing::graph($scene->graph, $scene->shapes);
        \iterator_to_array($drawing);
        $texts = \array_column($drawing->getReturn()->marks, 'text');
        $this->assertContains('Middle', $texts);
        $this->assertNotContains('Trend · B: 0 (Middle)', $texts, 'semantic labels are not painted over the data');
    }

    /** @return iterable<array{string}> */
    public static function orientations(): iterable
    {
        yield ['vertical'];
        yield ['horizontal'];
    }

    public function testBandsDeduplicatePositionsAndNumericHorizontalAxesRunUpward(): void
    {
        $chart = self::parse("xychart horizontal\nx-axis [A, A, B]\nline [10, 20, 30]");
        $geometry = new XyGeometry($chart, left: 0.0, top: 0.0);
        $this->assertSame($geometry->category(index: 0, count: 3), $geometry->category(index: 1, count: 3));
        $this->assertSame(['B', 0.75], $geometry->category(index: 2, count: 3));
        $numeric = new XyGeometry(self::parse('xychart horizontal; line [1, 2, 3]'), left: 0.0, top: 0.0);
        $this->assertGreaterThan($numeric->position(category: 1.0, value: 0.0)[1], $numeric->position(category: 0.0, value: 0.0)[1]);
    }

    public function testLineMarkersCanBeSelectedOverBarsAndAxesAreNotSelectable(): void
    {
        $scene = self::scene("xychart\nx-axis [A, B]\ny-axis 0 --> 10\nline [5, 10]\nbar [10, 10]");
        $point = \array_find($scene->graph->nodes, static fn ($node): bool => 'xy-0-0' === $node->name);
        $cols = 234;
        $rows = (int) \ceil($scene->graph->height / 2);
        $view = new ZoomPanModel($cols, (int) \ceil($scene->graph->height), [1.0]);
        $this->assertSame($point->id, MermaidOutline::nodeAt($scene, $view, $cols, $rows, (int) ($point->y / 2), (int) $point->x));
        $this->assertNull($scene->selectable(0));
        $this->assertNull(MermaidOutline::nodeAt($scene, $view, $cols, $rows, row: 0, col: 0));
        $barOnly = self::scene('xychart; bar [1, 2]');
        $this->assertCount(2, $barOnly->elements);
    }

    public function testLongCaptionsStayInsideTheCanvasAndSemanticLabelsAreBounded(): void
    {
        $scene = self::scene(
            'xychart horizontal; title "'
            .\str_repeat('Title ', times: 100)
            .'"; x-axis ["'
            .\str_repeat('Category ', times: 70)
            .'"]; y-axis "Value" 0 --> 10; line "'
            .\str_repeat('Named ', times: 100)
            .'" [5 "'
            .\str_repeat('Point ', times: 100)
            .'"]',
        );
        foreach ($scene->graph->nodes as $node) {
            $this->assertGreaterThanOrEqual(0, $node->x - ($node->w / 2));
            $this->assertGreaterThanOrEqual(0, $node->y - ($node->h / 2));
            $this->assertLessThanOrEqual($scene->graph->width, $node->x + ($node->w / 2));
            $this->assertLessThanOrEqual($scene->graph->height, $node->y + ($node->h / 2));
        }
        $point = \array_first($scene->selectionGraph->nodes);
        $this->assertLessThan(500, \mb_strlen($point->label));
        $this->assertStringContainsString('…', $point->label);
    }

    #[DataProvider('upstreamFixtures')]
    public function testPinnedUpstreamExamplesRenderWithoutCli(string $file): void
    {
        $scheduler = new FakeScheduler();
        $result = null;
        new NativeMermaidRenderer(new MermaidTask($scheduler))->rows(
            (string) \file_get_contents($file),
            width: 28,
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
        $files = \glob(__DIR__.'/../fixtures/mermaid/xy/*.mmd');
        foreach (false === $files ? [] : $files as $file) {
            yield \basename($file) => [$file];
        }
    }

    #[DataProvider('invalidSources')]
    public function testInvalidSyntaxAndResourceLimits(string $source, string $message): void
    {
        $this->expectException(MermaidSyntaxError::class);
        $this->expectExceptionMessageIsOrContains($message);
        self::parse($source);
    }

    /** @return iterable<array{string, string}> */
    public static function invalidSources(): iterable
    {
        yield ['', 'at least one plot'];
        yield ['wrong', 'Expected xychart'];
        yield ['xychart', 'at least one plot'];
        yield ['xychart; unknown', 'Expected XY title'];
        yield ['xychart; line', 'require a data array'];
        yield ['xychart; line []', 'nonempty items'];
        yield ['xychart; line [1,]', 'nonempty items'];
        yield ['xychart; line [1,,2]', 'nonempty items'];
        yield ['xychart; line [1e3]', 'Expected XY value'];
        yield ['xychart; line [abc]', 'Expected XY value'];
        yield ['xychart; line [1] suffix', 'closing bracket'];
        yield ['xychart; line [[1]]', 'Nested XY arrays'];
        yield ['xychart; line [1]]', 'Unexpected XY closing'];
        yield ["xychart\nline [1\n", 'Unclosed XY array'];
        yield ['xychart; line [1', 'Unclosed XY label'];
        yield ['xychart; title "unclosed', 'Unclosed XY label'];
        yield ['xychart; accDescr {unclosed', 'Unclosed XY label'];
        yield ['xychart; y-axis [A]; line [1]', 'requires a numeric range'];
        yield ['xychart; x-axis wrong --> 1; line [1]', 'numeric axis range'];
        yield ['xychart; y-axis 1 --> wrong; line [1]', 'numeric axis range'];
        yield ['xychart; line ['.\str_repeat('9', times: 400).']', 'must be finite'];
        yield ['xychart; y-axis 0 --> '.\str_repeat('9', times: 400).'; line [1]', 'must be finite'];
        yield ['xychart; line ['.\implode(',', \array_fill(0, count: 4097, value: '1')).']', 'XY item limit'];
        yield ['xychart; line ['.\implode(',', \array_fill(0, count: 2049, value: '1')).']; bar ['.\implode(',', \array_fill(0, count: 2049, value: '1')).']', 'XY point limit'];
        yield ["xychart\n".\str_repeat('x', times: 65_537), 'Statement exceeds'];
        yield ["xychart\n".\str_repeat('x', times: 65_538), 'Statement exceeds'];
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
    public function testNativeExportUsesDataGeometryAndEscapesLabels(GraphExportFormat $format): void
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
        if (GraphExportFormat::Svg === $format) {
            $this->assertStringContainsString('&lt;script&gt;', $result);
            $this->assertStringContainsString('>Last</text>', $result);
            $this->assertStringNotContainsString('<script>', $result);

            return;
        }
        $this->assertStringStartsWith("\x89PNG", $result);
    }

    /** @return iterable<array{GraphExportFormat}> */
    public static function formats(): iterable
    {
        yield [GraphExportFormat::Svg];
        yield [GraphExportFormat::Png];
    }
}
