<?php

declare(strict_types=1);

namespace Porta\Widget\Render;

use Porta\Ui\Sgr;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Style\Style;

/**
 * Applies visual spans (selection, search matches, current line, bracket
 * highlight, diagnostic underlines) onto an ANSI-highlighted line.
 *
 * Spans are expressed in absolute display columns. Decoration only inserts
 * zero-width SGR sequences, so the visible width of the line is unchanged
 * and spans can be applied sequentially.
 */
final class LineDecorator
{
    public const string BRACKET_SGR = "\x1b[1m\x1b[7m";
    public const string UNDERLINE_SGR = Sgr::UNDERLINE;

    /**
     * Apply one SGR span over [$startCol, $endCol) display columns.
     *
     * @param string      $openSgr   SGR sequence starting the effect (e.g. background)
     * @param string      $closeSgr  SGR sequence ending it (e.g. Sgr::DEFAULT_BG or "\x1b[24m")
     * @param string|null $bgReapply When the effect is a background, pass its SGR code
     *                               again so it is re-applied after any reset inside the span
     */
    public static function apply(string $line, int $startCol, int $endCol, string $openSgr, string $closeSgr, ?string $bgReapply = null): string
    {
        $lineWidth = AnsiUtils::visibleWidth($line);
        $startCol = max(0, $startCol);
        $endCol = min($endCol, max($lineWidth, $startCol));
        if ($endCol <= $startCol) {
            return $line;
        }

        $before = 0 === $startCol ? '' : AnsiUtils::sliceByColumn($line, 0, $startCol);
        $middle = AnsiUtils::sliceByColumn($line, $startCol, $endCol - $startCol);
        $after = $endCol >= $lineWidth ? '' : AnsiUtils::sliceByColumn($line, $endCol, $lineWidth - $endCol);

        if (null !== $bgReapply) {
            $middle = AnsiUtils::reapplyBackgroundAfterResets($middle, $bgReapply);
        }

        return $before.$openSgr.$middle.$closeSgr.$after;
    }

    /**
     * Replace the single cell at display column $col with $glyph rendered in
     * $style (foreground only, so any background is preserved). Used to draw
     * indent guides over leading-whitespace cells; the visible width is
     * unchanged. $col must be within the line (0 <= $col < visible width).
     */
    public static function overlayGlyph(string $line, int $col, string $glyph, Style $style): string
    {
        $lineWidth = AnsiUtils::visibleWidth($line);
        $before = 0 === $col ? '' : AnsiUtils::sliceByColumn($line, 0, $col);
        $after = AnsiUtils::sliceByColumn($line, $col + 1, $lineWidth - $col - 1);

        return $before.$style->apply($glyph).$after;
    }

    /**
     * Insert $text at display column $col, pushing existing content right.
     * Existing SGR spans stay attached to their characters, so this is safe to
     * call after other decorations. Used for inlay-hint virtual text; appends
     * when $col is at or past the line's end.
     */
    public static function insertAt(string $line, int $col, string $text): string
    {
        $lineWidth = AnsiUtils::visibleWidth($line);
        if ($col >= $lineWidth) {
            return $line.$text;
        }
        $before = 0 === $col ? '' : AnsiUtils::sliceByColumn($line, 0, $col);
        $after = AnsiUtils::sliceByColumn($line, $col, $lineWidth - $col);

        return $before.$text.$after;
    }

    /**
     * Apply a background over the whole line, padding it to $width first so
     * the background covers trailing space (current-line highlight). The
     * style's own apply() re-establishes the background after any reset
     * inside the line and closes it with the targeted background reset.
     */
    public static function applyFullWidth(string $line, int $width, Style $bg): string
    {
        $lineWidth = AnsiUtils::visibleWidth($line);
        if ($lineWidth < $width) {
            $line .= str_repeat(' ', max(0, $width - $lineWidth));
        }

        return $bg->apply($line);
    }
}
