<?php

declare(strict_types=1);

namespace Porta\Support;

use Porta\Ui\Glyphs;
use Porta\Ui\Sgr;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * Small helpers for ANSI-styled text measured in display columns, wrapping the
 * `str_repeat(' ', max(0, $width - AnsiUtils::visibleWidth($s)))` idiom the
 * renderers hand-rolled in several places.
 */
final class AnsiText
{
    /**
     * Clip $text to $width display columns, marking the cut with the glyph
     * vocabulary's {@see Glyphs::ELLIPSIS}. SGR codes are not counted.
     *
     * This exists because the mark cannot be defaulted at the vendor seam:
     * AnsiUtils::truncateToWidth defaults $ellipsis to '...' (three ASCII
     * dots), so every one of the IDE's ~50 truncation sites had to pass '…'
     * by hand — and the vocabulary's rule (Glyphs::ELLIPSIS) held by
     * convention only. Here it holds by construction.
     *
     * Sites that deliberately truncate WITHOUT a mark (PTY output rows,
     * fixed-width chrome fillers) pass $ellipsis: '' — a named argument that
     * reads as its own justification — and say why.
     */
    public static function truncate(string $text, int $width, bool $pad = false, string $ellipsis = Glyphs::ELLIPSIS): string
    {
        return AnsiUtils::truncateToWidth($text, $width, $ellipsis, $pad);
    }

    /**
     * Render a whole line faint: SGR 2 up front, re-armed after every reset
     * that would cancel it (`0`, or intensity's own `22` — checked as whole
     * params, so `1;2`-style lists pass untouched), closed at the end. The
     * regex matches only CSI-SGR sequences, so APC cursor markers and every
     * other escape family pass through byte-identical — the same construction
     * {@see \Symfony\Component\Tui\Ansi\AnsiUtils::reapplyBackgroundAfterResets}
     * uses for backgrounds (Style re-arms only backgrounds, never intensity,
     * which is why this transform exists). Styles emit SGR 2 even in the
     * monochrome theme, so a dimmed backdrop reads under --no-ansi too.
     */
    public static function dim(string $line): string
    {
        if (!str_contains($line, "\x1b")) {
            return Sgr::FAINT.$line.Sgr::NORMAL_INTENSITY;
        }

        $reArmed = (string) preg_replace_callback(
            '/\x1b\[([\d;]*)m/',
            static function (array $match): string {
                $params = explode(';', $match[1]);

                return '' === $match[1] || \in_array('0', $params, strict: true) || \in_array('22', $params, strict: true)
                    ? $match[0].Sgr::FAINT
                    : $match[0];
            },
            $line,
        );

        return Sgr::FAINT.$reArmed.Sgr::NORMAL_INTENSITY;
    }

    /**
     * A run of $width spaces (clamped at zero) — the "nothing at this index"
     * filler row every panel renderer hand-rolled as
     * `str_repeat(' ', max(0, $w))`.
     */
    public static function blank(int $width): string
    {
        return str_repeat(' ', max(0, $width));
    }

    /**
     * Right-pad $text with spaces so its visible width reaches $width (a no-op
     * when it is already at least that wide). SGR codes are not counted.
     */
    public static function padRight(string $text, int $width): string
    {
        return $text.str_repeat(' ', max(0, $width - AnsiUtils::visibleWidth($text)));
    }

    /**
     * Word-wrap $text into lines of at most $width display columns: greedy on
     * spaces, hard-breaking any single word wider than a whole line. Meant for
     * plain text — wrap first, style each returned line after — so an SGR code
     * can never straddle a break. Empty input yields one empty line, keeping
     * "at least one row" a guarantee rather than a caller special case.
     *
     * @return non-empty-list<string>
     */
    public static function wrap(string $text, int $width): array
    {
        $width = max(1, $width);
        $lines = [];
        $line = '';
        foreach (explode(' ', $text) as $word) {
            $candidate = '' === $line ? $word : $line.' '.$word;
            if (AnsiUtils::visibleWidth($candidate) <= $width) {
                $line = $candidate;
                continue;
            }
            if ('' !== $line) {
                $lines[] = $line;
            }
            while (AnsiUtils::visibleWidth($word) > $width) {
                $head = AnsiUtils::sliceByColumn($word, startCol: 0, length: $width);
                if ('' === $head) {
                    break; // a single glyph wider than the whole line; the caller's clip handles it
                }
                $lines[] = $head;
                // Plain text, so the byte length of the head is exactly what was consumed.
                $word = substr($word, \strlen($head));
            }
            $line = $word;
        }
        $lines[] = $line;

        return $lines;
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
            return self::padRight(self::truncate($line, $width), $width);
        }
        $marker = substr($line, $pos, $end - $pos + 1);
        $caretCol = AnsiUtils::visibleWidth(substr($line, offset: 0, length: $pos));
        $bare = substr($line, offset: 0, length: $pos).substr($line, $end + 1);

        return self::padRight(self::caretWindow($bare, $caretCol, $width, $marker), $width);
    }
}
