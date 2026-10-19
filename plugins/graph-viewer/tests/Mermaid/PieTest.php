<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Graph\GraphNode;
use Porta\GraphViewer\Mermaid\MermaidLexer;
use Porta\GraphViewer\Mermaid\MermaidSceneRows;
use Porta\GraphViewer\Mermaid\MermaidStatement;
use Porta\GraphViewer\Mermaid\MermaidSyntaxError;
use Porta\GraphViewer\Mermaid\MermaidTask;
use Porta\GraphViewer\Mermaid\MermaidWrap;
use Porta\GraphViewer\Mermaid\NativeMermaidRenderer;
use Porta\GraphViewer\Mermaid\PieDiagram;
use Porta\GraphViewer\Mermaid\PieLayout;
use Porta\GraphViewer\Mermaid\PieParser;
use Porta\Tests\Support\FakeScheduler;
use Porta\Ui\Theme\Styles;

final class PieTest extends TestCase
{
    private static function parse(string $source): PieDiagram
    {
        $parser = new PieParser();
        foreach (new MermaidLexer($source, chart: true)->statements() as $statement) {
            $parser->accept($statement);
        }

        return $parser->finish();
    }

    public function testPinnedExampleAndDuplicateZeroValues(): void
    {
        $diagram = self::parse("pie title Pets adopted by volunteers\n\"Dogs\" : 386\n\"Cats\" : 85\n\"Rats\" : 15\n\"Dogs\" : 999\n\"None\" : 0\n\"0\" : -0");
        $this->assertSame(['Dogs', 'Cats', 'Rats', 'None', 0], \array_keys($diagram->slices));
        $this->assertSame(486.0, $diagram->total);
        $this->assertSame(['label' => 'Dogs', 'value' => 386.0, 'text' => '386', 'origin' => 1], $diagram->slices['Dogs']);
        $this->assertSame('Pets adopted by volunteers', $diagram->metadata['title']['label']);
        $this->assertFalse($diagram->showData);
        $this->assertSame('42.960', self::parse('pie showData
"Calcium":42.960')->slices['Calcium']['text']);
    }

    public function testQuotedTextMetadataCommentsAndSourcePositions(): void
    {
        $diagram = self::parse(<<<'MMD'
            %% intro
            pie showData title Sales [Q1]; "unmatched %% hidden
            accTitle: Sales #hash %% hidden
            accDescr {
            literal ; %% #hash
            }
            "A; %% [|] #quot;" : 1 %% trailing
            'Single "other"; %% [|]' : 2
            "escaped \"quote\" \\path" : 3
            title Replacement
            MMD);
        $this->assertTrue($diagram->showData);
        $this->assertSame('Replacement', $diagram->metadata['title']['label']);
        $this->assertSame(9, $diagram->metadata['title']['origin']);
        $this->assertSame('Sales #hash', $diagram->metadata['acctitle']['label']);
        $this->assertSame('literal ; %% #hash', $diagram->metadata['accdescr']['label']);
        $this->assertSame(['A; %% [|] "', 'Single "other"; %% [|]', 'escaped "quote" \\path'], \array_column($diagram->slices, 'label'));
        $this->assertSame(6, $diagram->slices['A; %% [|] #quot;']['origin']);
        $this->assertSame('', self::parse("pie\naccDescr:\ntitle")->metadata['title']['label']);
    }

    public function testProportionalGeometryOriginsAndNarrowRows(): void
    {
        $work = new PieLayout()->layout(self::parse("pie showData\ntitle Proportions\n\"漢字\":1\n\"Other\":3\n\"Zero\":0"));
        \iterator_to_array($work);
        $scene = $work->getReturn();
        $nodes = \array_values(\array_intersect_key($scene->graph->nodes, $scene->elements));
        $this->assertCount(3, $nodes);
        $this->assertCount(0, $scene->graph->edges);
        [$a, $b] = \array_map(static fn (GraphNode $node): array => \explode(':', $scene->shapes[$node->id]), \array_slice($nodes, offset: 0, length: 2));
        $this->assertEqualsWithDelta(3.0, ((float) $b[2] - (float) $b[1]) / ((float) $a[2] - (float) $a[1]), 0.000_01);
        $this->assertSame('漢字: 1 (25.00%)', $scene->legend[1]['text']);
        $this->assertSame('Other: 3 (75.00%)', $nodes[1]->label);
        $this->assertSame([2, 3, 4], \array_values($scene->elements));
        $this->assertCount((int) \ceil($scene->graph->height / 2), $scene->origins);
        $rows = MermaidSceneRows::rows($scene, width: 1, styles: Styles::dark());
        \iterator_to_array($rows);
        $result = $rows->getReturn();
        $this->assertStringContainsString('Other:3(75.00%)', \implode('', $result->rows));
        $this->assertContains(4, $result->origins);
        $this->assertCount(\count($result->rows), $result->origins);
        $work = new PieLayout()->layout(self::parse('pie
"A":0
"B":0'));
        \iterator_to_array($work);
        $this->assertCount(0, $work->getReturn()->graph->edges);
        $this->assertSame('A (0.00%)', $work->getReturn()->graph->nodes[\array_key_first($work->getReturn()->elements)]->label);
        $work = new PieLayout()->layout(self::parse('pie'));
        \iterator_to_array($work);
        $this->assertSame('title: Pie', $work->getReturn()->legend[0]['text']);
        $work = new PieLayout()->layout(self::parse("%% intro\npie"));
        \iterator_to_array($work);
        $this->assertSame(1, $work->getReturn()->legend[0]['origin']);
        $this->assertSame('A', self::parse('pie "A":1')->slices['A']['label']);
        $this->assertSame('A', self::parse('pie showData "A":1')->slices['A']['label']);
    }

    public function testEscapesInlineAccessibilityAndGraphemeWrapping(): void
    {
        $diagram = self::parse(<<<'MMD'
            pie accDescr {
            Description
            }
            "A\nB\t\r\b\f\v\0\q":1
            'it\'s':0
            MMD);
        $this->assertSame('Description', $diagram->metadata['accdescr']['label']);
        $this->assertSame("A\nB\t\r\x08\f\v\0q", \array_first($diagram->slices)['label']);
        $this->assertArrayHasKey("it's", $diagram->slices);
        $work = new PieLayout()->layout($diagram);
        \iterator_to_array($work);
        $this->assertStringContainsString('B\\t\\r\\b\\f\\v\\0q', \implode("\n", \array_column($work->getReturn()->legend, 'text')));
        $work = MermaidWrap::text("e\u{0301}漢字\nend", width: 3);
        \iterator_to_array($work);
        $this->assertSame("e\u{0301}漢\n字\nend", $work->getReturn());
        $work = MermaidWrap::text('', width: 3);
        \iterator_to_array($work);
        $this->assertSame('', $work->getReturn());
    }

    public function testDisplayDecodingDoesNotMergeDistinctSliceLabels(): void
    {
        $diagram = self::parse(<<<'MMD'
            pie
            "A#quot;":1
            "A\"":2
            "A#quot;":9
            MMD);
        $this->assertCount(2, $diagram->slices);
        $this->assertSame(['A"', 'A"'], \array_column($diagram->slices, 'label'));
        $this->assertSame([1.0, 2.0], \array_column($diagram->slices, 'value'));
        $this->assertSame([1, 2], \array_column($diagram->slices, 'origin'));
        $this->assertSame(3.0, $diagram->total);
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalid(): iterable
    {
        yield 'header' => ['wrong', 'Expected pie'];
        yield 'semicolon' => ['pie; "A":1', 'Expected quoted pie'];
        yield 'unquoted' => ['pie
Dogs:1', 'Expected quoted pie'];
        yield 'number' => ['pie
"A":1e2', 'Expected quoted pie'];
        yield 'leading zero integer' => ['pie
"A":01', 'Expected quoted pie'];
        yield 'negative' => ['pie
"A":-1', 'finite and nonnegative'];
        yield 'overflow' => ['pie
"A":'.\str_repeat('9', times: 310), 'finite and nonnegative'];
        yield 'total overflow' => ['pie
"A":'.\str_repeat('9', times: 308).'
"B":'.\str_repeat('9', times: 308), 'Pie total exceeds'];
        yield 'unclosed' => ['pie
"A:1', 'Unclosed label'];
        yield 'description' => ['pie
accDescr {unfinished', 'Unclosed accessibility'];
    }

    #[DataProvider('invalid')]
    public function testLocatedErrors(string $source, string $message): void
    {
        try {
            self::parse("\n".$source);
            $this->fail('invalid pie syntax must fail');
        } catch (MermaidSyntaxError $error) {
            $this->assertStringContainsString($message, $error->getMessage());
            $line = match (true) {
                \str_contains($message, 'Pie total') => 3,
                \str_contains($source, "\n") => 2,
                default => 1,
            };
            $this->assertSame($line, $error->sourceLine);
        }
    }

    public function testWrappingYieldsWithinLongLines(): void
    {
        $scheduler = new FakeScheduler();
        $result = null;
        $task = new MermaidTask($scheduler);
        $cancel = $task->run(
            MermaidWrap::text(\str_repeat('A', times: 1000), width: 116),
            static function (string $value) use (&$result): void {
                $result = $value;
            },
            static function (string $error): never {
                self::fail($error);
            },
        );
        $scheduler->fire();
        $this->assertNull($result);
        $this->assertNotEmpty($scheduler->scheduled);
        $cancel();
        $this->assertSame([], $scheduler->scheduled);
        try {
            self::parse('pie "A":-1');
            $this->fail('negative inline value must fail');
        } catch (MermaidSyntaxError $error) {
            $this->assertSame([0, 4, 4], [$error->sourceLine, $error->column, $error->offset]);
        }
    }

    public function testHeaderAndSliceLimitGuards(): void
    {
        try {
            new PieParser()->finish();
            $this->fail('missing header must fail');
        } catch (MermaidSyntaxError $error) {
            $this->assertStringContainsString('Expected pie', $error->getMessage());
        }
        $parser = new PieParser();
        $parser->accept(new MermaidStatement('pie', offset: 0, line: 0, column: 0));
        for ($i = 0; $i < 4096; ++$i) {
            $parser->accept(new MermaidStatement('"S'.$i.'":0', offset: 0, line: $i + 1, column: 0));
        }
        $parser->accept(new MermaidStatement('"S0":9', offset: 0, line: 4097, column: 0));
        $this->assertSame(0.0, $parser->finish()->total);
        $this->expectExceptionMessageIsOrContains('pie slice limit');
        $parser->accept(new MermaidStatement('"New":0', offset: 0, line: 4098, column: 0));
    }

    public function testScheduledRenderingCancellationAndInlineRows(): void
    {
        $scheduler = new FakeScheduler();
        $renderer = new NativeMermaidRenderer(new MermaidTask($scheduler));
        $scene = null;
        $error = null;
        $cancel = $renderer->render(
            'pie
"'.\str_repeat('漢', times: 15_000).'":1',
            static function ($value) use (&$scene): void {
                $scene = $value;
            },
            static function (string $value) use (&$error): void {
                $error = $value;
            },
        );
        $scheduler->fire();
        $this->assertNull($scene);
        $this->assertNotEmpty($scheduler->scheduled);
        $cancel();
        $this->assertSame([], $scheduler->scheduled);
        $renderer->rows(
            'pie showData
"Dogs":3
"Cats":1',
            width: 20,
            success: static function ($value) use (&$scene): void {
                $scene = $value;
            },
            error: static function (string $value) use (&$error): void {
                $error = $value;
            },
        );
        while ([] !== $scheduler->scheduled) {
            $scheduler->fire();
        }
        $this->assertNull($error);
        $this->assertStringContainsString('Dogs: 3 (75.00%)', \implode('', $scene->rows));
    }
}
