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
        return AnsiSlice::truncate($text, $width, $ellipsis, $pad);
    }

    /**
     * Truncate to $width by cutting the MIDDLE out, keeping both ends.
     *
     * For a path, the tail is the part that identifies it: cutting from the
     * right turns `src/Some/Deep/Namespace/ExampleClass.php` into
     * `src/Some…`, which names nothing. This gives `src/So…leClass.php`
     * instead — the same budget spent on both ends.
     *
     * Slicing by COLUMN on both sides rather than by character, so a
     * double-width grapheme cannot make the result one column wider than
     * asked; and text already inside $width comes back byte-identical, so a
     * caller can compare the two to learn whether anything was cut.
     */
    public static function elideMiddle(string $text, int $width, string $ellipsis = Glyphs::ELLIPSIS): string
    {
        $visible = AnsiUtils::visibleWidth($text);
        $mark = AnsiUtils::visibleWidth($ellipsis);
        if ($visible <= $width) {
            return $text;
        }
        if ($width <= $mark) {
            // No room for two ends and a mark between them; the ordinary
            // tail cut is the only thing that fits, and it says the same.
            return self::truncate($text, $width, ellipsis: $ellipsis);
        }
        // The tail gets the larger half: it carries the filename.
        $room = $width - $mark;
        $head = \intdiv($room, num2: 2);

        return AnsiSlice::byColumn($text, startCol: 0, length: $head).$ellipsis.AnsiSlice::byColumn($text, startCol: $visible - ($room - $head), length: $room - $head);
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
        if (!\str_contains($line, "\x1b")) {
            return Sgr::FAINT.$line.Sgr::NORMAL_INTENSITY;
        }

        $reArmed = (string) \preg_replace_callback(
            '/\x1b\[([\d;]*)m/',
            static function (array $match): string {
                $params = \explode(';', $match[1]);

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
        return \str_repeat(' ', \max(0, $width));
    }

    /**
     * Right-pad $text with spaces so its visible width reaches $width (a no-op
     * when it is already at least that wide). SGR codes are not counted.
     */
    public static function padRight(string $text, int $width): string
    {
        return $text.\str_repeat(' ', \max(0, $width - AnsiUtils::visibleWidth($text)));
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
        $width = \max(1, $width);
        $lines = [];
        $line = '';
        foreach (\explode(' ', $text) as $word) {
            $candidate = '' === $line ? $word : $line.' '.$word;
            if (AnsiUtils::visibleWidth($candidate) <= $width) {
                $line = $candidate;
                continue;
            }
            if ('' !== $line) {
                $lines[] = $line;
            }
            while (AnsiUtils::visibleWidth($word) > $width) {
                $head = AnsiSlice::byColumn($word, startCol: 0, length: $width);
                if ('' === $head) {
                    break; // a single glyph wider than the whole line; the caller's clip handles it
                }
                $lines[] = $head;
                // Plain text, so the byte length of the head is exactly what was consumed.
                $word = \substr($word, \strlen($head));
            }
            $line = $word;
        }
        $lines[] = $line;

        return $lines;
    }

    /**
     * The columns of $line from EXACTLY $startCol, at most $length wide — the
     * slice a caller composing a row around a cut (an overlay's tail, a pane
     * seam, an overwrite) actually means.
     *
     * `AnsiUtils::sliceByColumn` gets both edges wrong for a double-width
     * glyph once $startCol > 0. Non-strict, it KEEPS a glyph straddling the end,
     * so `("a中b", 1, 1)` is two columns wide and the renderer throws on the
     * row. Strict or not, it DROPS a glyph straddling the start, so
     * `("a中b", 2, 5)` is `b` — which belongs at column 3, and everything
     * composed after it shifts one column left. (A $startCol of 0 takes the
     * vendor's prefix path, which already drops an end straddler.)
     *
     * Here the end is strict — the {@see caretWindow()} reasoning — and the
     * half of a start straddler that falls inside the range is a blank, so
     * the first column returned is column $startCol.
     */
    public static function columnsFrom(string $line, int $startCol, int $length): string
    {
        $slice = AnsiSlice::byColumn($line, $startCol, $length, strict: true);
        if ($startCol <= 0 || $length <= 0) {
            return $slice;
        }
        $before = AnsiUtils::visibleWidth(AnsiSlice::byColumn($line, 0, $startCol));
        if ($before >= $startCol || AnsiUtils::visibleWidth($line) <= $startCol) {
            return $slice; // nothing straddles the start, or the line ends before it
        }
        // The prefix stopped at $before, short of $startCol only because the
        // straddler did not fit: its inside half is the gap (one column, for a
        // double-width glyph). max() proves the guard to the analyzer.
        $lead = \max(1, $startCol - $before);

        return \str_repeat(' ', $lead).AnsiSlice::byColumn($line, $startCol + $lead, $length - $lead, strict: true);
    }

    /**
     * $text without its hardware-cursor markers (every shape). The pattern is
     * built from the vendor's own prefix, so a marker change upstream cannot
     * leave a stale literal matching nothing.
     */
    public static function stripCursorMarkers(string $text): string
    {
        $prefix = \preg_quote(AnsiUtils::CURSOR_MARKER_PREFIX, delimiter: '/');

        return (string) \preg_replace('/'.$prefix.'[^\x07]*\x07/', replacement: '', subject: $text);
    }

    /**
     * The $width-column window of $text that keeps display column $caretCol
     * visible, with $marker inserted exactly at the caret. Used to horizontally
     * scroll a single-line input so its (zero-width) cursor marker never falls
     * past the width cut. When $text fits, the window starts at column 0.
     *
     * The window is sliced from the raw text and the marker re-inserted, so the
     * marker cannot be lost at the truncation boundary.
     *
     * Both slices are STRICT. `sliceByColumn` defaults to non-strict, which
     * keeps a grapheme that STARTS inside the range and ends past it — so a
     * double-width character landing on the last column came back one column
     * wider than asked for. Nothing downstream caught it: {@see
     * scrollLineToCaret()} finishes with {@see padRight()}, which is a floor
     * and never truncates, so the over-wide row went to the renderer and threw.
     * A find bar, a settings field or a Search Everywhere query row was one CJK
     * or emoji character away from taking the session down. Strict drops the
     * straddling glyph instead, and the pad fills the column it leaves.
     */
    public static function caretWindow(string $text, int $caretCol, int $width, string $marker): string
    {
        $off = \max(0, $caretCol - $width + 1);
        $left = AnsiSlice::byColumn($text, $off, $caretCol - $off, strict: true);
        $right = AnsiSlice::byColumn($text, $caretCol, $width - ($caretCol - $off), strict: true);

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
        $pos = \strpos($line, AnsiUtils::CURSOR_MARKER_PREFIX);
        $end = false === $pos ? false : \strpos($line, needle: "\x07", offset: $pos);
        if (false === $pos || false === $end) {
            return self::padRight(self::truncate($line, $width), $width);
        }
        $marker = \substr($line, $pos, $end - $pos + 1);
        $caretCol = AnsiUtils::visibleWidth(\substr($line, offset: 0, length: $pos));
        $bare = \substr($line, offset: 0, length: $pos).\substr($line, $end + 1);

        return self::padRight(self::caretWindow($bare, $caretCol, $width, $marker), $width);
    }
}
