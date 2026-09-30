<?php

declare(strict_types=1);

namespace Porta\Support;

/**
 * Plain-text sibling of {@see AnsiText}: helpers for text nobody has styled
 * yet.
 */
final class Text
{
    /**
     * The first line of $text with surrounding whitespace trimmed away; ''
     * for a blank input. The one-line summary every process-output and
     * error-banner site wants — previously hand-rolled in two spellings
     * (strtok, each copy carrying the same lint-suppression comment, and
     * explode()[0]) that disagreed only on whether they trimmed first.
     */
    public static function firstLine(string $text): string
    {
        return \rtrim(\explode("\n", \trim($text), limit: 2)[0], characters: "\r");
    }

    /**
     * The 0-based line and byte column of byte $offset in $text — where a
     * position found by searching a whole document sits for an editor that
     * counts in lines. An offset past the end is clamped to it.
     *
     * @return array{int, int}
     */
    public static function lineAndColumn(string $text, int $offset): array
    {
        $before = \substr($text, offset: 0, length: \max(0, $offset));
        $lineStart = \strrpos($before, needle: "\n");

        return [\substr_count($before, needle: "\n"), false === $lineStart ? \strlen($before) : \strlen($before) - $lineStart - 1];
    }
}
