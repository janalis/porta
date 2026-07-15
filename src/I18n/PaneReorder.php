<?php

declare(strict_types=1);

namespace Porta\I18n;

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
final class PaneReorder
{
    /** @param list<array{start: int, width: int, ltr: bool}> $runs */
    public function reorderRow(string $line, array $runs): string
    {
        $out = '';
        foreach ($runs as $run) {
            $slice = AnsiUtils::sliceByColumn($line, $run['start'], $run['width'], strict: true);
            // Reset after every run: redundant after a (self-balanced) reordered
            // run, load-bearing after a verbatim run that may end mid-SGR — that
            // stray background is exactly what used to bleed across panes.
            $out .= ($run['ltr'] ? $slice : Bidi::reorder($slice, true))."\x1b[0m";
        }

        return $out;
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

            return $run['start'] + Bidi::visualToLogicalColumn($slice, $visualCol - $run['start'], true);
        }

        return $visualCol;
    }
}
