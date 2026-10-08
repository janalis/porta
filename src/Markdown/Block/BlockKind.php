<?php

declare(strict_types=1);

namespace Porta\Markdown\Block;

/** What an open block is while the block parser builds the tree. */
enum BlockKind
{
    case Document;
    case BlockQuote;
    case List;
    case Item;
    case FootnoteDefinition;
    case Paragraph;
    case Heading;
    case ThematicBreak;
    case CodeBlock;
    case HtmlBlock;
    case Table;

    /** Whether a block of this kind may hold $child. */
    public function canContain(self $child): bool
    {
        return match ($this) {
            self::Document, self::BlockQuote, self::Item, self::FootnoteDefinition => self::Item !== $child,
            self::List => self::Item === $child,
            default => false,
        };
    }

    /** Whether the line's remaining text is added to a block of this kind. */
    public function acceptsLines(): bool
    {
        return match ($this) {
            self::Paragraph, self::CodeBlock, self::HtmlBlock, self::Table => true,
            default => false,
        };
    }

    public function isContainer(): bool
    {
        return match ($this) {
            self::Document, self::BlockQuote, self::List, self::Item, self::FootnoteDefinition => true,
            default => false,
        };
    }
}
