<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use Janalis\Viz\Layout\GraphNode;
use Janalis\Viz\Mermaid\MermaidLexer;
use Janalis\Viz\Mermaid\MermaidSyntaxError;
use Janalis\Viz\Mermaid\MermaidWrap;
use Janalis\Viz\Mermaid\PieDiagram;
use Janalis\Viz\Mermaid\PieLayout;
use Janalis\Viz\Mermaid\PieParser;
use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Mermaid\MermaidSceneRows;
use Porta\GraphViewer\Mermaid\MermaidTask;
use Porta\GraphViewer\Mermaid\NativeMermaidRenderer;
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
