<?php

declare(strict_types=1);

namespace Porta\Editor;

use Porta\Ui\Glyphs;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * PhpStorm's Show Whitespaces, over the LEADING and TRAILING runs only.
 *
 * Not the inner ones. PhpStorm offers the three separately and turning all of
 * them on writes a dot between every word — legible on a wide screen with a
 * pale grey, unreadable in a terminal that has one intensity and eighty
 * columns. Leading and trailing are also where the questions actually are:
 * is this line indented with tabs or spaces, and is there junk after the
 * semicolon.
 *
 * The substitution is one display column for one display column, so nothing
 * downstream — the column slice, the spans, the width assertions — sees a
 * different line than it would have.
 */
final class WhitespaceGlyphs
{
    /**
     * Mark the whitespace in an already-highlighted line.
     *
     * $raw is the same line before tab expansion, which is the only place the
     * tabs still exist: {@see Document::getVisualLines()} turns them into
     * spaces before the highlighter ever sees them, so by here a tab and four
     * spaces are the same bytes.
     *
     * @param int $tabSize the stop width the expansion used; a tab covers the
     *                     columns from where it starts to the next multiple
     */
    public static function apply(string $line, string $raw, int $tabSize = Document::TAB_SIZE): string
    {
        $tabs = self::leadingTabColumns($raw, $tabSize);
        $out = '';
        $col = 0; // display column, meaningful only while still in the indent
        $indent = true; // no non-space visible character seen yet
        $trailing = null; // byte offset in $out where the current run of spaces began
        $i = 0;
        $length = \strlen($line);

        while ($i < $length) {
            $ansi = AnsiUtils::extractAnsiCode($line, $i);
            if (null !== $ansi) {
                // Escapes carry no column and must survive verbatim.
                $out .= $ansi['code'];
                $i += $ansi['length'];
                continue;
            }

            $char = $line[$i];
            ++$i;
            if (' ' !== $char) {
                $indent = false;
                $trailing = null;
                $out .= $char;
                continue;
            }

            $trailing ??= \strlen($out);
            if (!$indent) {
                $out .= $char; // decided at the end: only a run that reaches EOL is trailing
                continue;
            }

            // A tab is an arrow and then BLANK, the way a graphical editor
            // draws it — not four dots. That is also what keeps a tab legible
            // when an indent guide takes the arrow's cell: the blank padding
            // still tells it apart from four spaces, which would be four dots.
            $out .= match ($tabs[$col] ?? null) {
                self::TAB_START => Glyphs::WHITESPACE_TAB,
                self::TAB_PAD => ' ',
                default => Glyphs::WHITESPACE_SPACE,
            };
            ++$col;
        }

        // A run of spaces still open at end-of-line is the trailing one. Marked
        // here rather than as it was written, because until the line ends there
        // is no telling a trailing run from an inner one.
        return null === $trailing ? $out : \substr($out, offset: 0, length: $trailing).\str_replace(' ', Glyphs::WHITESPACE_SPACE, \substr($out, $trailing));
    }

    private const int TAB_START = 1;
    private const int TAB_PAD = 2;

    /**
     * The display columns a TAB covers within the line's indent, each marked as
     * its start or its padding.
     *
     * Only the indent: a tab used to align a trailing comment is rare, and
     * finding it would need the display column of every character before it,
     * wide runes included. The indent is all spaces and tabs, so its columns
     * are countable without measuring anything.
     *
     * @return array<int, self::TAB_*>
     */
    private static function leadingTabColumns(string $raw, int $tabSize): array
    {
        $columns = [];
        $col = 0;
        // Bounded by the indent itself rather than broken out of: the loop then
        // has no exit to get wrong, and `strspn` is how the indent guides find
        // the same run.
        for ($i = 0, $indent = \strspn($raw, characters: " \t"); $i < $indent; ++$i) {
            if (' ' === $raw[$i]) {
                ++$col;
                continue;
            }

            // A tab runs to the next stop, so its width depends on where it
            // starts: at column 6 with 4-column stops it covers 6 and 7.
            $stop = $col + $tabSize - ($col % $tabSize);
            $columns[$col] = self::TAB_START;
            for ($pad = $col + 1; $pad < $stop; ++$pad) {
                $columns[$pad] = self::TAB_PAD;
            }
            $col = $stop;
        }

        return $columns;
    }
}
