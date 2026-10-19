<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Graph\GraphExportFormat;
use Porta\GraphViewer\Graph\GraphNode;
use Porta\GraphViewer\Mermaid\MermaidGraph;
use Porta\GraphViewer\Mermaid\MermaidOutline;
use Porta\GraphViewer\Mermaid\MermaidScene;
use Porta\GraphViewer\Mermaid\MermaidSyntaxError;
use Porta\GraphViewer\Mermaid\MermaidTask;
use Porta\GraphViewer\Mermaid\MindmapLexer;
use Porta\GraphViewer\Mermaid\MindmapParser;
use Porta\GraphViewer\Mermaid\NativeMermaidRenderer;
use Porta\Help\Fence\FenceResult;
use Porta\Tests\Support\FakeScheduler;

final class MindmapTest extends TestCase
{
    private static function parse(string $source): MermaidGraph
    {
        $parser = new MindmapParser();
        foreach (new MindmapLexer($source)->statements() as $statement) {
            $parser->accept($statement);
        }

        return $parser->finish();
    }

    public function testHierarchyUsesNearestLessIndentedParentAndNeverMergesRepeatedWords(): void
    {
        $source = "\n %% comment\nMINDMAP %% header\n  Root\n      Same\n         Leaf\n    Same\n\t\t\tChild\n      %% ignored\n      Leaf\n      Peer\n  ";
        $graph = self::parse($source);
        $this->assertSame(['Root', 'Same', 'Leaf', 'Same', 'Child', 'Leaf', 'Peer'], \array_column($graph->nodes, 'label'));
        $this->assertSame([3, 4, 5, 6, 7, 9, 10], \array_column($graph->nodes, 'origin'));
        $this->assertSame(['mindmap-0', 'mindmap-1', 'mindmap-0', 'mindmap-0', 'mindmap-4', 'mindmap-4'], \array_column($graph->edges, 'from'));
        $this->assertSame(['mindmap-1', 'mindmap-2', 'mindmap-3', 'mindmap-4', 'mindmap-5', 'mindmap-6'], \array_column($graph->edges, 'to'));
        $this->assertSame(['---'], \array_values(\array_unique(\array_column($graph->edges, 'arrow'))));
        $tokens = \iterator_to_array(new MindmapLexer($source)->statements());
        $this->assertSame(4, $tokens[2]->line);
        $this->assertSame(6, $tokens[2]->column);
        $this->assertSame(\strpos($source, needle: 'Same'), $tokens[2]->offset);
    }

    #[DataProvider('shapes')]
    public function testShapesQuotedLabelsAndDecorations(string $declaration, string $label, string $shape): void
    {
        $graph = self::parse("mindmap\n  ".$declaration."\n  :::custom-class another\n  ::icon(fa fa-book)\n    Child");
        $this->assertSame($label, $graph->nodes['mindmap-0']['label']);
        $this->assertSame($shape, $graph->shapes['mindmap-0']);
        $this->assertSame('mindmap-0', $graph->edges[0]['from']);
        $this->assertCount(2, $graph->nodes);
    }

    /** @return iterable<array{string, string, string}> */
    public static function shapes(): iterable
    {
        yield ['root[Square]', 'Square', 'rect'];
        yield ['(Round)', 'Round', 'rounded'];
        yield ['root((Circle))', 'Circle', 'circle'];
        yield ['root{{Hexagon}}', 'Hexagon', 'hexagon'];
        yield ['root)Cloud(', 'Cloud', 'cloud'];
        yield ['root))Bang((', 'Bang', 'bang'];
        yield ['root(-Cloud-)', 'Cloud', 'rect'];
        yield ['root-)Bang(-', 'Bang', 'rect'];
        yield ['root["String containing [] () %%"] %% trailing', 'String containing [] () %%', 'rect'];
        yield ["root[`**Start** with\na second line 😎`]", "Start with\na second line 😎", 'rect'];
        yield ["root[\"`Markdown\nwith brackets []`\"]", "Markdown\nwith brackets []", 'rect'];
        yield ['A; #quot; &amp; B %% trailing', 'A; " & B', 'mindmap-plain'];
        yield ['root[Line<br/>two]', "Line\ntwo", 'rect'];
    }

    public function testMultilineLabelsKeepTheFollowingNodesSourcePositions(): void
    {
        $source = "mindmap\r\n  Root[\"First\nsecond\"]\r\n    Child[\"Last\"]";
        $tokens = \iterator_to_array(new MindmapLexer($source)->statements());
        $this->assertCount(3, $tokens);
        $this->assertSame(3, $tokens[2]->line);
        $this->assertSame(4, $tokens[2]->column);
        $this->assertSame(\strpos($source, needle: 'Child'), $tokens[2]->offset);
        $graph = self::parse($source);
        $this->assertSame(["First\nsecond", 'Last'], \array_column($graph->nodes, 'label'));
    }

