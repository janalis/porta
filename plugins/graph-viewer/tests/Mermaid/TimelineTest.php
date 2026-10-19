<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Mermaid\MermaidStatement;
use Porta\GraphViewer\Mermaid\MermaidSyntaxError;
use Porta\GraphViewer\Mermaid\MermaidTask;
use Porta\GraphViewer\Mermaid\NativeMermaidRenderer;
use Porta\GraphViewer\Mermaid\TimelineDiagram;
use Porta\GraphViewer\Mermaid\TimelineLayout;
use Porta\GraphViewer\Mermaid\TimelineLexer;
use Porta\GraphViewer\Mermaid\TimelineParser;
use Porta\Help\Fence\FenceResult;
use Porta\Tests\Support\FakeScheduler;

final class TimelineTest extends TestCase
{
    private static function parse(string $source): TimelineDiagram
    {
        $parser = new TimelineParser();
        foreach (new TimelineLexer($source)->statements() as $statement) {
            $parser->accept($statement);
        }

        return $parser->finish();
    }

    public function testPeriodsAndEventsPreserveOrderPunctuationAndSourceLocations(): void
    {
        $source = "%% intro\nTIMELINE LR\n  title History: # ; %% literal\naccTitle: literal # ; %%\naccDescr {\nDescription\n}section First\n漢字 : [link](https://example.com): second\n : Third<br/>line\nsection First\nEmpty\nsection Trailing\n# ignored";
        $diagram = self::parse($source);
        $this->assertSame('LR', $diagram->direction);
        $this->assertSame(1, $diagram->origin);
        $this->assertSame(['title', 'acctitle', 'accdescr'], \array_keys($diagram->metadata));
        $this->assertSame('History: # ; %% literal', $diagram->metadata['title']['label']);
        $this->assertSame('literal # ; %%', $diagram->metadata['acctitle']['label']);
        $this->assertSame('Description', $diagram->metadata['accdescr']['label']);
        $this->assertSame(['First', 'First', 'Trailing'], \array_column($diagram->sections, 'label'));
        $this->assertSame(['漢字', 'Empty'], \array_column($diagram->periods, 'label'));
        $this->assertSame([0, 1], \array_column($diagram->periods, 'section'));
        $this->assertSame(['[link](https://example.com)', 'second', "Third\nline"], \array_column($diagram->periods[0]['events'], 'label'));
        $this->assertSame([7, 7, 8], \array_column($diagram->periods[0]['events'], 'origin'));
        $tokens = \iterator_to_array(new TimelineLexer($source)->statements());
        $event = \array_first(\array_filter($tokens, static fn (MermaidStatement $item): bool => \str_starts_with($item->text, ':')));
        $this->assertSame(7, $event->line);
        $this->assertSame(3, $event->column, 'columns count Unicode characters, offsets count bytes');
        $this->assertSame(\strpos($source, needle: ': [link]'), $event->offset);
        $this->assertSame([], $diagram->periods[1]['events']);
        $this->assertNull(self::parse("timeline\nUnsectioned")->periods[0]['section']);
        $this->assertSame('description', self::parse("timeline\naccDescr: description")->metadata['accdescr']['label']);
    }

    public function testDirectionsPlacePeriodsOnAxisAndEventsPerpendicularToIt(): void
    {
        foreach (['LR', 'TD'] as $direction) {
            $diagram = self::parse('timeline '.$direction."\nA : a1 : a2\nB : ".\str_repeat('long ', times: 30));
            $work = new TimelineLayout()->layout($diagram);
            \iterator_to_array($work);
            $scene = $work->getReturn();
            $nodes = \array_column($scene->graph->nodes, column_key: null, index_key: 'name');
            $a = $nodes['period-0'];
            $b = $nodes['period-1'];
            $event = $nodes['period-0-event-0'];
            if ('LR' === $direction) {
                $this->assertGreaterThan($a->x, $b->x);
                $this->assertSame($a->y, $b->y);
                $this->assertSame($a->x, $event->x);
                $this->assertGreaterThan($a->y, $event->y);
            }
            if ('TD' === $direction) {
                $this->assertGreaterThan($a->y, $b->y);
                $this->assertSame($a->x, $b->x);
                $this->assertGreaterThan($a->x, $event->x);
                $this->assertSame($a->y, $event->y);
            }
            $this->assertCount(4, $scene->graph->edges);
            $this->assertSame('title: Timeline', $scene->legend[0]['text']);
            $this->assertSame('A : a1', $scene->legend[2]['text']);
            $this->assertCount((int) \ceil($scene->graph->height / 2), $scene->origins);
            $this->assertContains(2, $scene->origins);
            foreach ($scene->graph->edges as $edge) {
                $this->assertNotSame($edge->points[1], $edge->arrowTip);
            }
        }
    }

    public function testEmptyAndRepeatedSectionsRemainInScene(): void
    {
        $work = new TimelineLayout()->layout(self::parse("timeline\nsection Same\nsection Same\nA\nsection Empty"));
        \iterator_to_array($work);
        $this->assertSame(['title: Timeline', 'section: Same', 'section: Same', 'A', 'section: Empty'], \array_column($work->getReturn()->legend, 'text'));
        $this->assertCount(0, $work->getReturn()->graph->edges);
        $work = new TimelineLayout()->layout(self::parse('timeline'));
        \iterator_to_array($work);
        $this->assertCount(1, $work->getReturn()->graph->nodes);
    }

    #[DataProvider('upstreamFixtures')]
    public function testPinnedUpstreamExamplesRenderWithoutCli(string $file): void
    {
        $scheduler = new FakeScheduler();
        $renderer = new NativeMermaidRenderer(new MermaidTask($scheduler));
        $result = null;
        $renderer->rows(
            (string) \file_get_contents($file),
            width: 32,
            success: static function ($value) use (&$result): void {
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
        $files = \glob(__DIR__.'/../fixtures/mermaid/timeline/*.mmd');
        foreach (false === $files ? [] : $files as $file) {
            yield \basename($file) => [$file];
        }
    }

    public function testLongLayoutYieldsAndCancellationSuppressesResult(): void
    {
        $scheduler = new FakeScheduler();
        $renderer = new NativeMermaidRenderer(new MermaidTask($scheduler));
        $cancel = $renderer->render(
            "timeline\n".\str_repeat('漢', times: 15_000),
            static function (): never {
                self::fail('cancelled request must not publish');
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
        yield ['', 'Expected timeline'];
        yield ['wrong', 'Expected timeline'];
        yield ["timeline\n: orphan", 'preceding period'];
        yield ["timeline\nA:bad", 'Invalid timeline token'];
        yield ["timeline\nA : ", 'Invalid timeline token'];
        yield ["timeline\n".\str_repeat('x', times: 65_537), 'Statement exceeds'];
        yield ["timeline\n".\str_repeat("section A\n", times: 4097), 'timeline section limit'];
        yield ["timeline\n".\str_repeat("A\n", times: 4097), 'timeline period limit'];
        yield ["timeline\nA\n".\str_repeat(": event\n", times: 4097), 'timeline event limit'];
    }

    public function testParserRejectsInvalidStatementsWhenCalledDirectly(): void
    {
        $parser = new TimelineParser();
        $parser->accept(new MermaidStatement('timeline TD', offset: 0, line: 0, column: 0));
        $this->expectException(MermaidSyntaxError::class);
        $this->expectExceptionMessageIsOrContains('Expected timeline period');
        $parser->accept(new MermaidStatement('bad:period', offset: 12, line: 1, column: 0));
    }
}
