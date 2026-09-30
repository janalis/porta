<?php

declare(strict_types=1);

namespace Porta\Editor;

use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * Pure text-measurement algorithms shared by {@see Document}: UTF-8 character
 * boundaries, terminal display width with tab stops, tab expansion, and UTF-16
 * code-unit conversion (for LSP positions). Stateless — every method takes an
 * explicit line string — so the coordinate math is testable on its own and the
 * document is left owning only storage, cursor and history.
 */
final class TextMetrics
{
    /**
     * What `mb_strwidth()` measures differently from the terminal: combining
     * marks, format characters (a ZWJ), Hangul vowel/final jamo, skin-tone
     * modifiers. The vendor's own fast-path test, which it keeps private; text
     * without any of these is measured by `mb_strwidth()` alone.
     */
    private const string MISMEASURED = '/[\p{M}\p{Cf}\x{1160}-\x{11FF}\x{D7B0}-\x{D7FF}\x{1F3FB}-\x{1F3FF}]/u';

    /** Next UTF-8 character boundary at or after $col+1 in $line. */
    public static function nextCharBoundary(string $line, int $col): int
    {
        ++$col;
        $len = \strlen($line);
        while ($col < $len && (\ord($line[$col]) & 0xC0) === 0x80) {
            ++$col;
        }

        return \min($len, $col);
    }

    /** Previous UTF-8 character boundary before $col in $line. */
    public static function prevCharBoundary(string $line, int $col): int
    {
        --$col;
        while ($col > 0 && (\ord($line[$col]) & 0xC0) === 0x80) {
            --$col;
        }

        return \max(0, $col);
    }

    /**
     * The byte offset after the grapheme that starts at $col: a caret step or a
     * Delete. A grapheme is what the terminal draws as one character — a base
     * letter with its combining marks, a flag, a ZWJ family — so stepping by
     * code point stopped inside one and deleting that way left half of it.
     * LSP stays on code points: a UTF-16 position may point inside a grapheme.
     */
    public static function nextGrapheme(string $line, int $col): int
    {
        if ($col >= \strlen($line)) {
            return \strlen($line);
        }

        return $col + \strlen(self::graphemes(\substr($line, $col))[0]);
    }

    /** The byte offset where the grapheme before $col starts: a caret step left or a Backspace. */
    public static function prevGrapheme(string $line, int $col): int
    {
        if ($col <= 0) {
            return 0;
        }
        $graphemes = self::graphemes(\substr($line, offset: 0, length: $col));

        return $col - \strlen($graphemes[\count($graphemes) - 1]);
    }

    /**
     * $text split into graphemes. grapheme_extract() is not used: at an
     * invalid byte it skips that byte AND the grapheme after it, where a split
     * keeps the byte as one element. The code-point split is for the no-intl
     * polyfill, whose /u regex returns false on invalid UTF-8 — only the CI's
     * polyfill leg reaches it (contributing.md: intl is compiled in here).
     *
     * @return list<string>
     */
    private static function graphemes(string $text): array
    {
        /** @var list<string>|false $graphemes */
        $graphemes = \grapheme_str_split($text);

        return false === $graphemes ? \mb_str_split($text) : $graphemes;
    }

    /**
     * The largest UTF-8 character boundary at or before $col in $line, so a byte
     * offset carried onto another line (e.g. Up/Down) never lands mid-character.
     */
    public static function charBoundaryAtOrBefore(string $line, int $col): int
    {
        $col = \max(0, \min($col, \strlen($line)));
        while ($col > 0 && $col < \strlen($line) && (\ord($line[$col]) & 0xC0) === 0x80) {
            --$col;
        }

        return $col;
    }

    /**
     * Visual width of a string in terminal cells, expanding tabs to the next
     * multiple of $tabSize. A grapheme is as wide as the vendor draws it
     * ({@see AnsiUtils::graphemeWidth()}): summing code points gave a
     * decomposed accent two columns and a ZWJ family eight, and the caret
     * landed that far right of the text.
     */
    public static function displayWidth(string $text, int $tabSize): int
    {
        if (!\str_contains($text, "\t") && 1 !== \preg_match(self::MISMEASURED, $text)) {
            return \mb_strwidth($text, encoding: 'UTF-8');
        }

        $width = 0;
        foreach (self::graphemes($text) as $grapheme) {
            $width += self::cellWidth($grapheme, $width, $tabSize);
        }

        return $width;
    }

    /** Columns $grapheme takes when it starts at column $width: a tab runs to the next stop. */
    private static function cellWidth(string $grapheme, int $width, int $tabSize): int
    {
        return "\t" === $grapheme ? $tabSize - ($width % $tabSize) : AnsiUtils::graphemeWidth($grapheme);
    }

    /** Expand a single line's tabs to spaces at $tabSize tab stops. */
    public static function expandTabs(string $line, int $tabSize): string
    {
        $result = '';
        $width = 0;
        foreach (self::graphemes($line) as $grapheme) {
            $cells = self::cellWidth($grapheme, $width, $tabSize);
            $result .= "\t" === $grapheme ? \str_repeat(' ', \max(0, $cells)) : $grapheme;
            $width += $cells;
        }

        return $result;
    }

    /** Byte offset on $line for a given display column (terminal cell). */
    public static function byteColForDisplayCol(string $line, int $displayCol, int $tabSize): int
    {
        if ($displayCol <= 0 || '' === $line) {
            return 0;
        }
        // ASCII fast path
        if (\preg_match('/^[\x20-\x7E]*$/', $line)) {
            return \min($displayCol, \strlen($line));
        }

        // Whole graphemes only, so a column never lands inside one, where
        // typing would split it.
        $col = 0;
        $width = 0;
        foreach (self::graphemes($line) as $grapheme) {
            if ($width >= $displayCol) {
                break;
            }
            $width += self::cellWidth($grapheme, $width, $tabSize);
            $col += \strlen($grapheme);
        }

        return $col;
    }

    /** UTF-16 code-unit column for a byte offset on $line (for LSP positions). */
    public static function byteColToUtf16(string $line, int $byteCol): int
    {
        $prefix = \substr($line, offset: 0, length: $byteCol);

        return '' === $prefix ? 0 : \strlen((string) \mb_convert_encoding($prefix, to_encoding: 'UTF-16LE', from_encoding: 'UTF-8')) >> 1;
    }

    /** Byte offset on $line for a UTF-16 code-unit column (LSP position). */
    public static function utf16ColToByteCol(string $line, int $utf16Col): int
    {
        if ($utf16Col <= 0 || '' === $line) {
            return 0;
        }
        // ASCII fast path
        if (\preg_match('/^[\x20-\x7E]*$/', $line)) {
            return \min($utf16Col, \strlen($line));
        }

        $units = 0;
        $bytes = 0;
        $len = \strlen($line);
        while ($bytes < $len && $units < $utf16Col) {
            $next = self::nextCharBoundary($line, $bytes);
            $char = \substr($line, $bytes, $next - $bytes);
            $units += \strlen((string) \mb_convert_encoding($char, to_encoding: 'UTF-16LE', from_encoding: 'UTF-8')) >> 1;
            $bytes = $next;
        }

        return $bytes;
    }
}
