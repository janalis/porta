<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Graph\GraphExportFormat;
use Porta\GraphViewer\Mermaid\KanbanDiagram;
use Porta\GraphViewer\Mermaid\KanbanLayout;
use Porta\GraphViewer\Mermaid\KanbanLexer;
use Porta\GraphViewer\Mermaid\KanbanParser;
use Porta\GraphViewer\Mermaid\MermaidScene;
use Porta\GraphViewer\Mermaid\MermaidSyntaxError;
use Porta\GraphViewer\Mermaid\MermaidTask;
use Porta\GraphViewer\Mermaid\NativeMermaidRenderer;
use Porta\Tests\Support\FakeScheduler;
use Symfony\Component\Tui\Ansi\AnsiUtils;

final class KanbanTest extends TestCase
{
    private static function parse(string $source): KanbanDiagram
    {
        $parser = new KanbanParser();
        foreach (new KanbanLexer($source)->statements() as $statement) {
            $parser->accept($statement);
        }

        return $parser->finish();
    }

    private static function scene(string $source): MermaidScene
    {
        $work = new KanbanLayout()->layout(self::parse($source));
        \iterator_to_array($work);

        return $work->getReturn();
    }

    public function testIndentationColumnsAndDeeperCardsPreserveOccurrences(): void
    {
        $source = "%% intro\nKANBAN %% board\n  todo[Todo]\n    same[First]\n        same[Deeper card]\n  done[Done]\n    same[Last]";
        $diagram = self::parse($source);
        $this->assertSame(1, $diagram->origin);
        $this->assertSame(['todo', 'same', 'same', 'done', 'same'], \array_column($diagram->nodes, 'id'));
        $this->assertSame([0, 0, 0, 1, 1], \array_column($diagram->nodes, 'column'));
        $this->assertSame([true, false, false, true, false], \array_column($diagram->nodes, 'group'));
        $this->assertSame(['Todo', 'First', 'Deeper card', 'Done', 'Last'], \array_column($diagram->nodes, 'label'));
        $this->assertSame([2, 3, 4, 5, 6], \array_column($diagram->nodes, 'origin'));
        $allColumns = self::parse("kanban\n  repeated[A]\n  repeated[B]");
        $this->assertSame([true, true], \array_column($allColumns->nodes, 'group'), 'equal indentation always creates a column');
        $this->assertCount(2, self::parse("kanban\nRoot\n\tCard")->nodes);
    }

