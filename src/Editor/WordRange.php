<?php

declare(strict_types=1);

namespace Porta\Editor;

use Porta\Support\IntMath;

/**
 * The run of `[\w$]` characters containing or touching a column of a line —
 * what a double-click selects, a Ctrl+click follows and the inlay-hint card
 * reads. `$` counts on both sides here, unlike {@see CaretWord}'s identifier.
 */
final class WordRange
{
    /**
     * The byte range of the word at $col of $line, or null when the column is
     * on no word character.
     *
     * @return array{startCol: int, endCol: int}|null
     */
    public static function at(string $line, int $col): ?array
    {
        $col = IntMath::clamp($col, 0, \strlen($line));
        // Matched as UTF-8, so `\w` is a letter of any script: byte by byte,
        // double-clicking `$café` selected `$caf`, and `名前` nothing. A line
        // that is not UTF-8 (a Latin-1 file) is read by bytes, as it was: with
        // /u the match fails outright, and nothing was selected.
        $utf8 = \mb_check_encoding($line, encoding: 'UTF-8') ? 'u' : '';
        $before = [];
        $after = [];
        \preg_match('/[\w$]*$/'.$utf8, \substr($line, offset: 0, length: $col), $before);
        \preg_match('/^[\w$]*/'.$utf8, \substr($line, $col), $after);
        $start = $col - \strlen($before[0] ?? '');
        $end = $col + \strlen($after[0] ?? '');

        return $end > $start ? ['startCol' => $start, 'endCol' => $end] : null;
    }
}
