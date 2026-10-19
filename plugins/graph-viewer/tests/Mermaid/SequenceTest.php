<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use Janalis\Viz\Layout\GraphEdge;
use Janalis\Viz\Layout\GraphNode;
use Janalis\Viz\Mermaid\MermaidConfiguration;
use Janalis\Viz\Mermaid\MermaidLexer;
use Janalis\Viz\Mermaid\MermaidPalette;
use Janalis\Viz\Mermaid\MermaidScene;
use Janalis\Viz\Mermaid\SequenceDiagram;
use Janalis\Viz\Mermaid\SequenceLayout;
use Janalis\Viz\Mermaid\SequenceParser;
use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Mermaid\MermaidTask;
use Porta\GraphViewer\Mermaid\NativeMermaidRenderer;
use Porta\GraphViewer\Render\GraphCellGrid;
use Porta\GraphViewer\Render\GraphRenderer;
use Porta\Help\Fence\FenceResult;
use Porta\ImageViewer\ZoomPanModel;
use Porta\Tests\Support\FakeScheduler;
use Porta\Ui\Theme\Styles;
use Symfony\Component\Tui\Ansi\AnsiUtils;

final class SequenceTest extends TestCase
{
    private static function parse(string $source): SequenceDiagram
    {
        $parser = new SequenceParser();
        foreach (new MermaidLexer($source, textual: true)->statements() as $statement) {
            $parser->accept($statement);
        }

        return $parser->finish();
    }

    /** A scene without mirrored footers, so each case reads its timeline alone. */
    private static function scene(string $source): MermaidScene
    {
        $work = new SequenceLayout(new MermaidConfiguration(mirrorActors: false))->layout(self::parse($source));
        \iterator_to_array($work);

        return $work->getReturn();
    }

    /** @return array<string, GraphEdge> each participant's lifeline */
    private static function lifelines(MermaidScene $scene): array
    {
        $lines = [];
        foreach ($scene->graph->edges as $edge) {
            $header = \array_find($scene->graph->nodes, static fn (GraphNode $node): bool => $node->x === $edge->minX && \abs($node->y + ($node->h / 2) - $edge->minY) < 1e-9);
            if (!$edge->decoration && $edge->minX === $edge->maxX && null !== $header) {
                $lines[(string) $header->name] = $edge;
            }
        }

        return $lines;
    }

    public function testActivationBackgroundPreservesMessageLineAndArrow(): void
    {
        $scene = self::scene('sequenceDiagram; participant A; participant B; activate B; A->>B: one; A->>B: two')->themed(MermaidPalette::defaults('sequenceDiagram'));
        $view = new ZoomPanModel((int) $scene->graph->width, (int) $scene->graph->height, [1.0]);
        $rows = \array_map(AnsiUtils::stripAnsiCodes(...), GraphRenderer::render($scene->graph, $view, null, 100, 60, Styles::dark()));
        $grid = new GraphCellGrid(100, 60, $view, null);
        foreach (\array_slice($scene->graph->edges, offset: 0, length: 2) as $edge) {
            $row = $grid->rowOf($edge->arrowTip[1]);
            $column = $grid->colOf($edge->arrowTip[0]);
            $this->assertSame('▸', \mb_substr($rows[$row], $column, length: 1));
            $this->assertSame('─', \mb_substr($rows[$row], $column - 1, length: 1));
        }
    }

