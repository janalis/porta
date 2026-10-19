<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Graph\GraphEdge;
use Porta\GraphViewer\Graph\GraphExportFormat;
use Porta\GraphViewer\Graph\GraphNode;
use Porta\GraphViewer\Mermaid\MermaidScene;
use Porta\GraphViewer\Mermaid\MermaidStatement;
use Porta\GraphViewer\Mermaid\MermaidSyntaxError;
use Porta\GraphViewer\Mermaid\MermaidTask;
use Porta\GraphViewer\Mermaid\NativeMermaidRenderer;
use Porta\GraphViewer\Mermaid\RadarDeclarations;
use Porta\GraphViewer\Mermaid\RadarDiagram;
use Porta\GraphViewer\Mermaid\RadarGrid;
use Porta\GraphViewer\Mermaid\RadarLayout;
use Porta\GraphViewer\Mermaid\RadarLexer;
use Porta\GraphViewer\Mermaid\RadarParser;
use Porta\Tests\Support\FakeScheduler;
use Symfony\Component\Tui\Ansi\AnsiUtils;

final class RadarTest extends TestCase
{
    private static function parse(string $source): RadarDiagram
    {
        $parser = new RadarParser();
        foreach (new RadarLexer($source)->statements() as $statement) {
            $parser->accept($statement);
        }

        return $parser->finish();
    }

    private static function scene(string $source): MermaidScene
    {
        $work = new RadarLayout()->layout(self::parse($source));
        \iterator_to_array($work);

        return $work->getReturn();
    }

    public function testAxesCurvesMetadataAliasesAndLocatedMultilineEntries(): void
    {
        $source = "%% intro\nradar-beta :\ntitle Results %% hidden\naccTitle: Accessible\naccDescr {\n Description\n}\ncurve S['Series, one']{\n C: 3,\n %% comment\n A 1, B: 2\n}, T{4,5,6}\naxis A[\"Alpha\"], B , C";
        $diagram = self::parse($source);
        $this->assertSame(1, $diagram->origin);
        $this->assertSame('Results', $diagram->metadata['title']['label']);
        $this->assertSame('Accessible', $diagram->metadata['acctitle']['label']);
        $this->assertSame('Description', $diagram->metadata['accdescr']['label']);
        $this->assertSame(['A', 'B', 'C'], \array_column($diagram->axes, 'name'));
        $this->assertSame(['Alpha', 'B', 'C'], \array_column($diagram->axes, 'label'));
        $this->assertSame('Series, one', $diagram->curves[0]['label']);
        $this->assertSame([1.0, 2.0, 3.0], \array_column($diagram->curves[0]['entries'], 'value'));
        $this->assertSame([10, 10, 8], \array_column($diagram->curves[0]['entries'], 'origin'));
        $this->assertSame(11, $diagram->curves[1]['origin']);
        $tokens = \iterator_to_array(new RadarLexer($source)->statements());
        $this->assertSame(\strpos($source, needle: 'curve'), $tokens[4]->offset);
        $this->assertSame(7, $tokens[4]->line);
        $this->assertSame([], self::parse('radar-beta:')->axes);
        $this->assertSame('', self::parse("radar-beta\ntitle")->metadata['title']['label']);
        $this->assertSame('single', self::parse("radar-beta\naccDescr: single")->metadata['accdescr']['label']);
    }

    public function testLabelsOptionsAndRepeatedReferencesKeepFirstValue(): void
    {
        $diagram = self::parse("radar-beta\naxis 0['Zero'], A[\"A \\\"quote\\\"\"], B['B \\'quote\\'']\ncurve S{B:3, A:2, 0:1, A:9}\nshowLegend false, ticks 100, max 10, min 1, graticule polygon");
        $this->assertSame(['Zero', 'A "quote"', "B 'quote'"], \array_column($diagram->axes, 'label'));
        $this->assertSame([1.0, 2.0, 3.0], \array_column($diagram->curves[0]['entries'], 'value'));
        $this->assertFalse($diagram->showLegend);
        $this->assertSame(32, $diagram->ticks);
        $this->assertSame(10.0, $diagram->maximum);
        $this->assertSame(1.0, $diagram->minimum);
        $this->assertSame('polygon', $diagram->graticule);
        $this->assertTrue(self::parse("radar-beta\nshowLegend true")->showLegend);
        $this->assertSame([], self::scene("radar-beta\nticks 0\ngraticule polygon")->graph->edges);
    }

