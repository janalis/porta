<?php

declare(strict_types=1);

namespace Porta\Support;

use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * Small helpers for ANSI-styled text measured in display columns, wrapping the
 * `str_repeat(' ', max(0, $width - AnsiUtils::visibleWidth($s)))` idiom the
 * renderers hand-rolled in several places.
 */
final class AnsiText
{
    /**
     * Right-pad $text with spaces so its visible width reaches $width (a no-op
     * when it is already at least that wide). SGR codes are not counted.
     */
    public static function padRight(string $text, int $width): string
    {
        return $text.str_repeat(' ', max(0, $width - AnsiUtils::visibleWidth($text)));
    }

    /**
     * The $width-column window of $text that keeps display column $caretCol
     * visible, with $marker inserted exactly at the caret. Used to horizontally
     * scroll a single-line input so its (zero-width) cursor marker never falls
     * past the width cut. When $text fits, the window starts at column 0.
     *
     * The window is sliced from the raw text and the marker re-inserted, so the
     * marker cannot be lost at the truncation boundary.
     */
    public static function caretWindow(string $text, int $caretCol, int $width, string $marker): string
    {
        $off = max(0, $caretCol - $width + 1);
        $left = AnsiUtils::sliceByColumn($text, $off, $caretCol - $off);
        $right = AnsiUtils::sliceByColumn($text, $caretCol, $width - ($caretCol - $off));

        return $left.$marker.$right;
    }

    /**
     * Clip a fully-assembled line to $width, padded, keeping any embedded cursor
     * marker visible by horizontally scrolling the whole line to the caret. The
     * marker is located by its APC prefix (shape-agnostic, so callers need not
     * know the configured cursor shape). For a mid-line input (find bar, results)
     * whose field can't tail-scroll on its own; a line with no marker just clips
     * from the start.
     */
    public static function scrollLineToCaret(string $line, int $width): string
    {
        $pos = strpos($line, AnsiUtils::CURSOR_MARKER_PREFIX);
        $end = false === $pos ? false : strpos($line, needle: "\x07", offset: $pos);
        if (false === $pos || false === $end) {
            return self::padRight(AnsiUtils::truncateToWidth($line, $width, '…'), $width);
        }
        $marker = substr($line, $pos, $end - $pos + 1);
        $caretCol = AnsiUtils::visibleWidth(substr($line, offset: 0, length: $pos));
        $bare = substr($line, offset: 0, length: $pos).substr($line, $end + 1);

        return self::padRight(self::caretWindow($bare, $caretCol, $width, $marker), $width);
    }
}
