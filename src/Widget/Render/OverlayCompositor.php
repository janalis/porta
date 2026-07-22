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
            $tail = (string) preg_replace('/\x1b_pi:c;[^\x07]*\x07/', '', $tail);
        }

        // Reset after the popup as well as after the head: when the popup is a
        // clipped overlay slice (a menu spilling across a widget boundary), its
        // trailing reset may have been sliced off, and without this its
        // background would bleed across the tail — visible as a menu box painted
        // full-width over a blank pane.
        return AnsiText::padRight($head.Sgr::RESET.$popup.Sgr::RESET.$tail.Sgr::RESET, $columns);
    }

    /**
     * Centre a fully-built box a third of the way down the pane and blit each of
     * its rows into the editor lines at columns [$col, $col + $width); returns the
     * composited lines and the box's top screen row. The centered overlays — the
     * editor's list/search popups and the find-in-files modal — share this
     * placement and each layer their own geometry on the returned start row.
     *
     * @param list<string> $lines
     * @param list<string> $box
     *
     * @return array{list<string>, int} the composited lines, and the box's top row
     */
    public static function placeCentered(array $lines, array $box, int $col, int $width, int $columns, int $rows): array
    {
        $startRow = max(0, intdiv($rows - \count($box), num2: 3));

        return [self::placeAt($lines, $box, $startRow, $col, $width, $columns), $startRow];
    }

    /**
     * Blit a fully-built box at an explicit position — the draggable modals'
     * variant of {@see placeCentered}, which delegates here. Rows beyond the
     * pane are skipped, same bounds guard.
     *
     * @param list<string> $lines
     * @param list<string> $box
     *
     * @return list<string>
     */
    public static function placeAt(array $lines, array $box, int $startRow, int $col, int $width, int $columns): array
    {
        foreach ($box as $i => $boxLine) {
            $row = $startRow + $i;
            if ($row >= \count($lines)) {
                break;
            }
            $lines[$row] = self::into($lines[$row], $col, $width, $columns, $boxLine);
        }

        return $lines;
    }
}
