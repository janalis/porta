<?php

declare(strict_types=1);

namespace Porta\Markdown\Block;

/**
 * Whether an open block continues on the current line, each kind consuming
 * its own prefix: a quote's `>`, an item's indentation, a code block's four
 * columns. A closing code fence consumes the whole line.
 */
final class Continuation
{
    public const int MATCHED = 0;
    public const int FAILED = 1;
    public const int CONSUMED = 2;

    private const int CODE_INDENT = 4;

    public function continues(LineCursor $cursor, OpenBlock $block): int
    {
        return match ($block->kind) {
            BlockKind::BlockQuote => $this->quote($cursor),
            BlockKind::Item => $this->item($cursor, $block),
            BlockKind::FootnoteDefinition => $this->footnote($cursor),
            BlockKind::CodeBlock => $block->fenced ? $this->fence($cursor, $block) : $this->indentedCode($cursor),
            BlockKind::HtmlBlock => $cursor->blank && $block->htmlType >= 6 ? self::FAILED : self::MATCHED,
            BlockKind::Paragraph, BlockKind::Table => $cursor->blank ? self::FAILED : self::MATCHED,
            BlockKind::Heading, BlockKind::ThematicBreak => self::FAILED,
            default => self::MATCHED,
        };
    }

    private function quote(LineCursor $cursor): int
    {
        if ($cursor->indented() || '>' !== $cursor->peekNonspace()) {
            return self::FAILED;
        }
        $cursor->advanceNextNonspace();
        $cursor->advance(1);
        if (self::spaceOrTab($cursor->line[$cursor->offset] ?? '')) {
            $cursor->advanceColumns(1);
        }

        return self::MATCHED;
    }

    private function item(LineCursor $cursor, OpenBlock $block): int
    {
        // Every item opens with its marker read.
        $indent = ($block->marker->markerOffset ?? 0) + ($block->marker->padding ?? 0);

        return match (true) {
            $cursor->blank && [] === $block->children => self::FAILED, // a blank line after an empty item ends it
            $cursor->blank => self::matchBlank($cursor),
            $cursor->indent >= $indent => self::matchIndent($cursor, $indent),
            default => self::FAILED,
        };
    }

    private function footnote(LineCursor $cursor): int
    {
        return match (true) {
            $cursor->blank => self::matchBlank($cursor),
            $cursor->indent >= self::CODE_INDENT => self::matchIndent($cursor, self::CODE_INDENT),
            default => self::FAILED,
        };
    }

    private function fence(LineCursor $cursor, OpenBlock $block): int
    {
        $m = [];
        if (
            $cursor->indent <= 3
            && $block->fenceChar === $cursor->peekNonspace()
            && 1 === \preg_match('/^(?:`{3,}|~{3,})(?=[ \t]*$)/', $cursor->restFromNonspace(), $m)
            && \strlen($m[0]) >= $block->fenceLength
        ) {
            return self::CONSUMED;
        }
        for ($i = $block->fenceOffset; $i > 0 && self::spaceOrTab($cursor->line[$cursor->offset] ?? ''); --$i) {
            $cursor->advanceColumns(1);
        }

        return self::MATCHED;
    }

    private function indentedCode(LineCursor $cursor): int
    {
        return match (true) {
            $cursor->indent >= self::CODE_INDENT => self::matchIndent($cursor, self::CODE_INDENT),
            $cursor->blank => self::matchBlank($cursor),
            default => self::FAILED,
        };
    }

    /** A blank line continues the block: skip to its end. */
    private static function matchBlank(LineCursor $cursor): int
    {
        $cursor->advanceNextNonspace();

        return self::MATCHED;
    }

    /** An indented line continues the block: consume the block's own indentation. */
    private static function matchIndent(LineCursor $cursor, int $columns): int
    {
        $cursor->advanceColumns($columns);

        return self::MATCHED;
    }

    public static function spaceOrTab(string $c): bool
    {
        return ' ' === $c || "\t" === $c;
    }
}