    public function testMetadataQuotesAndDecorationsRemainInertAndReadable(): void
    {
        $source = <<<'MERMAID'
            kanban
              todo[Todo]
                task[Can't reproduce]@{ assigned: 'K.Sveidqvist', ticket: MC-2038, priority: 'Very High', icon: task }
                ::icon(bomb)
                :::red bold
                other[Original]@{
                  label: "New, ] @{} <br/>line"
                  assigned: 'O''Brien'
                  ticket: "Ticket\nsecond"
                  shape: rect
                  custom: unchanged
                }
            MERMAID;
        $diagram = self::parse($source);
        $metadata = $diagram->nodes[1]['metadata'];
        $this->assertSame('K.Sveidqvist', $metadata['assigned']);
        $this->assertSame('MC-2038', $metadata['ticket']);
        $this->assertSame('Very High', $metadata['priority']);
        $this->assertSame('bomb', $metadata['icon']);
        $this->assertSame('red bold', $metadata['class']);
        $this->assertSame("Can't reproduce", $diagram->nodes[1]['label']);
        $this->assertSame("New, ] @{} \nline", $diagram->nodes[2]['label']);
        $this->assertSame("O'Brien", $diagram->nodes[2]['metadata']['assigned']);
        $this->assertSame("Ticket\nsecond", $diagram->nodes[2]['metadata']['ticket']);
        $this->assertSame('unchanged', $diagram->nodes[2]['metadata']['custom']);
        $scene = self::scene($source);
        $this->assertStringContainsString("assigned: K.Sveidqvist\nticket: MC-2038\npriority: Very High\nicon: bomb", $scene->graph->nodes[1]->label);
        $this->assertSame('Original', self::parse("kanban\nOriginal@{}")->nodes[0]['label']);
        $this->assertSame('Raw name', self::parse("kanban\nRaw name %% trailing comment")->nodes[0]['label']);
        // A pinned flow list reads as JavaScript's `Array#toString` joins it.
        $lists = self::parse("kanban\nA@{ assigned: [x, 'y z', \"w\",], ticket: [] }")->nodes[0]['metadata'];
        $this->assertSame(['x,y z,w', ''], [$lists['assigned'], $lists['ticket']]);
    }

    public function testMultilineLabelsAndMetadataKeepByteOffsetsAndIndentation(): void
    {
        $source = "kanban\n  root[\"漢\n字 %% literal\"]\n    task[\"quoted \\\"end\\\"\"]@{\n assigned: \"A } @{}\"\n}\n    next[Last] %% tail";
        $tokens = \iterator_to_array(new KanbanLexer($source)->statements());
        $this->assertCount(4, $tokens);
        $this->assertSame([0, 1, 3, 6], \array_column($tokens, 'line'));
        $this->assertSame([0, 2, 4, 4], \array_column($tokens, 'column'));
        $this->assertSame(\strpos($source, needle: 'task['), $tokens[2]->offset);
        $diagram = self::parse($source);
        $this->assertSame("漢\n字 %% literal", $diagram->nodes[0]['label']);
        $this->assertSame('quoted "end"', $diagram->nodes[1]['label']);
        $this->assertSame('A } @{}', $diagram->nodes[1]['metadata']['assigned']);
        $this->assertSame('Last', $diagram->nodes[2]['label']);
    }

    public function testColumnsAlignAndWrappedCardsStayWithinTheirBackground(): void
    {
        $source = "kanban\n  a[".\str_repeat('Long header ', times: 8)."]\n    x[".\str_repeat('Long card ', times: 12)."]\n    y[Second]\n  b[Short]\n    z[Third]";
        $scene = self::scene($source);
        $nodes = $scene->graph->nodes;
        $this->assertSame($nodes[0]->y, $nodes[3]->y);
        $this->assertSame($nodes[0]->h, $nodes[3]->h);
        $this->assertSame(10.0, $nodes[0]->h, 'three wrapped header lines fit without clipping');
        $this->assertSame($nodes[1]->y - ($nodes[1]->h / 2), $nodes[4]->y - ($nodes[4]->h / 2));
        $this->assertGreaterThan($nodes[1]->y + ($nodes[1]->h / 2), $nodes[2]->y - ($nodes[2]->h / 2));
        $this->assertSame(50.0, $nodes[3]->x - $nodes[0]->x);
        $this->assertSame(42.0, $nodes[1]->w);
        $this->assertCount(5, $scene->elements);
        $this->assertCount(7, $nodes, 'five semantic nodes and two backgrounds');
        $this->assertSame($nodes[5]->h, $nodes[6]->h);
        $this->assertNull($scene->selectable(5));
        $this->assertSame(3, $scene->origin(2));
        $this->assertSame(5, $scene->origins[(int) ($nodes[1]->y / 2)], 'shared rows follow the last authored column');
        foreach ($scene->selectionGraph->nodes as $node) {
            $this->assertLessThanOrEqual($scene->graph->width, $node->x + ($node->w / 2));
            $this->assertLessThanOrEqual($scene->graph->height, $node->y + ($node->h / 2));
            foreach (\explode("\n", $node->drawLabel) as $line) {
                $this->assertLessThanOrEqual(36, AnsiUtils::visibleWidth($line));
            }
        }
    }

    #[DataProvider('invalidSources')]
    public function testInvalidSyntaxAndResourceLimitsAreLocated(string $source, string $message): void
    {
        $this->expectException(MermaidSyntaxError::class);
        $this->expectExceptionMessageIsOrContains($message);
        self::parse($source);
    }

    /** @return iterable<array{string, string}> */
    public static function invalidSources(): iterable
    {
        yield ['', 'Expected kanban'];
        yield ['kanban', 'Expected kanban and a column'];
        yield ['graph TD', 'Expected kanban'];
        yield ["kanban\n  A\n B", 'column indentation'];
        yield ["kanban\n::icon(bomb)", 'preceding node'];
        yield ["kanban\nA\n::icon(", 'Invalid Kanban decoration'];
        yield ["kanban\nA@{ assigned: 'open }", 'Unclosed Kanban'];
        yield ["kanban\nA@{ assigned: X", 'Unclosed Kanban'];
        yield ["kanban\nA[\"open", 'Unclosed Kanban'];
        yield ["kanban\nA@{ assigned: X }trailing", 'Invalid Kanban metadata'];
        yield ["kanban\nA@{ assigned: [\"A\" B] }", 'Unsupported Kanban YAML scalar'];
        yield ["kanban\nA@{ assigned: {nested: value} }", 'flat scalar mapping'];
        yield ["kanban\nA@{ assigned: X, assigned: Y }", 'Duplicate Kanban'];
        yield ["kanban\nA@{ assigned: *alias }", 'Unsupported Kanban YAML'];
        yield ["kanban\nA@{ assigned:   }", 'Unsupported Kanban YAML'];
        yield ["kanban\nA@{ shape: Rect }", 'shape names must be lowercase'];
        yield ["kanban\nA@{ shape: not_valid }", 'shape names must be lowercase'];
        yield ["kanban\nA[Unclosed", 'Unclosed or empty mindmap shape'];
        yield ["kanban\n".\str_repeat('A', times: 65_538), 'Statement exceeds'];
        yield ["kanban\nA %%".\str_repeat('x', times: 65_536), 'Statement exceeds'];
        yield ["kanban\n".\str_repeat("A\n", times: 4097), 'Kanban node limit'];
    }

    #[DataProvider('upstreamExamples')]
    public function testPinnedUpstreamFixturesRenderWithoutCli(string $path): void
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
        foreach (new \GlobIterator(__DIR__.'/../fixtures/mermaid/kanban/*.mmd') as $file) {
            yield [$file->getPathname()];
        }
    }

