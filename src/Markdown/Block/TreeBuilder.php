<?php

declare(strict_types=1);

namespace Porta\Markdown\Block;

use Porta\Markdown\Inline\InlineContext;
use Porta\Markdown\Inline\InlineParser;
use Porta\Markdown\Inline\LinkSyntax;
use Porta\Markdown\MappedText;
use Porta\Markdown\Node\AlertKind;
use Porta\Markdown\Node\Alignment;
use Porta\Markdown\Node\Block;
use Porta\Markdown\Node\BlockQuote;
use Porta\Markdown\Node\CodeBlock;
use Porta\Markdown\Node\Document;
use Porta\Markdown\Node\FootnoteDefinition;
use Porta\Markdown\Node\Heading;
use Porta\Markdown\Node\HtmlBlock;
use Porta\Markdown\Node\ListBlock;
use Porta\Markdown\Node\ListItem;
use Porta\Markdown\Node\Paragraph;
use Porta\Markdown\Node\Table;
use Porta\Markdown\Node\TableCell;
use Porta\Markdown\Node\TableRow;
use Porta\Markdown\Node\ThematicBreak;
use Porta\Markdown\SourceRange;

/**
 * The finished block tree as immutable nodes, each leaf's text run through
 * the inline parser. GitHub's two block-level readings that hinge on a
 * paragraph's first characters happen here: a `[ ]`/`[x]` opening a list
 * item makes it a task, and a `[!NOTE]` line opening a quote makes it an alert.
 */
final class TreeBuilder
{
    private InlineContext $context;

    public function __construct(
        private readonly InlineParser $inlines = new InlineParser(),
    ) {
        $this->context = new InlineContext();
    }

    public function build(ParsedBlocks $parsed): Document
    {
        $footnotes = [];
        self::collectFootnotes($parsed->document, $footnotes);
        $this->context = new InlineContext($parsed->references, $footnotes);

        return new Document($this->blocks($parsed->document), new SourceRange(0, $parsed->document->end));
    }

    /** @param array<string, true> $labels */
    private static function collectFootnotes(OpenBlock $block, array &$labels): void
    {
        if (BlockKind::FootnoteDefinition === $block->kind) {
            $labels[LinkSyntax::normalize($block->label)] = true;
        }
        foreach ($block->children as $child) {
            self::collectFootnotes($child, $labels);
        }
    }

    /** @return list<Block> */
    private function blocks(OpenBlock $parent): array
    {
        $blocks = [];
        foreach ($parent->children as $child) {
            $blocks[] = $this->block($child);
        }

        return $blocks;
    }

    private function block(OpenBlock $block): Block
    {
        $range = new SourceRange($block->start, \max($block->start, $block->end));

        return match ($block->kind) {
            BlockKind::Paragraph => $this->paragraph($block),
            BlockKind::Heading => new Heading($block->level, $this->inlines->parse($block->content ?? MappedText::verbatim('', $block->start), $this->context), $range, $block->setext),
            BlockKind::ThematicBreak => new ThematicBreak($range),
            BlockKind::CodeBlock => $this->code($block, $range),
            BlockKind::HtmlBlock => new HtmlBlock(\implode("\n", $block->lines), $range),
            BlockKind::BlockQuote => $this->quote($block, $range),
            BlockKind::List => $this->list($block, $range),
            BlockKind::Item => $this->item($block, $range),
            BlockKind::FootnoteDefinition => new FootnoteDefinition($block->label, $this->blocks($block), $range),
            BlockKind::Table => $this->table($block, $range),
            BlockKind::Document => new Document($this->blocks($block), $range),
        };
    }

    private function paragraph(OpenBlock $block): Paragraph
    {
        $content = $block->content ?? MappedText::verbatim('', $block->start);
        $start = $content->source(\strspn($content->text, characters: " \t\n"));

        return new Paragraph($this->inlines->parse($content, $this->context), new SourceRange($start, \max($start, $block->end)));
    }

