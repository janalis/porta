<?php

declare(strict_types=1);

namespace Porta\I18n;

use Porta\Container\AsIdeService;
use Porta\Support\AnsiText;
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
    /**
     * @param list<array{start: int, width: int, ltr: bool}> $runs    the panes, tiling the row
     * @param list<array{start: int, width: int, ltr: bool}> $regions floating surfaces, painted over the panes
     */
    public function reorderRow(string $line, array $runs, array $regions = []): string
    {
        // Blank what floats before reordering the panes, then stamp it back.
        // Without the blanking the pane pass carries the surface's own glyphs
        // along with it and paints a second copy of the box somewhere else in
        // the row; without the full-span reorder the pane slides sideways.
        $row = $this->reorderPanes(self::blankRegions($line, $regions), $runs);
        foreach ($regions as $region) {
            $row = $this->paintRegion($row, $line, $region);
        }

        return $row;
    }

    /**
     * The row with every floating surface's columns replaced by spaces.
     *
     * The pane underneath then reorders over its FULL span against a hole,
     * which puts its visible text at exactly the columns it would occupy if
     * nothing floated at all — and the surface is stamped over the hole after.
     *
     * @param list<array{start: int, width: int, ltr: bool}> $regions
     */
    private static function blankRegions(string $line, array $regions): string
    {
        foreach ($regions as $region) {
            // No clamping here: regionRunsForRow() never yields a zero width,
            // and sliceByColumn() tolerates a tail that runs past the row --
            // guards for either survived every mutation, which is what dead
            // defensive code looks like.
            \assert($region['width'] > 0, description: 'regionRunsForRow() never yields an empty surface');
            $end = $region['start'] + $region['width'];
            $width = AnsiUtils::visibleWidth($line);
            $line =
                AnsiUtils::sliceByColumn($line, startCol: 0, length: $region['start'], strict: true)
                .Sgr::RESET
                .\str_repeat(' ', $region['width'])
                .AnsiUtils::sliceByColumn($line, $end, $width - $end, strict: true)
                .Sgr::RESET;
        }

        return $line;
    }

    /**
     * Freeze an already-laid-out row so a terminal that runs the bidi
     * algorithm itself cannot lay it out again.
     *
     * Most terminals draw the cells they are given, and Porta's reorder is the
     * whole story. cmux is the exception found in the field: it shapes rows
     * through CoreText, which applies UAX#9 unconditionally, so a row Porta has
     * already ordered gets ordered a SECOND time.
     *
     * Two weaker remedies were tried against the real thing and are recorded
     * because both looked right on paper:
     *
     * - Handing the layout back — emitting the LOGICAL row and letting the
     *   terminal mirror it. The glyphs came out readable and the LAYOUT did
     *   not: with a Latin label the row read left-to-right and each Hebrew run
     *   was reversed IN PLACE, leaving labels left-aligned.
     * - Wrapping each pane in a right-to-left ISOLATE to settle its direction.
     *   Measured on the reported screen, the menu bar came back with every item
     *   internally correct and the items never reordered — the runs reversed in
     *   place and stayed put, so the isolate's embedding level was not honoured.
     *
     * What does hold is the strongest statement in the algorithm: a left-to-right
     * OVERRIDE makes every character inside strong L, so nothing reverses and
     * nothing reorders. Porta keeps the layout, and the terminal is told to draw
     * these cells in this order. Verified by running a UAX#9 reorder over the
     * rows Porta actually emits: all forty come back identical.
     *
     * The override takes no column, so it goes in front of the row and the
     * width never changes — every row is frozen, whatever it starts with.
     */
    public function freezeRow(string $line): string
    {
        // ONE override at column zero, and no pop: an override runs to the end
        // of the paragraph, and a terminal treats a row as a paragraph. So the
        // whole composed row is frozen, whatever is on it.
        //
        // The earlier version spent a pair per PANE, and that was wrong twice
        // over once floating surfaces were in play. It ran after the dropdown
        // had been stamped onto the row but spent cells by PANE geometry, so it
        // wrote override characters into the dropdown's own cells and shifted
        // its border a column; and on the rows where the dropdown reached the
        // last column there was no trailing padding, so those rows were skipped
        // and got no freeze at all. Measured on the reported screen: three
        // override characters at columns 176, 199 and 200 on alternate rows,
        // and none on the rest. Until symfony/tui fa0b189 measured format
        // characters as no column, the override still had to replace a
        // padding cell, and a row with none to give went unfrozen.
        // Drop the widgets' own direction marks on the way. They steer PORTA's
        // reorder, which has already run, and a terminal running UAX#9 would
        // steer with them a second time.
        return Bidi::LTR_OVERRIDE.\strtr($line, [Bidi::LTR_MARK => '', Bidi::RTL_MARK => '', "\u{061C}" => '']);
    }

    /**
     * Put a floating surface back over the reordered row, at its own columns.
     *
     * The panes are reordered over their FULL spans first, exactly as if
     * nothing floated above them, and the surface is then stamped back on top.
     * That is what makes a toast behave like a toast: it hides the cells under
     * it and moves none of them. Letting the region own its columns during
     * segmentation instead truncated the pane beneath, and reordering the
     * shortened span slid the pane's whole row sideways — the settings screen
     * in Hebrew, reported five times.
     *
     * The surface's own content is taken from the ORIGINAL row (the panes' pass
     * has shuffled it) and reordered within its own box unless it is verbatim.
     *
     * @param array{start: int, width: int, ltr: bool} $region
     */
    private function paintRegion(string $row, string $line, array $region): string
    {
        $width = AnsiUtils::visibleWidth($row);
        // A floating box is laid out in the same LOGICAL space as the widgets,
        // so a mirrored frame has to mirror the box too. Leaving it at its
        // logical columns is what puts an app-menu dropdown at the opposite end
        // of the screen from the title it belongs to: `File` is logically first,
        // so its dropdown sits at column 0 while the mirrored menu bar draws
        // `קובץ` at the far right. Mirroring across the row is also what makes
        // the stamp land exactly in the hole {@see blankRegions()} left behind.
        // Clamp to what the row actually holds: a surface hanging off the end
        // has fewer cells than it claims, and stamping its claimed width would
        // make the row SHORTER than its pane -- caught by the clamping case.
        $keep = \max(0, \min($region['width'], $width - $region['start']));
        if (0 === $keep) {
            return $row;
        }
        $start = \max(0, $width - $region['start'] - $keep);
        $end = $start + $keep;
        $before = AnsiUtils::sliceByColumn($row, startCol: 0, length: $start, strict: true);
        $after = AnsiUtils::sliceByColumn($row, $end, $width - $end, strict: true);
        $slice = AnsiUtils::sliceByColumn($line, $region['start'], $keep, strict: true);
        $slice = self::withoutABorrowedCaret($line, $slice, $region['start']);
        $middle = $region['ltr'] ? $slice : Bidi::reorder($slice, BaseDirection::Rtl);

        return $before.Sgr::RESET.$middle.Sgr::RESET.$after.Sgr::RESET;
    }

    /** @param list<array{start: int, width: int, ltr: bool}> $runs */
    private function reorderPanes(string $line, array $runs): string
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
        $markerAt = \strpos($line, AnsiUtils::CURSOR_MARKER_PREFIX);
        // No caret on this row, or it sits at or after this run's first column,
        // which makes it this run's own. The second test covers the first run
        // for free — nothing can sit before column zero.
        if (false === $markerAt || AnsiUtils::visibleWidth(\substr($line, offset: 0, length: $markerAt)) >= $start) {
            return $slice;
        }

        return AnsiText::stripCursorMarkers($slice);
    }

    /**
     * Invert the reorder for a visual column, so a mouse click maps to the
     * logical column the widgets rendered. Spans are identical in visual and
     * logical space, so the column selects the same run either way.
     *
     * @param list<array{start: int, width: int, ltr: bool}> $runs
     * @param list<array{start: int, width: int, ltr: bool}> $regions
     */
    public function logicalColumn(string $line, int $visualCol, array $runs, array $regions = []): int
    {
        // The surfaces first, and in reverse: they are painted over the panes
        // in order, so the LAST one drawn owns the cell a click lands on.
        // Without this a click inside a mirrored dropdown was mapped through
        // the pane underneath it and hit nothing — the box moved with the
        // frame and the mouse did not follow it.
        $width = AnsiUtils::visibleWidth($line);
        foreach (\array_reverse($regions) as $region) {
            $keep = \max(0, \min($region['width'], $width - $region['start']));
            if (0 === $keep) {
                continue;
            }
            $start = \max(0, $width - $region['start'] - $keep);
            if ($visualCol < $start || $visualCol >= ($start + $keep)) {
                continue;
            }
            $offset = $visualCol - $start;
            if ($region['ltr']) {
                return $region['start'] + $offset;
            }
            $slice = AnsiUtils::sliceByColumn($line, $region['start'], $keep, strict: true);

            return $region['start'] + Bidi::visualToLogicalColumn($slice, $offset, BaseDirection::Rtl);
        }

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
