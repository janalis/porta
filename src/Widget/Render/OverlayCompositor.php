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
        // Exactly the columns after the popup: a glyph it half-covers leaves its
        // other half a blank in the row's own style, and nothing after it moves.
        $tail = AnsiUtils::sliceToWidth($line, $col + $width, $columns - $col - $width);

        // The tail slice re-emits, at its start, a caret marker that sat before
        // its boundary — after the popup, a column the caret was never at. The
        // row's own caret belongs where it sits: in the head; at the popup's
        // first column, which the head slice ends on and so drops — the caret
        // right after what was typed, with a shadow cast along the row; or
        // under the popup, which hides it. Only one past the popup is the tail's.
        $caret = AnsiText::caret($line);
        if (null !== $caret && $caret[0] < ($col + $width)) {
            $tail = AnsiText::stripCursorMarkers($tail);
            if ($caret[0] === $col && !\str_contains($popup, AnsiUtils::CURSOR_MARKER_PREFIX)) {
                $head .= $caret[1];
            }
        }
        // A popup carrying a caret of its own: the row's is a second one, and
        // two hardware-cursor markers on one row are ambiguous.
        if (\str_contains($popup, AnsiUtils::CURSOR_MARKER_PREFIX)) {
            $tail = AnsiText::stripCursorMarkers($tail);
        }

        // Reset after the popup as well as after the head: when the popup is a
        // clipped overlay slice (a menu spilling across a widget boundary), its
        // trailing reset may have been sliced off, and without this its
        // background would bleed across the tail — visible as a menu box painted
        // full-width over a blank pane.
        return AnsiText::padRight($head.Sgr::RESET.$popup.Sgr::RESET.$tail.Sgr::RESET, $columns);
    }
}
