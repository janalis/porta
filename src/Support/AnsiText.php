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
     * $marker visible by horizontally scrolling the whole line to the caret. For
     * a mid-line input (find bar, results) whose field can't tail-scroll on its
     * own; a line with no marker just clips from the start.
     */
    public static function scrollLineToCaret(string $line, int $width, string $marker): string
    {
        $pos = strpos($line, $marker);
        if (false === $pos) {
            return self::padRight(AnsiUtils::truncateToWidth($line, $width, '…'), $width);
        }
        $caretCol = AnsiUtils::visibleWidth(substr($line, 0, $pos));
        $bare = substr($line, 0, $pos).substr($line, $pos + \strlen($marker));

        return self::padRight(self::caretWindow($bare, $caretCol, $width, $marker), $width);
    }
}