    private function code(OpenBlock $block, SourceRange $range): CodeBlock
    {
        $literal = [] === $block->lines ? '' : \implode("\n", $block->lines)."\n";
        $bodyStart = $block->bodyStart < 0 ? $range->end : $block->bodyStart;
        $bodyEnd = $block->fenced && [] !== $block->lines ? \max($bodyStart, $block->bodyEnd) : \max($bodyStart, $range->end);

        return new CodeBlock($literal, $block->info, $block->fenced, $range, new SourceRange($bodyStart, \min($bodyEnd, $range->end)));
    }

    private function quote(OpenBlock $block, SourceRange $range): BlockQuote
    {
        $first = $block->children[0] ?? null;
        $m = [];
        if (
            null === $first
            || BlockKind::Paragraph !== $first->kind
            || null === $first->content
            || 1 !== \preg_match('/^\[!(NOTE|TIP|IMPORTANT|WARNING|CAUTION)\][ \t]*(?:\n|$)/i', $first->content->text, $m)
        ) {
            return new BlockQuote($this->blocks($block), $range);
        }
        $rest = $first->content->from(\strlen($m[0]));
        self::keepRest($block, $first, $rest);

        return new BlockQuote($this->blocks($block), $range, AlertKind::from(\strtoupper($m[1])));
    }

    /** A paragraph keeps what follows the marker it opened with, or goes when nothing does. */
    private static function keepRest(OpenBlock $parent, OpenBlock $paragraph, MappedText $rest): void
    {
        $paragraph->content = $rest;
        if ('' === \trim($rest->text)) {
            $parent->remove($paragraph);
        }
    }

    private function list(OpenBlock $block, SourceRange $range): ListBlock
    {
        $marker = $block->marker;
        $items = [];
        foreach ($block->children as $child) {
            $items[] = $this->item($child, new SourceRange($child->start, \max($child->start, $child->end)));
        }

        return new ListBlock($marker->ordered ?? false, $marker->start ?? 1, $block->tight, $marker->char ?? '-', $items, $range);
    }

    private function item(OpenBlock $block, SourceRange $range): ListItem
    {
        $marker = new SourceRange($block->marker->sourceStart ?? $block->start, $block->marker->sourceEnd ?? $block->start);
        $first = $block->children[0] ?? null;
        $m = [];
        if (null === $first || BlockKind::Paragraph !== $first->kind || null === $first->content || 1 !== \preg_match('/^\[([ xX])\](?=[ \t\n]|$)/', $first->content->text, $m)) {
            return new ListItem($this->blocks($block), $range, $marker);
        }
        $checkbox = $first->content->range(0, 3);
        $rest = $first->content->from(3);
        self::keepRest($block, $first, $rest);

        return new ListItem($this->blocks($block), $range, $marker, ' ' !== $m[1], $checkbox);
    }

    private function table(OpenBlock $block, SourceRange $range): Table
    {
        $alignments = \array_map(static fn (string $cell): Alignment => match (true) {
            \str_starts_with($cell, ':') && \str_ends_with($cell, ':') => Alignment::Center,
            \str_starts_with($cell, ':') => Alignment::Left,
            \str_ends_with($cell, ':') => Alignment::Right,
            default => Alignment::None,
        }, $block->alignments);
        $rows = [];
        foreach ($block->rows as [$text, $start, $end]) {
            $rows[] = $this->row($text, new SourceRange($start, \max($start, $end)), \count($alignments));
        }
        $header = \array_shift($rows) ?? new TableRow([], $range);

        return new Table($alignments, $header, $rows, $range);
    }

    /** One row, cut or padded to the header's number of cells. */
    private function row(MappedText $text, SourceRange $range, int $columns): TableRow
    {
        $cells = [];
        foreach (\array_slice(TableRows::cells($text), offset: 0, length: $columns) as $cell) {
            $cells[] = new TableCell($this->inlines->parse($cell, $this->context), $cell->range(0, \strlen($cell->text)));
        }
        while (\count($cells) < $columns) {
            $cells[] = new TableCell([], new SourceRange($range->end, $range->end));
        }

        return new TableRow($cells, $range);
    }
}
