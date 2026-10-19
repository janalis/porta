<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Graph\GraphExportFormat;
use Porta\GraphViewer\Graph\GraphNode;
use Porta\GraphViewer\Mermaid\MermaidDrawing;
use Porta\GraphViewer\Mermaid\MermaidLexer;
use Porta\GraphViewer\Mermaid\MermaidOutline;
use Porta\GraphViewer\Mermaid\MermaidScene;
use Porta\GraphViewer\Mermaid\MermaidSyntaxError;
use Porta\GraphViewer\Mermaid\MermaidTask;
use Porta\GraphViewer\Mermaid\NativeMermaidRenderer;
use Porta\GraphViewer\Mermaid\TreemapDiagram;
use Porta\GraphViewer\Mermaid\TreemapLayout;
use Porta\GraphViewer\Mermaid\TreemapParser;
use Porta\GraphViewer\Mermaid\TreemapTiles;
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

    public function testHierarchyMetadataThousandsValuesAndSourceLocations(): void
    {
        $source = "%% intro\nTREEMAP-BETA\ntitle Sales\naccTitle: Accessible\naccDescr {\n Description\n}\n  \"Root\"\n    \"Group\"\n      \"Same\": 1,000:::forward\n      'Same', 500.5\n    \"End\": 0\nclassDef forward fill:red;";
        $diagram = self::parse($source);
        $this->assertSame(1, $diagram->origin);
        $this->assertSame('Sales', $diagram->metadata['title']['label']);
        $this->assertSame('Accessible', $diagram->metadata['acctitle']['label']);
        $this->assertSame('Description', $diagram->metadata['accdescr']['label']);
        $this->assertSame(['Root', 'Group', 'Same', 'Same', 'End'], \array_column($diagram->nodes, 'label'));
        $this->assertSame([null, 0, 1, 1, 0], \array_column($diagram->nodes, 'parent'));
        $this->assertSame([0, 1, 2, 2, 1], \array_column($diagram->nodes, 'depth'));
        $this->assertSame([null, null, 1000.0, 500.5, 0.0], \array_column($diagram->nodes, 'value'));
        $this->assertSame([7, 8, 9, 10, 11], \array_column($diagram->nodes, 'origin'));
        $this->assertSame([0], $diagram->children[-1]);
        $tokens = \iterator_to_array(new MermaidLexer($source, chart: true)->statements());
        $leaf = \array_find($tokens, static fn ($statement): bool => \str_starts_with($statement->text, '"Same"'));
        $this->assertSame(9, $leaf->line);
        $this->assertSame(6, $leaf->column);
        $this->assertSame(\strpos($source, needle: '"Same"'), $leaf->offset);
        $this->assertSame('', self::parse("treemap\ntitle")->metadata['title']['label']);
        $this->assertSame('Single', self::parse("treemap\naccDescr: Single")->metadata['accdescr']['label']);
        $this->assertSame([], self::parse('treemap')->nodes);
    }

    public function testTopLevelAreasAreProportionalAndCoverTheCanvas(): void
    {
        $work = new TreemapTiles()->rectangles(self::parse("treemap\n\"Large\":4\n\"Small\":1"), top: 2.0);
        \iterator_to_array($work);
        [$boxes, $width, $height] = $work->getReturn();
        $this->assertSame(224.0, $width);
        $this->assertSame(144.0, $height);
        $this->assertSame(176.0, $boxes[0]['w']);
        $this->assertSame(44.0, $boxes[1]['w']);
        $this->assertSame(140.0, $boxes[0]['h']);
        $this->assertSame($boxes[0]['x'] + $boxes[0]['w'], $boxes[1]['x']);
        $this->assertSame(220.0 * 140.0, ($boxes[0]['w'] * $boxes[0]['h']) + ($boxes[1]['w'] * $boxes[1]['h']));
        $this->assertSame(4.0, ($boxes[0]['w'] * $boxes[0]['h']) / ($boxes[1]['w'] * $boxes[1]['h']));
    }

    public function testSectionAreasIncludeAllDescendantWeights(): void
    {
        $source = "treemap\n\"Large\"\n  \"Nested\"\n    \"A\":4\n\"Small\"\n  \"B\":1";
        $work = new TreemapTiles()->rectangles(self::parse($source), top: 2.0);
        \iterator_to_array($work);
        [$boxes] = $work->getReturn();
        $this->assertSame(176.0, $boxes[0]['w']);
        $this->assertSame(44.0, $boxes[3]['w']);
        $this->assertSame(4.0, ($boxes[0]['w'] * $boxes[0]['h']) / ($boxes[3]['w'] * $boxes[3]['h']));
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
        $this->assertSame(2, MermaidOutline::nodeAt($scene, $view, cols: 224, rows: $rows, row: (int) ($leaf->y / 2), col: (int) $leaf->x));
        $this->assertSame(0, MermaidOutline::nodeAt($scene, $view, cols: 224, rows: $rows, row: (int) ($root->y / 2), col: (int) $root->x));
        $this->assertSame([], $scene->graph->edges);
        $this->assertCount(0, self::scene('treemap')->elements);
    }

    public function testForwardClassesMergePropertiesAndColorNativeText(): void
    {
        $source = "treemap\n\"Root\":::parent\n  \"Dark\":80:::dark\n  \"Light\":20:::light\nclassDef parent stroke:red,stroke-width:8px,stroke-dasharray:5;\nclassDef dark fill:#000;\nclassDef dark color:white,stroke:none;\nclassDef light fill:white,color:#123456,stroke-width:2px;";
        $scene = self::scene($source);
        $this->assertSame('#000000', $scene->graph->nodes[1]->fillColor);
        $this->assertSame('#ffffff', $scene->graph->nodes[1]->textColor);
        $this->assertSame(0.0, $scene->graph->nodes[1]->strokeWidth);
        $this->assertSame('#123456', $scene->graph->nodes[2]->textColor);
        $this->assertSame(0.2, $scene->graph->nodes[2]->strokeWidth);
        $this->assertSame(0.8, $scene->graph->nodes[3]->strokeWidth);
        $this->assertTrue($scene->graph->nodes[3]->dashed);
        $this->assertSame('#ff0000', $scene->graph->nodes[3]->color);
        $drawing = MermaidDrawing::graph($scene->graph, $scene->shapes);
        \iterator_to_array($drawing);
        $marks = $drawing->getReturn()->marks;
        $dark = \array_find($marks, static fn ($mark): bool => 'Dark' === $mark->text);
        $light = \array_find($marks, static fn ($mark): bool => 'Light' === $mark->text);
        $this->assertSame('#ffffff', $dark->stroke);
        $this->assertSame('#123456', $light->stroke);
        $automatic = self::scene("treemap\n\"Dark\":1:::dark\n\"White\":1:::white\nclassDef dark fill:black,stroke-dasharray:0;\nclassDef white fill:white;");
        $this->assertSame('#ffffff', $automatic->graph->nodes[0]->textColor);
        $this->assertSame('#222222', $automatic->graph->nodes[1]->textColor);
        $this->assertFalse($automatic->graph->nodes[0]->dashed);
        $this->assertSame([], self::parse("treemap\nclassDef empty;")->classes['empty']);
        $this->assertNotEmpty(self::scene("treemap\n\"Unknown\":::missing")->graph->nodes);
    }

    public function testExtremeWeightsZeroRegionsAndRepeatedLabelsRemainFinite(): void
    {
        $large = '1'.\str_repeat('0', times: 308);
        $scene = self::scene("treemap\n\"Zero\":0\n\"Same\":".$large."\n\"Same\":".$large);
        $this->assertSame(0.0, $scene->graph->nodes[0]->w * $scene->graph->nodes[0]->h);
        $this->assertSame('', $scene->graph->nodes[0]->drawLabel);
        $this->assertSame($scene->graph->nodes[1]->w * $scene->graph->nodes[1]->h, $scene->graph->nodes[2]->w * $scene->graph->nodes[2]->h);
        foreach ($scene->graph->nodes as $node) {
            $this->assertTrue(\is_finite($node->x) && \is_finite($node->y));
            $this->assertTrue(\is_finite($node->w) && \is_finite($node->h));
            $this->assertGreaterThanOrEqual(0, $node->w);
            $this->assertGreaterThanOrEqual(0, $node->h);
        }
        $allZero = self::scene("treemap\n\"A\":0\n\"B\":0\n\"C\":0");
        $areas = \array_map(static fn (GraphNode $node): float => $node->w * $node->h, $allZero->selectionGraph->nodes);
        $this->assertEqualsWithDelta($areas[0], $areas[1], 0.000_001);
        $this->assertEqualsWithDelta($areas[1], $areas[2], 0.000_001);
    }

    public function testMultilineLongLabelsAreClippedInsideTheirTiles(): void
    {
        $label = \str_repeat('漢 long label ', times: 4000);
        $scene = self::scene("treemap\ntitle Overview\n\"".$label."\ncontinued\":4\n\"Tiny\":.00001\n\"Narrow\":1");
        $this->assertStringContainsString('continued', $scene->graph->nodes[0]->label);
        $this->assertStringContainsString('…', $scene->graph->nodes[0]->drawLabel);
        foreach ($scene->selectionGraph->nodes as $node) {
            $this->assertLessThanOrEqual($scene->graph->width, $node->x + ($node->w / 2));
            $this->assertLessThanOrEqual($scene->graph->height, $node->y + ($node->h / 2));
            if ('' === $node->drawLabel) {
                continue;
            }
            foreach (\explode("\n", $node->drawLabel) as $line) {
                $this->assertLessThanOrEqual((int) \floor($node->w - 4), AnsiUtils::visibleWidth($line));
            }
            $this->assertLessThanOrEqual((int) \floor(($node->h - 2) / 2), \count(\explode("\n", $node->drawLabel)));
        }
        $source = "treemap\n";
        for ($level = 0; $level < 20; ++$level) {
            $source .= \str_repeat(' ', $level).'"Level '.$level."\"\n";
        }
        $source .= \str_repeat(' ', times: 20).'"Leaf":1';
        $deep = self::scene($source);
        $this->assertGreaterThan(224.0, $deep->graph->width);
        $this->assertGreaterThan(0, $deep->graph->nodes[20]->h);
    }

    #[DataProvider('invalidSources')]
    public function testInvalidSyntaxStylesAndLimitsAreRejected(string $source, string $message): void
    {
        $this->expectException(MermaidSyntaxError::class);
        $this->expectExceptionMessageIsOrContains($message);
        self::parse($source);
    }

    /** @return iterable<array{string, string}> */
    public static function invalidSources(): iterable
    {
        yield ['', 'Expected treemap'];
        yield ['graph TD', 'Expected treemap'];
        yield ["treemap\nUnquoted", 'quoted treemap'];
        yield ["treemap\n\"Bad\":-1", 'quoted treemap'];
        yield ["treemap\n\"Bad\":1e3", 'quoted treemap'];
        yield ["treemap\n\"Bad\":1_000", 'complete finite'];
        yield ["treemap\n\"Bad\":1.2.3", 'complete finite'];
        yield ["treemap\n\"Bad\":".\str_repeat('9', times: 400), 'complete finite'];
        yield ["treemap\nclassDef bad filter:blur(2px);", 'Unsupported treemap'];
        yield ["treemap\nclassDef bad fill:imaginary;", 'Invalid treemap class color'];
        yield ["treemap\nclassDef bad fill:none;", 'Invalid treemap class color'];
        yield ["treemap\nclassDef bad stroke-width:wide;", '0 to 1000'];
        yield ["treemap\nclassDef bad stroke-width:1001px;", '0 to 1000'];
        yield ["treemap\nclassDef bad stroke-dasharray:-1;", 'non-negative lengths'];
        yield ["treemap\n".\str_repeat('"Leaf":1'."\n", times: 4097), 'treemap node limit'];
        $classes = [];
        for ($id = 0; $id < 4097; ++$id) {
            $classes[] = 'classDef c'.$id.' fill:red;';
        }
        yield ["treemap\n".\implode("\n", $classes), 'treemap class limit'];
        $nested = [];
        for ($level = 0; $level <= 128; ++$level) {
            $nested[] = \str_repeat(' ', $level).'"Section"';
        }
        yield ["treemap\n".\implode("\n", $nested), 'nesting exceeds'];
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
    public function testNativeExportsUseProportionalTilesAndEscapedColoredText(GraphExportFormat $format): void
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
        if (GraphExportFormat::Svg === $format) {
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

    /** @return iterable<array{GraphExportFormat}> */
    public static function exportFormats(): iterable
    {
        yield [GraphExportFormat::Svg];
        yield [GraphExportFormat::Png];
    }
}
