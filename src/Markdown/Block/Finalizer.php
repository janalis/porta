<?php

declare(strict_types=1);

namespace Porta\Markdown\Block;

use Porta\Markdown\Inline\Entities;
use Porta\Markdown\Inline\LinkReference;
use Porta\Markdown\MappedText;

/**
 * What closing a block settles: a paragraph gives up its leading link
 * reference definitions, a fence's first line becomes its info string, an
 * indented block drops trailing blank lines, a list learns whether it is tight.
 */
final class Finalizer
{
    /** @var array<string, LinkReference> */
    public array $references = [];

    public function finalize(OpenBlock $block): void
    {
        match ($block->kind) {
            BlockKind::Paragraph => $this->paragraph($block),
            BlockKind::CodeBlock => $this->code($block),
            BlockKind::List => $block->tight = self::tight($block),
            BlockKind::Item => self::endWithLastChild($block),
            default => null,
        };
    }

    /** Strip the definitions at the start of a paragraph's text. */
    public function takeReferences(OpenBlock $paragraph): void
    {
        $content = $paragraph->content;
        while (null !== $content && \str_starts_with($content->text, '[')) {
            $taken = ReferenceDefinitions::take($content->text, $this->references);
            if (0 === $taken) {
                break;
            }
            $content = $content->from($taken);
            $content = $content->from(\strspn($content->text, characters: " \t"));
        }
        $paragraph->content = $content;
    }

    public function addTableRow(OpenBlock $table, MappedText $line, LineCursor $cursor): void
    {
        if ('' === \trim($line->text, characters: " \t")) {
            return; // the delimiter row, its line consumed when the table opened
        }
        $lead = \strspn($line->text, characters: " \t");
        $table->rows[] = [$line->from($lead), $line->source($lead), $cursor->lineStart + \strlen(\rtrim($cursor->line, characters: " \t"))];
    }

    private function paragraph(OpenBlock $paragraph): void
    {
        $this->takeReferences($paragraph);
        if (null === $paragraph->content || '' === \trim($paragraph->content->text, characters: " \t\n")) {
            $paragraph->parent?->remove($paragraph);
        }
    }

    private function code(OpenBlock $block): void
    {
        if ($block->fenced) {
            $block->info = Entities::unescape(\trim(\array_shift($block->lines) ?? '', characters: " \t"));

            return;
        }
        while ([] !== $block->lines && '' === \trim($block->lines[\count($block->lines) - 1], characters: " \t")) {
            \array_pop($block->lines);
        }
    }

    /** An item ends where its content does: a blank line or a dropped definition after it is not part of it. */
    private static function endWithLastChild(OpenBlock $item): void
    {
        $last = $item->lastChild();
        if (null !== $last) {
            $item->end = $last->end;
            $item->endLine = $last->endLine;
        }
    }

    /**
     * A list is loose when a blank line separates two of its items, or two
     * blocks directly inside one of its items.
     */
    private static function tight(OpenBlock $list): bool
    {
        $items = $list->children;
        foreach ($items as $index => $item) {
            $next = $items[$index + 1] ?? null;
            if (null !== $next && $item->blankBefore($next)) {
                return false;
            }
            foreach ($item->children as $childIndex => $child) {
                $after = $item->children[$childIndex + 1] ?? null;
                if (null !== $after && $child->blankBefore($after)) {
                    return false;
                }
            }
        }

        return true;
    }
}