    public function testMindmapOutlinesUseTheirGeometryForDrawingAndHitTesting(): void
    {
        $node = new GraphNode(0, 'node', 'Label', 15, 10, 20, 12, null, null, dashed: false);
        foreach (['cloud' => 129, 'bang' => 33, 'mindmap-plain' => 4] as $shape => $points) {
            $marks = MermaidOutline::marks($node, $shape);
            $this->assertCount('mindmap-plain' === $shape ? 2 : 1, $marks);
            $this->assertCount($points, MermaidOutline::points($node, $shape));
            $this->assertTrue(MermaidOutline::contains($node, $shape, x: 15, y: 10));
            $this->assertFalse(MermaidOutline::contains($node, $shape, x: 30, y: 10));
            $this->assertSame('polygon', $marks[0]->kind);
        }
        $plain = MermaidOutline::marks($node->painted('#112233', '#445566', null), 'mindmap-plain');
        $this->assertSame([MermaidOutline::points($node, 'rounded'), '#445566', 0.0], [$plain[0]->points, $plain[0]->fill, $plain[0]->strokeWidth], 'a rounded box in the section color');
        $this->assertSame([[[5.0, 16.0], [25.0, 16.0]], '#112233'], [$plain[1]->points, $plain[1]->stroke], 'and a line along its foot');
        $this->assertFalse(MermaidOutline::contains($node, 'bang', x: 24, y: 15));
        foreach (['bang' => 1, 'cloud' => 8] as $shape => $index) {
            [$x, $y] = MermaidOutline::points($node, $shape)[$index];
            $radius = \hypot(($x - $node->x) / ($node->w / 2), ($y - $node->y) / ($node->h / 2));
            $this->assertEqualsWithDelta('bang' === $shape ? 0.78 : 0.8, $radius, delta: 0.000_001);
        }
    }

    #[DataProvider('upstreamFixtures')]
    public function testPinnedUpstreamExamplesRenderWithoutCli(string $file): void
    {
        $scheduler = new FakeScheduler();
        $renderer = new NativeMermaidRenderer(new MermaidTask($scheduler));
        $result = null;
        $renderer->rows(
            (string) \file_get_contents($file),
            width: 24,
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
        $files = \glob(__DIR__.'/../fixtures/mermaid/mindmap/*.mmd');
        foreach (false === $files ? [] : $files as $file) {
            yield \basename($file) => [$file];
        }
    }

    public function testNativeSceneSelectionOriginsAndSvgExport(): void
    {
        $scheduler = new FakeScheduler();
        $renderer = new NativeMermaidRenderer(new MermaidTask($scheduler));
        $source = "mindmap\n  root((Root))\n    Child\n      Leaf";
        $scene = null;
        $renderer->render(
            $source,
            static function (MermaidScene $value) use (&$scene): void {
                $scene = $value;
            },
            static function (string $error): never {
                self::fail($error);
            },
        );
        while ([] !== $scheduler->scheduled) {
            $scheduler->fire();
        }
        $this->assertInstanceOf(MermaidScene::class, $scene);
        $this->assertSame([1, 2, 3], \array_values($scene->elements));
        $this->assertSame(1, $scene->origin($scene->onLine(1)));
        $this->assertSame('circle', $scene->shapes[0]);
        $this->assertSame($scene->graph->nodes[0]->w, $scene->graph->nodes[0]->h);
        $root = $scene->graph->nodes[0];
        $this->assertGreaterThan($root->x + ($root->w / 2), $scene->graph->nodes[1]->x - ($scene->graph->nodes[1]->w / 2), 'the first branch grows to the right');
        $this->assertNull($scene->graph->edges[0]->arrowTip);
        $svg = null;
        $renderer->export(
            $source,
            GraphExportFormat::Svg,
            static function (string $value) use (&$svg): void {
                $svg = $value;
            },
            static function (string $error): never {
                self::fail($error);
            },
        );
        while ([] !== $scheduler->scheduled) {
            $scheduler->fire();
        }
        $this->assertIsString($svg);
        $this->assertStringContainsString('>Leaf</text>', $svg);
        $this->assertStringContainsString('<polygon', $svg);
    }

    #[DataProvider('invalidSources')]
    public function testMalformedInputAndResourceLimits(string $source, string $message): void
    {
        $this->expectException(MermaidSyntaxError::class);
        $this->expectExceptionMessageIsOrContains($message);
        self::parse($source);
    }

    /** @return iterable<array{string, string}> */
    public static function invalidSources(): iterable
    {
        yield ['', 'Expected mindmap'];
        yield ['wrong', 'Expected mindmap'];
        yield ['mindmap', 'root node'];
        yield ["mindmap\nRoot\nOther", 'only one root'];
        yield ["mindmap\n  Root\n Other", 'only one root'];
        yield ["mindmap\n::icon(book)", 'preceding node'];
        yield ["mindmap\n:::hot", 'preceding node'];
        yield ["mindmap\nRoot\n::icon()", 'Invalid mindmap decoration'];
        yield ["mindmap\nRoot]", 'Invalid mindmap node'];
        yield ["mindmap\nRoot[", 'Unclosed or empty'];
        yield ["mindmap\nRoot[]", 'Unclosed or empty'];
        yield ["mindmap\nRoot[\"unclosed", 'Unclosed mindmap label'];
        yield ["mindmap\n".\str_repeat('a', times: 65_537), 'Statement exceeds'];
        yield ["mindmap\nRoot\n".\str_repeat(" Child\n", times: 4096), 'node limit'];
        $source = 'mindmap';
        for ($depth = 0; $depth < 129; ++$depth) {
            $source .= "\n".\str_repeat(' ', $depth).'Node';
        }
        yield [$source, 'depth limit'];
    }

    public function testCancellationOfLargeLayoutSuppressesPublication(): void
    {
        $scheduler = new FakeScheduler();
        $renderer = new NativeMermaidRenderer(new MermaidTask($scheduler));
        $cancel = $renderer->render(
            "mindmap\nRoot\n".\str_repeat(" Child\n", times: 2000),
            static function (): never {
                self::fail('Cancelled render published a scene');
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
}
