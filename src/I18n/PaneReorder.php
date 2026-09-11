<?php

declare(strict_types=1);

namespace Porta\I18n;

use Porta\Container\AsIdeService;
use Porta\Ui\Sgr;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * Applies the RTL bidi reorder to a composited screen row one pane at a time,
 * given the pane column runs computed by {@see \Porta\Ui\PaneSegmenter}. Each
 * run is reordered (or, for a code/LTR pane, kept verbatim) strictly within its
 * own column span, so the reorder never crosses a pane boundary — the fix for
 * the whole-row reorder that tore multi-pane views apart.
 *
 * A run is `array{start: int, width: int, ltr: bool}`; the runs tile the row's
 * columns left to right.
 */
#[AsIdeService]
final class PaneReorder
{
    /** @param list<array{start: int, width: int, ltr: bool}> $runs */
    public function reorderRow(string $line, array $runs): string
    {
        $out = '';
        foreach ($runs as $run) {
            $slice = AnsiUtils::sliceByColumn($line, $run['start'], $run['width'], strict: true);
            // Reset after every run. MEASURED (July 2026): with the current
            // slicer this changes no rendered output — `Bidi::reorder()` already
            // brackets its result with resets, and `sliceByColumn(strict: true)`
            // opens every slice by re-emitting the source's SGR state at that
            // column, reset included. Deleting it survived every case that could
            // be constructed (verbatim→reordered, verbatim→verbatim, and a
            // verbatim run ending mid-SGR — the case this comment used to claim
            // it for). It stays because closing the row's SGR state is THIS
            // class's contract and not the slicer's: two vendored behaviours
            // currently make it redundant, and a stray background bleeding
            // across panes is the bug that made this class exist.
            $slice = self::withoutABorrowedCaret($line, $slice, $run['start']);
            $out .= ($run['ltr'] ? $slice : Bidi::reorder($slice, BaseDirection::Rtl)).Sgr::RESET;
        }

        return $out;
    }

    /**
     * Drop a caret marker this run only inherited from an earlier one.
     *
     * `sliceByColumn(strict: true)` opens each slice by re-emitting the escape
     * state active at that column, and it counts the zero-width APC caret
     * marker as state — so every run to the RIGHT of the caret opened with a
     * copy of a cursor that belongs to another pane. The ScreenWriter strips
     * only the first, so the rest reached the terminal as raw escapes, once per
     * pane per frame; and the surviving copy loses its `\x07` terminator, which
     * makes it swallow the text after it.
     *
     * Only this path re-slices an already-composed row, which is why an LTR
     * locale never sees it. {@see \Porta\Overlay\Region\SpanSlicer} carries
     * the same guard against the same vendored behaviour.
     */
    private static function withoutABorrowedCaret(string $line, string $slice, int $start): string
    {
        $markerAt = strpos($line, AnsiUtils::CURSOR_MARKER_PREFIX);
        // No caret on this row, or it sits at or after this run's first column,
        // which makes it this run's own. The second test covers the first run
        // for free — nothing can sit before column zero.
        if (false === $markerAt || AnsiUtils::visibleWidth(substr($line, offset: 0, length: $markerAt)) >= $start) {
            return $slice;
        }

        return (string) preg_replace('/\x1b_pi:c;[^\x07]*\x07/', replacement: '', subject: $slice);
    }

    /**
     * Invert the reorder for a visual column, so a mouse click maps to the
     * logical column the widgets rendered. Spans are identical in visual and
     * logical space, so the column selects the same run either way.
     *
     * @param list<array{start: int, width: int, ltr: bool}> $runs
     */
    public function logicalColumn(string $line, int $visualCol, array $runs): int
    {
        foreach ($runs as $run) {
            if ($visualCol >= ($run['start'] + $run['width'])) {
                continue;
            }

            if ($run['ltr']) {
                return $visualCol;
            }
            $slice = AnsiUtils::sliceByColumn($line, $run['start'], $run['width'], strict: true);

            return $run['start'] + Bidi::visualToLogicalColumn($slice, $visualCol - $run['start'], BaseDirection::Rtl);
        }

        return $visualCol;
    }
}
