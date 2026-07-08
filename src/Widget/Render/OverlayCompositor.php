<?php

declare(strict_types=1);

namespace Porta\Widget\Render;

use Porta\Support\AnsiText;
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
        $tailWidth = AnsiUtils::visibleWidth($tail);

        // Reset after the popup as well as after the head: when the popup is a
        // clipped overlay slice (a menu spilling across a widget boundary), its
        // trailing reset may have been sliced off, and without this its
        // background would bleed across the tail — visible as a menu box painted
        // full-width over a blank pane.
        return $head."\x1b[0m".$popup."\x1b[0m".$tail."\x1b[0m".str_repeat(' ', max(0, $columns - $col - $width - $tailWidth));
    }
}
