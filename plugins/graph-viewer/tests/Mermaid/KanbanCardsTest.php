<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Graph\GraphFont;
use Porta\GraphViewer\Graph\GraphNode;
use Porta\GraphViewer\Mermaid\KanbanCards;
use Porta\GraphViewer\Mermaid\MermaidDrawing;
use Porta\GraphViewer\Mermaid\MermaidMark;
use Porta\GraphViewer\Mermaid\MermaidScene;

/** Pinned kanban cards: left-aligned title, ticket and assignee row, priority bar. */
final class KanbanCardsTest extends TestCase
{
    private static function card(MermaidScene $scene, string $title): GraphNode
    {
        $node = \array_find($scene->graph->nodes, static fn (GraphNode $node): bool => \str_starts_with($node->label, $title));
        self::assertInstanceOf(GraphNode::class, $node, $title);

        return $node;
    }

    public function testPrioritiesColorTheBarAsPinned(): void
    {
        foreach (['Very High' => '#ff0000', 'High' => '#ffa500', 'Medium' => '', 'Low' => '#0000ff', 'Very Low' => '#add8e6', 'urgent' => ''] as $priority => $color) {
            $this->assertSame('kanban-card:'.$color.':'.\base64_encode('T').':'.\base64_encode('A').':0', KanbanCards::shape($priority, 'T', 'A', tickets: ''), $priority);
        }
        $node = new GraphNode(0, 'card', 'Title', 20.0, 10.0, 20.0, 8.0, '#111111', '#ffffff', dashed: false, textColor: '#222222');
        $marks = KanbanCards::marks($node, KanbanCards::shape('High', '', '', tickets: ''));
        $this->assertSame([[10.2, 6.25], [10.2, 13.75]], $marks[1]->points, 'four pixels wide, just inside the left edge');
        $this->assertSame(['#ffa500', 0.4], [$marks[1]->stroke, $marks[1]->strokeWidth]);
        $this->assertCount(2, KanbanCards::marks($node, KanbanCards::shape('Medium', '', '', tickets: '')), 'no bar: the box and the title');
    }

    public function testTitleTicketAndAssigneeSitWhereThePinnedCardPutsThem(): void
    {
        $scene = NativeFixtureScene::render(
            "---\nconfig:\n  kanban:\n    ticketBaseUrl: 'https://example.test/#TICKET#'\n---\nkanban\n  Todo\n    a[Write docs]@{ ticket: MC-1, assigned: 'knsv', priority: 'Very High', icon: 'fa:book' }\n    b[Plain]",
        );
        $card = self::card($scene, 'Write docs');
        $plain = self::card($scene, 'Plain');
        $this->assertSame(2.0, $card->h - $plain->h, 'the ticket row adds one line');
        $assignedOnly = NativeFixtureScene::render("kanban\n  Todo\n    a[Plain]@{ assigned: 'me' }\n    b[Plain]");
        $this->assertSame(
            2.0,
            self::card($assignedOnly, 'Plain')->h - \array_first(\array_filter($assignedOnly->graph->nodes, static fn (GraphNode $node): bool => 'Plain' === $node->label))->h,
            'so does an assignee alone',
        );
        $this->assertStringContainsString('icon: fa:book', $card->label, 'cells and the legend keep every field');
        $marks = KanbanCards::marks($card, $scene->shapes[$card->id], $scene->runs['node:'.$card->id]);
        $texts = \array_values(\array_filter($marks, static fn (MermaidMark $mark): bool => 'text' === $mark->kind));
        $this->assertSame(['Write docs', 'MC-1', 'knsv'], \array_column($texts, 'text'), 'no icon is drawn');
        $left = $card->x - ($card->w / 2);
        $top = $card->y - ($card->h / 2);
        $this->assertSame([$left + 1 + 5.0, $top + 2.5], $texts[0]->points[0], 'the title starts one unit in');
        $this->assertSame([$left + 1 + 2.0, $top + 4.5], $texts[1]->points[0], 'the ticket starts beneath it');
        $this->assertSame([$card->x + ($card->w / 2) - 1 - 2.0, $top + 4.5], $texts[2]->points[0], 'the assignee ends one unit in');
        $underline = \array_last(\array_filter($marks, static fn (MermaidMark $mark): bool => 'polyline' === $mark->kind && 0.08 === $mark->strokeWidth));
        $this->assertSame([[$left + 1, $top + 4.8], [$left + 5, $top + 4.8]], $underline->points, 'a linked ticket is underlined');
        $unlinked = NativeFixtureScene::render("kanban\n  Todo\n    a[Write docs]@{ ticket: MC-1 }");
        $node = self::card($unlinked, 'Write docs');
        $this->assertNull(\array_find(KanbanCards::marks($node, $unlinked->shapes[$node->id], $unlinked->runs['node:'.$node->id]), static fn (MermaidMark $mark): bool => 0.08 === $mark->strokeWidth));
        $drawing = MermaidDrawing::scene($scene);
        \iterator_to_array($drawing);
        $titles = \array_filter($drawing->getReturn()->marks, static fn (MermaidMark $mark): bool => 'text' === $mark->kind && 'Write docs' === $mark->text);
        $this->assertCount(1, $titles, 'the card draws its own title; the centered label is hidden');
        $wrapped = NativeFixtureScene::render("kanban\n  Todo\n    a[Shipped long title that should wrap around the card width]");
        $long = self::card($wrapped, 'Shipped');
        $first = \array_find(KanbanCards::marks($long, $wrapped->shapes[$long->id], $wrapped->runs['node:'.$long->id]), static fn (MermaidMark $mark): bool => 'text' === $mark->kind);
        $this->assertSame($long->x - ($long->w / 2) + 1 + (\mb_strlen($first->text) / 2), $first->points[0][0], 'a wrapped line\'s trailing space is no indent');
        $this->assertStringEndsWith('wrap', $first->text);
    }

    public function testTitlesAreMarkdownBacktickedOrNot(): void
    {
        $scene = NativeFixtureScene::render(
            "kanban\n  Todo\n    a[\"`**Bold** and *it* text`\"]\n    b[Plain **stars**]\n    c[A long title that wraps between its words rather than inside any of them]",
        );
        $bold = self::card($scene, 'Bold and it text');
        $texts = \array_values(\array_filter(KanbanCards::marks($bold, $scene->shapes[$bold->id], $scene->runs['node:'.$bold->id]), static fn (MermaidMark $mark): bool => 'text' === $mark->kind));
        $this->assertSame(
            [
                ['Bold', GraphFont::Bold],
                ['and',  GraphFont::Regular],
                ['it',   GraphFont::Italic],
                ['text', GraphFont::Regular],
            ],
            \array_map(static fn (MermaidMark $mark): array => [$mark->text, $mark->font], $texts),
        );
        $left = $bold->x - ($bold->w / 2);
        $this->assertSame([$left + 1 + 2.0, $left + 1 + 6.5], [$texts[0]->points[0][0], $texts[1]->points[0][0]], 'runs follow each other from the left edge');
        $plain = self::card($scene, 'Plain stars');
        $this->assertSame(
            [
                ['Plain ', GraphFont::Regular],
                ['stars',  GraphFont::Bold],
            ],
            $scene->runs['node:'.$plain->id][0],
        );
        $long = self::card($scene, 'A long title');
        $this->assertSame("A long title that wraps between its\nwords rather than inside any of them", $long->drawLabel);
        $this->assertSame('A long title that wraps between its words rather than inside any of them', $long->label);
    }
}
