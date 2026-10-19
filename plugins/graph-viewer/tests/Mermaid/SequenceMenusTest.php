<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use Janalis\Viz\Layout\GraphNode;
use Janalis\Viz\Mermaid\MermaidLexer;
use Janalis\Viz\Mermaid\MermaidPalette;
use Janalis\Viz\Mermaid\SequenceDiagram;
use Janalis\Viz\Mermaid\SequenceLayout;
use Janalis\Viz\Mermaid\SequenceParser;
use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Mermaid\MermaidSceneRows;
use Porta\GraphViewer\Mermaid\MermaidTask;
use Porta\GraphViewer\Mermaid\NativeMermaidRenderer;
use Porta\Tests\Support\FakeScheduler;
use Porta\Ui\Theme\Styles;

final class SequenceMenusTest extends TestCase
{
    private static function parse(string $source): SequenceDiagram
    {
        $parser = new SequenceParser();
        foreach (new MermaidLexer($source, textual: true)->statements() as $statement) {
            $parser->accept($statement);
        }

        return $parser->finish();
    }

    public function testPanelsFollowChronologyAndMapTheirStatementsInNarrowRows(): void
    {
        $diagram = self::parse("sequenceDiagram\nparticipant A\nparticipant B\nlink A: Wiki @ https://example.test/wiki\nproperties B: {\"value\": 5}\nA->>B: message");
        $work = new SequenceLayout()->layout($diagram);
        \iterator_to_array($work);
        $scene = $work->getReturn();
        $panels = \array_values(\array_filter($scene->graph->nodes, static fn (GraphNode $node): bool => \str_starts_with($node->name, 'menu')));
        $this->assertCount(2, $panels);
        $this->assertStringContainsString('A links Wiki:', $panels[0]->label);
        $this->assertStringContainsString('B properties value: 5', $panels[1]->label);
        $this->assertGreaterThan(\array_last($scene->graph->edges)->maxY, $panels[0]->y - ($panels[0]->h / 2));
        $this->assertLessThan($panels[1]->y - ($panels[1]->h / 2), $panels[0]->y + ($panels[0]->h / 2));
        foreach ($panels as $index => $panel) {
            $this->assertSame(3 + $index, $scene->origins[(int) ($panel->y / 2)]);
            $this->assertLessThanOrEqual($scene->graph->width, $panel->x + ($panel->w / 2));
            $this->assertLessThan($scene->graph->height, $panel->y + ($panel->h / 2));
        }
        $this->assertCount((int) ($scene->graph->height / 2), $scene->origins);
        $rows = MermaidSceneRows::rows($scene->themed(MermaidPalette::defaults('sequenceDiagram')), width: 8, styles: Styles::dark());
        \iterator_to_array($rows);
        $this->assertContains(3, $rows->getReturn()->origins);
        $this->assertContains(4, $rows->getReturn()->origins);
        $this->assertStringContainsString('https://example.test/wiki', \implode('', $rows->getReturn()->rows));
    }

    public function testLongMenuLayoutYieldsAndCanBeCancelled(): void
    {
        $scheduler = new FakeScheduler();
        $renderer = new NativeMermaidRenderer(new MermaidTask($scheduler));
        $finished = false;
        $cancel = $renderer->render(
            'sequenceDiagram; link A: Long @ '.\str_repeat('字', times: 15_000),
            static function () use (&$finished): void {
                $finished = true;
            },
            static function (string $error): never {
                self::fail($error);
            },
        );
        $scheduler->fire();
        $this->assertFalse($finished);
        $this->assertNotEmpty($scheduler->scheduled);
        $cancel();
        $this->assertSame([], $scheduler->scheduled);
        $this->assertFalse($finished);
    }
}
