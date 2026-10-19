<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Graph\GraphExportFormat;
use Porta\GraphViewer\Mermaid\IshikawaDiagram;
use Porta\GraphViewer\Mermaid\IshikawaLayout;
use Porta\GraphViewer\Mermaid\IshikawaLexer;
use Porta\GraphViewer\Mermaid\IshikawaParser;
use Porta\GraphViewer\Mermaid\MermaidOutline;
use Porta\GraphViewer\Mermaid\MermaidScene;
use Porta\GraphViewer\Mermaid\MermaidSyntaxError;
use Porta\GraphViewer\Mermaid\MermaidTask;
use Porta\GraphViewer\Mermaid\NativeMermaidRenderer;
use Porta\Tests\Support\FakeScheduler;
use Symfony\Component\Tui\Ansi\AnsiUtils;

final class IshikawaTest extends TestCase
{
    private static function parse(string $source): IshikawaDiagram
    {
        $parser = new IshikawaParser();
        foreach (new IshikawaLexer($source)->statements() as $statement) {
            $parser->accept($statement);
        }

        return $parser->finish();
    }

    private static function scene(string $source): MermaidScene
    {
        $work = new IshikawaLayout()->layout(self::parse($source));
        \iterator_to_array($work);

        return $work->getReturn();
    }

    public function testEffectIndentationDoesNotChangeCauseHierarchy(): void
    {
        $source = "%% intro\nISHIKAWA-BETA\n      Effect\n  Cause\n    Detail\n      Deeper\n    Same\nSame\n  Nested\n\tLast";
        $diagram = self::parse($source);
        $this->assertSame(1, $diagram->origin);
        $this->assertSame(['Effect', 'Cause', 'Detail', 'Deeper', 'Same', 'Same', 'Nested', 'Last'], \array_column($diagram->nodes, 'label'));
        $this->assertSame([null, 0, 1, 2, 1, 0, 0, 0], \array_column($diagram->nodes, 'parent'));
        $this->assertSame([0, 1, 2, 3, 2, 1, 1, 1], \array_column($diagram->nodes, 'depth'));
        $this->assertSame([2, 3, 4, 5, 6, 7, 8, 9], \array_column($diagram->nodes, 'origin'));
        $this->assertSame([1, 5, 6, 7], $diagram->children[0]);
        $tokens = \iterator_to_array(new IshikawaLexer($source)->statements());
        $this->assertSame(\strpos($source, needle: 'Detail'), $tokens[3]->offset);
        $this->assertSame(4, $tokens[3]->column);
        $this->assertSame([null, 0, 1, 0], \array_column(self::parse("ishikawa\nEffect\nCause\n  Detail\nOther")->nodes, 'parent'));
    }

    public function testLabelsOwnQuotesPunctuationAndMetadataWords(): void
    {
        $diagram = self::parse("ishikawa\r\nProblem<br/>today\r\n  %% hidden\r\n\r\n\"quoted\"; [label] #tag %% literal\r\n  title Plain\r\n  accTitle: plain\r\n  :::class icon(name)\r\n  A &amp; B");
        $this->assertSame("Problem\ntoday", $diagram->nodes[0]['label']);
        $this->assertSame('"quoted"; [label] #tag %% literal', $diagram->nodes[1]['label']);
        $this->assertSame('title Plain', $diagram->nodes[2]['label']);
        $this->assertSame('accTitle: plain', $diagram->nodes[3]['label']);
        $this->assertSame(':::class icon(name)', $diagram->nodes[4]['label']);
        $this->assertSame('A & B', $diagram->nodes[5]['label']);
        $this->assertSame(4, $diagram->nodes[1]['origin']);
        $scene = self::scene("ishikawa\nOnly effect");
        $this->assertCount(1, $scene->graph->nodes);
        $this->assertSame([], $scene->graph->edges);
        $this->assertSame('fishhead', $scene->shapes[0]);
        $this->assertSame(1, $scene->origin(0));
    }

    public function testMainCausesAlternateAndLaterPairsMoveLeftOfEarlierPairs(): void
    {
        $scene = self::scene("ishikawa\nEffect\nA\nB\nC\nD\nE");
        $nodes = $scene->graph->nodes;
        $this->assertCount(6, $nodes);
        $this->assertCount(6, $scene->graph->edges);
        foreach ([1, 3, 5] as $id) {
            $this->assertLessThan($nodes[0]->y, $nodes[$id]->y);
        }
        foreach ([2, 4] as $id) {
            $this->assertGreaterThan($nodes[0]->y, $nodes[$id]->y);
        }
        $this->assertLessThan($nodes[1]->x - ($nodes[1]->w / 2), $nodes[3]->x + ($nodes[3]->w / 2));
        $this->assertLessThan($nodes[3]->x - ($nodes[3]->w / 2), $nodes[5]->x + ($nodes[5]->w / 2));
        $this->assertGreaterThan($nodes[1]->x, $nodes[0]->x);
        $spine = $scene->graph->edges[0];
        $this->assertSame($spine->points[0][1], $spine->points[1][1]);
        $this->assertSame($nodes[0]->x - ($nodes[0]->w / 2), $spine->points[1][0]);
        $this->assertSame($nodes[0]->y, $spine->points[1][1]);
        foreach ($scene->graph->edges as $edge) {
            $this->assertSame($edge->points[1], $edge->arrowTip);
        }
        $head = $nodes[0];
        $this->assertTrue(MermaidOutline::contains($head, 'fishhead', $head->x, $head->y));
        $this->assertFalse(MermaidOutline::contains($head, 'fishhead', $head->x + ($head->w / 2), $head->y - ($head->h / 2)));
    }

