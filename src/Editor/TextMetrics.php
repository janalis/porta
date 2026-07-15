<?php

declare(strict_types=1);

namespace Porta\Editor;

/**
 * Pure text-measurement algorithms shared by {@see Document}: UTF-8 character
 * boundaries, terminal display width with tab stops, tab expansion, and UTF-16
 * code-unit conversion (for LSP positions). Stateless — every method takes an
 * explicit line string — so the coordinate math is testable on its own and the
 * document is left owning only storage, cursor and history.
 */
final class TextMetrics
{
    /** Next UTF-8 character boundary at or after $col+1 in $line. */
    public static function nextCharBoundary(string $line, int $col): int
    {
        ++$col;
        $len = \strlen($line);
        while ($col < $len && (\ord($line[$col]) & 0xC0) === 0x80) {
            ++$col;
        }

        return min($len, $col);
    }

    /** Previous UTF-8 character boundary before $col in $line. */
    public static function prevCharBoundary(string $line, int $col): int
    {
        --$col;
        while ($col > 0 && (\ord($line[$col]) & 0xC0) === 0x80) {
            --$col;
        }

        return max(0, $col);
    }

    /**
     * The largest UTF-8 character boundary at or before $col in $line, so a byte
     * offset carried onto another line (e.g. Up/Down) never lands mid-character.
     */
    public static function charBoundaryAtOrBefore(string $line, int $col): int
    {
        $col = max(0, min($col, \strlen($line)));
        while ($col > 0 && $col < \strlen($line) && (\ord($line[$col]) & 0xC0) === 0x80) {
            --$col;
        }

        return $col;
    }

    /**
     * Visual width of a string in terminal cells, expanding tabs to the next
     * multiple of $tabSize. Tab-free text takes the mb_strwidth fast path.
     */
    public static function displayWidth(string $text, int $tabSize): int
    {
        if ('' === $text) {
            return 0;
        }
        if (!str_contains($text, "\t")) {
            return mb_strwidth($text, encoding: 'UTF-8');
        }

        $width = 0;
        $col = 0;
        $len = \strlen($text);
        while ($col < $len) {
            if ("\t" === $text[$col]) {
                $width += $tabSize - ($width % $tabSize);
                ++$col;

                continue;
            }
            $next = self::nextCharBoundary($text, $col);
            $width += mb_strwidth(substr($text, $col, $next - $col), encoding: 'UTF-8');
            $col = $next;
        }

        return $width;
    }

    /** Expand a single line's tabs to spaces at $tabSize tab stops. */
    public static function expandTabs(string $line, int $tabSize): string
    {
        $result = '';
        $width = 0;
        $col = 0;
        $len = \strlen($line);
        while ($col < $len) {
            if ("\t" === $line[$col]) {
                $n = $tabSize - ($width % $tabSize);
                $result .= str_repeat(' ', max(0, $n));
                $width += $n;
                ++$col;

                continue;
            }
            $next = self::nextCharBoundary($line, $col);
            $seg = substr($line, $col, $next - $col);
            $result .= $seg;
            $width += mb_strwidth($seg, encoding: 'UTF-8');
            $col = $next;
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
        if (preg_match('/^[\x20-\x7E]*$/', $line)) {
            return min($displayCol, \strlen($line));
        }

        $col = 0;
        $width = 0;
        $len = \strlen($line);
        while ($col < $len && $width < $displayCol) {
            if ("\t" === $line[$col]) {
                $width += $tabSize - ($width % $tabSize);
                ++$col;

                continue;
            }
            $next = self::nextCharBoundary($line, $col);
            $char = substr($line, $col, $next - $col);
            $width += mb_strwidth($char, encoding: 'UTF-8');
            $col = $next;
        }

        return $col;
    }

    /** UTF-16 code-unit column for a byte offset on $line (for LSP positions). */
    public static function byteColToUtf16(string $line, int $byteCol): int
    {
        $prefix = substr($line, offset: 0, length: $byteCol);

        return '' === $prefix ? 0 : \strlen((string) mb_convert_encoding($prefix, to_encoding: 'UTF-16LE', from_encoding: 'UTF-8')) >> 1;
    }

    /** Byte offset on $line for a UTF-16 code-unit column (LSP position). */
    public static function utf16ColToByteCol(string $line, int $utf16Col): int
    {
        if ($utf16Col <= 0 || '' === $line) {
            return 0;
        }
        // ASCII fast path
        if (preg_match('/^[\x20-\x7E]*$/', $line)) {
            return min($utf16Col, \strlen($line));
        }

        $units = 0;
        $bytes = 0;
        $len = \strlen($line);
        while ($bytes < $len && $units < $utf16Col) {
            $next = self::nextCharBoundary($line, $bytes);
            $char = substr($line, $bytes, $next - $bytes);
            $units += \strlen((string) mb_convert_encoding($char, to_encoding: 'UTF-16LE', from_encoding: 'UTF-8')) >> 1;
            $bytes = $next;
        }

        return $bytes;
    }
}