    public function testPolarCoordinatesClippingAndSelectableSourceOrigins(): void
    {
        $scene = self::scene("radar-beta\naxis A, B, C, D\ncurve S{0,5,10,20}\nmax 10\nticks 0\ngraticule polygon\nshowLegend false");
        $nodes = \array_values($scene->selectionGraph->nodes);
        $this->assertCount(4, $nodes);
        $this->assertSame(['S · A: 0', 'S · B: 5', 'S · C: 10', 'S · D: 20'], \array_column($nodes, 'label'));
        $this->assertEqualsWithDelta(104.0, $nodes[0]->x, delta: 0.000_001);
        $this->assertEqualsWithDelta(102.0, $nodes[0]->y, delta: 0.000_001);
        $this->assertEqualsWithDelta(134.0, $nodes[1]->x, delta: 0.000_001);
        $this->assertEqualsWithDelta(162.0, $nodes[2]->y, delta: 0.000_001);
        $this->assertEqualsWithDelta(44.0, $nodes[3]->x, delta: 0.000_001);
        $lines = self::outlines($scene);
        $this->assertCount(5, $lines);
        $curve = $lines[4];
        $this->assertSame($curve->points[0], \array_last($curve->points));
        $this->assertCount(5, $curve->points);
        foreach ($nodes as $node) {
            $this->assertSame('point', $scene->shapes[$node->id]);
            $this->assertSame(2, $scene->origin($node->id));
        }
        $this->assertNull($scene->selectable(0), 'axis labels are decorative');
        $this->assertSame($nodes[0]->id, $scene->onLine(2));
        $this->assertCount(4, $scene->legend);
        $this->assertSame([], \array_filter($scene->graph->nodes, static fn (GraphNode $node): bool => \str_starts_with($node->name, 'swatch-')));
    }

    /** @return list<GraphEdge> the scene's outlines, without the fills beneath them */
    private static function outlines(MermaidScene $scene): array
    {
        return \array_values(\array_filter($scene->graph->edges, static fn (GraphEdge $edge): bool => null === $edge->ribbon));
    }