    public function testLifetimesAnchorBirthDeathAndOpenActivation(): void
    {
        $source = 'sequenceDiagram; participant A; create actor B as Born; A->>+B: hello; B->>A: reply; destroy B; A--xB: bye';
        $diagram = self::parse($source);
        $this->assertSame(['create' => 1, 'destroy' => 5], $diagram->lifetimes['B']);
        $scene = self::scene($source)->themed(MermaidPalette::defaults('sequenceDiagram'));
        $nodes = \array_values($scene->graph->nodes);
        $born = \array_find($nodes, static fn (GraphNode $node): bool => 'B' === $node->name);
        $reply = \array_find($nodes, static fn (GraphNode $node): bool => 'reply' === $node->label);
        $this->assertGreaterThan($born->y + ($born->h / 2), $reply->y - ($reply->h / 2));
        $dead = \array_last(\array_filter($nodes, static fn (GraphNode $node): bool => 'B' === $node->name));
        $this->assertNotSame($born, $dead, 'a destroyed participant gets its footer where it dies, as pinned');
        $bar = $nodes[0];
        $hello = $scene->graph->edges[0];
        // The creating message meets the side of the header centered on it.
        $this->assertSame([$born->x - ($born->w / 2), $born->y], $hello->arrowTip);
        $bye = $scene->graph->edges[2];
        $this->assertNull($bye->arrowTip, 'a cross has no filled tip');
        $this->assertSame($bye->points[0][1], $bar->y + ($bar->h / 2));
        $this->assertSame($bye->points[0][1], $dead->y);
        $this->assertEqualsWithDelta($dead->x - ($dead->w / 2) - 0.32, \array_last($bye->points)[0], 1e-9, 'the line stops short of the cross');
        $lifeline = self::lifelines($scene)['B'];
        $this->assertEqualsWithDelta($born->y + ($born->h / 2), $lifeline->minY, 1e-9);
        $this->assertSame($dead->y - ($dead->h / 2), $lifeline->maxY);
        $this->assertFalse($lifeline->dashed, 'lifelines are solid, as pinned');
        $this->assertLessThan($scene->graph->height, $dead->y + ($dead->h / 2));
        $view = new ZoomPanModel((int) $scene->graph->width, (int) $scene->graph->height, [1.0]);
        $rows = \array_map(AnsiUtils::stripAnsiCodes(...), GraphRenderer::render($scene->graph, $view, null, 100, 60, Styles::dark()));
        $grid = new GraphCellGrid(100, 60, $view, null);
        $this->assertStringContainsString('▸', \mb_substr($rows[$grid->rowOf($born->y)], $grid->colOf($born->x - ($born->w / 2)) - 1, length: 2));
        $simultaneous = self::scene('sequenceDiagram; create participant 0; destroy 0; 1->>0: fleeting');
        $headers = \array_values(\array_filter($simultaneous->graph->nodes, static fn (GraphNode $node): bool => '0' === (string) $node->name));
        $this->assertCount(2, $headers);
        $this->assertSame($headers[0]->y + $headers[0]->h + 2, $headers[1]->y, 'one message creating and destroying stacks both headers');
        $this->assertLessThan($simultaneous->graph->height, \max(\array_map(static fn (GraphNode $node): float => $node->y + ($node->h / 2), $simultaneous->graph->nodes)));
        $sender = self::scene('sequenceDiagram; destroy A; A->>B: last');
        $footer = \array_last(\array_filter($sender->graph->nodes, static fn (GraphNode $node): bool => 'A' === $node->name));
        $this->assertSame($sender->graph->edges[0]->points[0][1], $footer->y);
        $this->assertSame($footer->x + ($footer->w / 2), $sender->graph->edges[0]->points[0][0], 'a message from a dying participant leaves its footer');
        $this->assertSame($footer->y - ($footer->h / 2), self::lifelines($sender)['A']->maxY);
        $this->assertCount(1, self::parse('sequenceDiagram; create participant X; Note over A: pause; A->>X: create')->lifetimes);
        $self = self::scene('sequenceDiagram; participant A; destroy A; A/|-A: end');
        $selfFooter = \array_last(\array_filter($self->graph->nodes, static fn (GraphNode $node): bool => 'A' === $node->name));
        $this->assertSame(\array_last($self->graph->edges[0]->points)[1], $selfFooter->y, 'a self message never moves to a header side');
    }

    public function testNativeDispatchNarrowRowsCancellationAndErrors(): void
    {
        $scheduler = new FakeScheduler();
        $renderer = new NativeMermaidRenderer(new MermaidTask($scheduler));
        $result = null;
        $error = null;
        $source = "# comment\nSEQUENCEDIAGRAM\nparticipant A as 漢字\nA->>B: message";
        $cancel = $renderer->rows(
            $source,
            3,
            static function (FenceResult $value) use (&$result): void {
                $result = $value;
            },
            static function (string $value) use (&$error): void {
                $error = $value;
            },
        );
        while ([] !== $scheduler->scheduled) {
            $scheduler->fire();
        }
        $this->assertNull($error);
        $this->assertInstanceOf(FenceResult::class, $result);
        $this->assertStringContainsString('message', \implode('', $result->rows));
        $this->assertContains(3, $result->origins);
        $cancel();
        $large = 'sequenceDiagram; '.\str_repeat('A->>B: hello;', times: 300);
        $scene = null;
        $cancel = $renderer->render(
            $large,
            static function (MermaidScene $value) use (&$scene): void {
                $scene = $value;
            },
            static function (string $error): never {
                self::fail($error);
            },
        );
        $scheduler->fire();
        $this->assertNull($scene);
        $cancel();
        $this->assertSame([], $scheduler->scheduled);
        $renderer->render(
            'sequenceDiagram; loop retry',
            static function (): never {
                self::fail('Unsupported statement accepted');
            },
            static function (string $value) use (&$error): void {
                $error = $value;
            },
        );
        // The error arrives once the parse finishes, however many slices that takes.
        while ([] !== $scheduler->scheduled) {
            $scheduler->fire();
        }
        $this->assertStringContainsString('Line 1', $error);
    }
}
