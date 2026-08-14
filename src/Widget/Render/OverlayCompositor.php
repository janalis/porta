<?php

declare(strict_types=1);

namespace Porta\Widget\Render;

use Porta\Support\AnsiText;
use Porta\Ui\Sgr;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * Composites a pre-styled popup segment onto a rendered editor row (signature
 * hint, list overlay, completion popup). Splits the row at the popup's column,
 * pads the gap, and re-pads the tail so the composited row stays exactly the
 * terminal width.
 */
final class OverlayCompositor
{
    /**
     * @param string $line   the existing rendered row (ANSI)
     * @param int    $col     display column where the popup starts
     * @param int    $width   visible width of $popup (already truncated/padded)
     * @param int    $columns total terminal width
     * @param string $popup   the styled popup segment, exactly $width columns wide
     */
    public static function into(string $line, int $col, int $width, int $columns, string $popup): string
    {
        $head = AnsiText::padRight(AnsiUtils::sliceByColumn($line, 0, $col), $col);
        $tail = AnsiUtils::sliceByColumn($line, $col + $width, $columns - $col - $width);

        // sliceByColumn re-emits an APC caret marker at a tail slice's start
        // when the marker sat before the boundary — for a compose that is
        // always a duplicate of the marker the head (or the popup itself)
        // already carries, and two hardware-cursor markers on one row are
        // ambiguous. A marker genuinely inside the tail region is never
        // re-emitted, so stripping the tail's copies is exact.
        if (str_contains($head.$popup, AnsiUtils::CURSOR_MARKER_PREFIX) && str_contains($tail, AnsiUtils::CURSOR_MARKER_PREFIX)) {
            $tail = (string) preg_replace('/\x1b_pi:c;[^\x07]*\x07/', replacement: '', subject: $tail);
        }

        // Reset after the popup as well as after the head: when the popup is a
        // clipped overlay slice (a menu spilling across a widget boundary), its
        // trailing reset may have been sliced off, and without this its
        // background would bleed across the tail — visible as a menu box painted
        // full-width over a blank pane.
        return AnsiText::padRight($head.Sgr::RESET.$popup.Sgr::RESET.$tail.Sgr::RESET, $columns);
    }
}