    public function testCircularGridAndSmoothCurvesCloseThroughEveryDatum(): void
    {
        $scene = self::scene("radar-beta\naxis A, B, C\ncurve S{1,2,3}\nticks 2");
        $lines = self::outlines($scene);
        $this->assertCount(6, $lines);
        $this->assertCount(65, $lines[0]->points);
        $curve = $lines[5];
        $fills = \array_values(\array_filter($scene->graph->edges, static fn (GraphEdge $edge): bool => null !== $edge->ribbon));
        $this->assertCount(3, $fills, 'each ring and the curve fill their area beneath the outline');
        $this->assertSame([$lines[0]->points, '@radar.graticuleColor@30'], [$fills[0]->ribbon, $fills[0]->color]);
        $this->assertSame([$curve->points, '@cScale0|=#000000@50'], [$fills[2]->ribbon, $fills[2]->color]);
        $this->assertTrue($fills[2]->decoration);
        $themed = NativeFixtureScene::render(
            "---\nconfig:\n  themeVariables:\n    radar:\n      curveOpacity: 0.8\n      graticuleOpacity: 1.5\n---\nradar-beta\naxis A, B, C\ncurve S{1,2,3}\nticks 1",
        );
        $colors = \array_column(\array_filter($themed->graph->edges, static fn (GraphEdge $edge): bool => null !== $edge->ribbon), 'color');
        $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $colors[0], 'an opacity above one clamps to opaque');
        $this->assertStringEndsWith('cc', \array_last($colors), 'the theme sets the curve opacity');
        $this->assertCount(25, $curve->points);
        foreach (\array_values($scene->selectionGraph->nodes) as $axis => $node) {
            $this->assertSame([$node->x, $node->y], $curve->points[$axis * 8]);
        }
        $this->assertSame($curve->points[0], \array_last($curve->points));
        $this->assertNotEmpty(\array_filter($scene->graph->nodes, static fn (GraphNode $node): bool => \str_starts_with($node->name, 'swatch-')));
        $polygon = self::scene("radar-beta\naxis A, B, C\ncurve S{1,2,3}\nticks 2\ngraticule polygon");
        $this->assertCount(4, self::outlines($polygon)[0]->points);
        $this->assertSame([], $scene->selectionGraph->edges);
    }

    public function testFlatAndExtremeRangesStayFiniteAndAxisLabelsAreBounded(): void
    {
        $huge = '1'.\str_repeat('0', times: 308);
        $label = \str_repeat('日本 long ', times: 100);
        $scene = self::scene("radar-beta\naxis A[\"".$label."\"], B, C\ncurve S{0,".$huge.",0}\nmax ".$huge);
        $this->assertSame($label, $scene->graph->nodes[0]->label);
        $this->assertStringContainsString('…', $scene->graph->nodes[0]->drawLabel);
        foreach ($scene->graph->nodes as $node) {
            $this->assertTrue(\is_finite($node->x));
            $this->assertTrue(\is_finite($node->y));
        }
        $flat = self::scene("radar-beta\naxis A\ncurve S{0}\nmax 0\nshowLegend false");
        $this->assertSame(104.0, \array_first($flat->selectionGraph->nodes)->x);
        $this->assertSame(102.0, \array_first($flat->selectionGraph->nodes)->y);
        $minimum = self::scene("radar-beta\naxis A\ncurve S{0}\nmin 5");
        $this->assertSame(102.0, \array_first($minimum->selectionGraph->nodes)->y);
    }

    #[DataProvider('invalidSources')]
    public function testInvalidSyntaxReferencesRangesAndLimitsAreRejected(string $source, string $error): void
    {
        $this->expectException(MermaidSyntaxError::class);
        $this->expectExceptionMessageIsOrContains($error);
        self::parse($source);
    }

    /** @return iterable<array{string, string}> */
    public static function invalidSources(): iterable
    {
        yield ['', 'Expected radar-beta'];
        yield ['radar', 'Invalid radar'];
        yield ["radar-beta\naxis A[\"unclosed]", 'Invalid radar'];
        yield ["radar-beta\naxis A\ncurve S{1", 'Invalid radar'];
        yield ["radar-beta\naxis A, A", 'unique ids'];
        yield ["radar-beta\naxis A\ncurve S{1,2}", 'one value'];
        yield ["radar-beta\ncurve S{1}", 'one value'];
        yield ["radar-beta\naxis A\ncurve S{B:1}", 'Unknown radar axis'];
        yield ["radar-beta\naxis A, B\ncurve S{A:1}", 'Missing radar value'];
        yield ["radar-beta\naxis A, B\ncurve S{1,B:2}", 'cannot mix'];
        yield ["radar-beta\naxis A\ncurve S{-1}", 'non-negative radar'];
        yield ["radar-beta\naxis A\ncurve S{1e2}", 'non-negative radar'];
        yield ["radar-beta\nticks 0.5", 'whole number'];
        yield ["radar-beta\nmax -1", 'finite non-negative'];
        yield ["radar-beta\nticks nope", 'finite non-negative'];
        yield ["radar-beta\nmax ".\str_repeat('9', times: 400), 'finite non-negative'];
        yield ["radar-beta\nshowLegend yes", 'Invalid radar showLegend'];
        yield ["radar-beta\ngraticule star", 'Invalid radar graticule'];
        yield ["radar-beta\nmin 10\nmax 1", 'maximum must'];
        yield ["radar-beta\ntitle ".\str_repeat('a', times: 65_537), 'size limit'];
        yield ["radar-beta\naxis ".\implode(',', \array_map(static fn (int $i): string => 'A'.$i, \range(0, end: 256))), 'at most 256'];
        yield ["radar-beta\ncurve S{".\str_repeat('1,', times: 4096).'1}', 'entry limit'];
        yield ["radar-beta\ncurve S{".\str_repeat('1,', times: 2048).'1},T{'.\str_repeat('1,', times: 2048).'1}', 'point or curve limit'];
    }

    public function testDirectDeclarationAndPathGuardsRejectMalformedInput(): void
    {
        try {
            RadarDeclarations::parse(new MermaidStatement('axis A B', offset: 0, line: 0, column: 0));
            $this->fail('Missing comma accepted');
        } catch (MermaidSyntaxError $error) {
            $this->assertStringContainsString('comma', $error->getMessage());
        }
        try {
            RadarDeclarations::parse(new MermaidStatement('axis ', offset: 0, line: 0, column: 0));
            $this->fail('Missing id accepted');
        } catch (MermaidSyntaxError $error) {
            $this->assertStringContainsString('named radar', $error->getMessage());
        }
        $this->expectException(\InvalidArgumentException::class);
        RadarGrid::edge([], color: '#000000');
    }

    public function testDeclarationListsAreBoundedBeforePublication(): void
    {
        $text = 'axis '.\implode(',', \array_map(static fn (int $i): string => 'A'.$i, \range(0, end: 4096)));
        $this->expectException(MermaidSyntaxError::class);
        $this->expectExceptionMessageIsOrContains('declaration limit');
        RadarDeclarations::parse(new MermaidStatement($text, offset: 0, line: 0, column: 0));
    }

    public function testParserReportsInvalidHeadersAndUnknownOptionsDirectly(): void
    {
        try {
            new RadarParser()->accept(new MermaidStatement('other', offset: 0, line: 0, column: 0));
            $this->fail('Wrong header accepted');
        } catch (MermaidSyntaxError $error) {
            $this->assertStringContainsString('Expected radar-beta', $error->getMessage());
        }
        $parser = new RadarParser();
        $parser->accept(new MermaidStatement('radar-beta', offset: 0, line: 0, column: 0));
        $this->expectException(MermaidSyntaxError::class);
        $this->expectExceptionMessageIsOrContains('Expected a radar option');
        $parser->accept(new MermaidStatement('unknown option', offset: 11, line: 1, column: 0));
    }

    #[DataProvider('upstreamExamples')]
    public function testUpstreamExamplesRenderWithoutTheCli(string $file): void
    {
        $source = \file_get_contents($file);
        $this->assertIsString($source);
        $rows = NativeFixtureRows::render($source, width: 32)->rows;
        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertLessThanOrEqual(32, AnsiUtils::visibleWidth($row));
        }
    }

    /** @return iterable<array{string}> */
    public static function upstreamExamples(): iterable
    {
        foreach (new \GlobIterator(__DIR__.'/../fixtures/mermaid/radar/*.mmd') as $file) {
            yield [$file->getPathname()];
        }
    }

    public function testCancellationPreventsPartialPublication(): void
    {
        $scheduler = new FakeScheduler();
        $cancel = new NativeMermaidRenderer(new MermaidTask($scheduler))->render(
            "radar-beta\naxis A,B,C\n".\str_repeat("curve S{1,2,3}\n", times: 1000),
            static function (): never {
                self::fail('Cancelled radar published');
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
    public function testNativeExportsEscapeLabelsAndDrawGridsAndCurves(GraphExportFormat $format): void
    {
        $result = NativeFixtureExport::render("radar-beta\naxis A[\"<script>\"], B, C\ncurve S[\"A &amp; B\"]{1,2,3}", $format);
        if (GraphExportFormat::Svg === $format) {
            $this->assertStringContainsString('&lt;script&gt;', $result);
            $this->assertStringContainsString('A &amp; B', $result);
            $this->assertStringContainsString('#8686ff', $result);
            $this->assertStringContainsString('<polyline', $result);
            $this->assertStringNotContainsString('<script>', $result);

            return;
        }
        $this->assertStringStartsWith("\x89PNG", $result);
        $image = \imagecreatefromstring($result);
        $this->assertInstanceOf(\GdImage::class, $image);
        $this->assertGreaterThan(0, \imagesx($image));
    }

    /** @return iterable<array{GraphExportFormat}> */
    public static function exportFormats(): iterable
    {
        yield [GraphExportFormat::Svg];
        yield [GraphExportFormat::Png];
    }
}
