<?php

declare(strict_types=1);

namespace Porta\Markdown\Block;

/**
 * Reads a list item's marker at the cursor and moves past it and the spaces
 * that set the item's content column — one to four of them, or exactly one
 * when there are five or more (the rest is then indented code) or none.
 */
final class ListMarkerReader
{
    public function read(LineCursor $cursor, OpenBlock $container): ?ListMarker
    {
        $rest = $cursor->restFromNonspace();
        $inParagraph = BlockKind::Paragraph === $container->kind;
        $marker = self::marker($rest, $container);
        if (null === $marker) {
            return null;
        }
        [$ordered, $char, $start, $length] = $marker;
        $next = $rest[$length] ?? '';
        if (!('' === $next || Continuation::spaceOrTab($next))) {
            return null;
        }
        if ($inParagraph && '' === \trim(\substr($rest, $length), characters: " \t")) {
            return null; // an empty item cannot interrupt a paragraph
        }

        return $this->consume($cursor, $ordered, $char, $start, $length);
    }

    /**
     * A bullet, or a number of up to nine digits and its `.`/`)`. An ordered
     * item interrupting a paragraph must start at 1.
     *
     * @return array{bool, string, int, int}|null ordered, the marker character, the number, the marker's length
     */
    private static function marker(string $rest, OpenBlock $container): ?array
    {
        $m = [];
        if (1 === \preg_match('/^[*+-]/', $rest, $m)) {
            return [false, $m[0], 0, 1];
        }
        if (1 !== \preg_match('/^(\d{1,9})([.)])/', $rest, $m) || (BlockKind::Paragraph === $container->kind && 1 !== (int) $m[1])) {
            return null;
        }

        return [true, $m[2], (int) $m[1], \strlen($m[0])];
    }

    private function consume(LineCursor $cursor, bool $ordered, string $char, int $start, int $length): ListMarker
    {
        $markerOffset = $cursor->indent;
        $sourceStart = $cursor->source($cursor->nextNonspace);
        $cursor->advanceNextNonspace();
        $cursor->advanceColumns($length);
        $spacesColumn = $cursor->column;
        $spacesOffset = $cursor->offset;
        do {
            $cursor->advanceColumns(1);
        } while (($cursor->column - $spacesColumn) < 5 && Continuation::spaceOrTab($cursor->line[$cursor->offset] ?? ''));
        $blankItem = $cursor->offset >= \strlen($cursor->line);
        $spaces = $cursor->column - $spacesColumn;
        $padding = $length + $spaces;
        if ($spaces >= 5 || $spaces < 1 || $blankItem) {
            // The content starts one space after the marker; the rest is its indentation.
            $padding = $length + 1;
            $cursor->column = $spacesColumn;
            $cursor->offset = $spacesOffset;
            $cursor->partialTab = false;
            if (Continuation::spaceOrTab($cursor->line[$cursor->offset] ?? '')) {
                $cursor->advanceColumns(1);
            }
        }

        return new ListMarker($ordered, $char, $start, $markerOffset, $padding, $sourceStart, $sourceStart + $length);
    }
}
