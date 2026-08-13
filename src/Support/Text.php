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
        return rtrim(explode("\n", trim($text), limit: 2)[0], characters: "\r");
    }
}