    public function testNestedBonesAlternateHorizontalAndDiagonalAndRetainSourceTargets(): void
    {
        $scene = self::scene("ishikawa\nEffect\nCause\n  Subcause\n    Deeper\n      Leaf\n    Sibling\n  Other\nBottom\n  Lower\n    Deep");
        $this->assertCount(10, $scene->elements);
        $depths = [0, 1, 2, 3, 4, 3, 2, 1, 2, 3];
        $bones = \array_column($scene->graph->edges, column_key: null, index_key: 'tail');
        foreach (\array_slice($scene->graph->edges, offset: 1) as $edge) {
            $this->assertSame($edge->tail, $scene->onLine($scene->origin($edge->tail)));
            $this->assertSame($edge->tail + 1, $scene->origin($edge->tail));
            $this->assertGreaterThan($edge->points[0][0], $edge->points[1][0]);
            $depth = $depths[$edge->tail];
            if (0 === ($depth % 2)) {
                $this->assertSame($edge->points[0][1], $edge->points[1][1]);
                $parent = $bones[$edge->head];
                $this->assertGreaterThanOrEqual(\min($parent->points[0][1], $parent->points[1][1]), $edge->points[1][1]);
                $this->assertLessThanOrEqual(\max($parent->points[0][1], $parent->points[1][1]), $edge->points[1][1]);
            }
            if (1 === ($depth % 2)) {
                $this->assertNotSame($edge->points[0][1], $edge->points[1][1]);
            }
        }
        foreach ($scene->graph->nodes as $node) {
            $this->assertGreaterThanOrEqual(0, $node->x - ($node->w / 2));
            $this->assertGreaterThanOrEqual(0, $node->y - ($node->h / 2));
            $this->assertLessThanOrEqual($scene->graph->width, $node->x + ($node->w / 2));
            $this->assertLessThanOrEqual($scene->graph->height, $node->y + ($node->h / 2));
        }
        $this->assertNull($scene->selectable(999));
    }

    public function testLongUnicodeLabelsWrapAndKeepFullSemanticText(): void
    {
        $label = \str_repeat('日本  long ', times: 100);
        $scene = self::scene("ishikawa\nEffect\n".$label);
        $this->assertSame(\rtrim($label), $scene->graph->nodes[1]->label);
        $this->assertStringContainsString("\n", $scene->graph->nodes[1]->drawLabel);
        foreach (\explode("\n", $scene->graph->nodes[1]->drawLabel) as $line) {
            $this->assertLessThanOrEqual(24, AnsiUtils::visibleWidth($line));
        }
    }

    #[DataProvider('invalidSources')]
    public function testResourceLimitsAndMissingEffectAreRejected(string $source, string $error): void
    {
        $this->expectException(MermaidSyntaxError::class);
        $this->expectExceptionMessageIsOrContains($error);
        self::parse($source);
    }

    /** @return iterable<array{string, string}> */
    public static function invalidSources(): iterable
    {
        yield ['', 'requires a header'];
        yield ['ishikawa', 'effect label'];
        yield ['wrong', 'Expected ishikawa'];
        yield ["ishikawa\n".\str_repeat('x', times: 65_537), 'size limit'];
        yield ["ishikawa\n".\str_repeat("node\n", times: 4097), 'node limit'];
        $source = "ishikawa\nEffect\n";
        for ($i = 0; $i < 128; ++$i) {
            $source .= \str_repeat(' ', $i)."Node\n";
        }
        yield [$source, 'depth limit'];
    }

    #[DataProvider('upstreamExamples')]
    public function testUpstreamExamplesRenderWithoutTheCli(string $file): void
    {
        $source = \file_get_contents($file);
        $this->assertIsString($source);
        $result = NativeFixtureRows::render($source, width: 32);
        $this->assertNotEmpty($result->rows);
        foreach ($result->rows as $row) {
            $this->assertLessThanOrEqual(32, AnsiUtils::visibleWidth($row));
        }
    }

    /** @return iterable<array{string}> */
    public static function upstreamExamples(): iterable
    {
        foreach (new \GlobIterator(__DIR__.'/../fixtures/mermaid/ishikawa/*.mmd') as $file) {
            yield [$file->getPathname()];
        }
    }

    public function testCancellationPreventsPartialPublication(): void
    {
        $scheduler = new FakeScheduler();
        $cancel = new NativeMermaidRenderer(new MermaidTask($scheduler))->render(
            "ishikawa\nEffect\n".\str_repeat("Cause\n", times: 3000),
            static function (): never {
                self::fail('Cancelled fishbone published');
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
    public function testNativeExportsEscapeLabelsAndDrawFishbone(GraphExportFormat $format): void
    {
        $result = NativeFixtureExport::render("ishikawa\nEffect\n<script>\n  A &amp; B", $format);
        if (GraphExportFormat::Svg === $format) {
            $this->assertStringContainsString('&lt;script&gt;', $result);
            $this->assertStringContainsString('A &amp; B', $result);
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
