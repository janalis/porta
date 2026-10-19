<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\GraphViewer\Graph\GraphFont;
use Porta\GraphViewer\Graph\GraphNode;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * Pinned `kanbanItem` cards: the title left-aligned at the top, the ticket at the bottom left
 * (underlined when `ticketBaseUrl` links it), the assignee at the bottom right, and a 4px bar
 * down the left edge in the priority's color. Icons draw nothing, as pinned.
 */
final class KanbanCards
{
    /** Pinned `colorFromPriority`; `Medium` and unknown priorities draw no bar. */
    private const array PRIORITIES = ['Very High' => '#ff0000', 'High' => '#ffa500', 'Low' => '#0000ff', 'Very Low' => '#add8e6'];

    /** The shape of a card with its bottom row and priority; a `ticketBaseUrl` links the ticket. */
    public static function shape(string $priority, string $ticket, string $assigned, string $tickets): string
    {
        return 'kanban-card:'.(self::PRIORITIES[$priority] ?? '').':'.\base64_encode($ticket).':'.\base64_encode($assigned).':'.('' === $tickets ? '0' : '1');
    }

    /**
     * @param ?list<list<array{string, GraphFont}>> $runs the title's styled lines, as pinned Markdown draws them
     * @return list<MermaidMark>
     */
    public static function marks(GraphNode $node, string $shape, ?array $runs = null): array
    {
        [, $priority, $ticket, $assigned, $linked] = \explode(':', $shape) + ['', '', '', '', '0'];
        $left = $node->x - ($node->w / 2);
        $right = $node->x + ($node->w / 2);
        $top = $node->y - ($node->h / 2);
        $color = $node->textColor ?? '#333333';
        $marks = [new MermaidMark('polygon', MermaidOutline::points($node, 'rounded'), stroke: $node->color ?? '#333333', fill: $node->fillColor ?? '#ffffff', strokeWidth: $node->strokeWidth)];
        if ('' !== $priority) {
            $marks[] = new MermaidMark('polyline', [[$left + 0.2, $top + 0.25], [$left + 0.2, $node->y + ($node->h / 2) - 0.25]], stroke: $priority, strokeWidth: 0.4);
        }
        $lines = $runs ?? \array_map(static fn (string $line): array => [[$line, $node->font]], \explode("\n", $node->drawLabel ?? $node->label));
        foreach ($lines as $index => $line) {
            \array_push($marks, ...MermaidRuns::from($line, $left + 1, $top + 2.5 + (2 * $index), $color, $node->fontScale));
        }
        $row = $top + 2 + (2 * \count($lines));
        $ticket = (string) \base64_decode($ticket, strict: true);
        $assigned = (string) \base64_decode($assigned, strict: true);
        if ('' !== $ticket) {
            $marks[] = self::text($ticket, $left + 1, $row, $color, $node);
        }
        if ('' !== $ticket && '1' === $linked) {
            $marks[] = new MermaidMark('polyline', [[$left + 1, $row + 0.8], [$left + 1 + AnsiUtils::visibleWidth($ticket), $row + 0.8]], stroke: $color, strokeWidth: 0.08);
        }
        if ('' !== $assigned) {
            $marks[] = self::text($assigned, $right - 1 - AnsiUtils::visibleWidth($assigned), $row, $color, $node);
        }

        return $marks;
    }

    /** A line of text starting at `$x`, centered on the row at `$y`; a wrapped line's trailing space is no indent. */
    private static function text(string $text, float $x, float $y, string $color, GraphNode $node): MermaidMark
    {
        $text = \rtrim($text, characters: ' ');

        return new MermaidMark('text', [[$x + (AnsiUtils::visibleWidth($text) / 2), $y + 0.5]], $text, stroke: $color, font: $node->font, fontScale: $node->fontScale);
    }
}