    public function testCancellationNeverPublishesPartialBoard(): void
    {
        $scheduler = new FakeScheduler();
        $cancel = new NativeMermaidRenderer(new MermaidTask($scheduler))->render(
            "kanban\nRoot\n".\str_repeat("  Card\n", times: 3000),
            static function (): never {
                self::fail('Cancelled Kanban published');
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
    public function testNativeExportEscapesMetadataAndDrawsColumns(GraphExportFormat $format): void
    {
        $scheduler = new FakeScheduler();
        $result = null;
        new NativeMermaidRenderer(new MermaidTask($scheduler))->export(
            "kanban\nTodo\n  task[<script>]@{ assigned: 'User', ticket: 'javascript:alert(1)', priority: High }\nDone",
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
            $this->assertStringContainsString('>javascript:alert(1)</text>', $result, 'the ticket is inert text at the card foot');
            $this->assertStringNotContainsString('href=', $result);
            $this->assertStringNotContainsString('<script>', $result);

            return;
        }
        $this->assertStringStartsWith("\x89PNG", $result);
        $image = \imagecreatefromstring($result);
        $this->assertInstanceOf(\GdImage::class, $image);
        // The first column: the default theme's `cScale2` lightened by 10, as pinned.
        $this->assertSame(0xe8_ffb9, \imagecolorat($image, x: 100, y: 40));
    }

    /** @return iterable<array{GraphExportFormat}> */
    public static function exportFormats(): iterable
    {
        yield [GraphExportFormat::Svg];
        yield [GraphExportFormat::Png];
    }
}
