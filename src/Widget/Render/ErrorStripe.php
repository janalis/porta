<?php

declare(strict_types=1);

namespace Porta\Widget\Render;

use Porta\Editor\DiagnosticStyle;
use Porta\Support\IntMath;
use Porta\Ui\Glyphs;
use Porta\Ui\Role;
use Porta\Ui\Sgr;
use Porta\Ui\Theme\Styles;

/**
 * The editor's right-margin marker stripe: one self-contained 1-column cell
 * per screen row, mapping the WHOLE file onto the pane's height so a glance
 * down the margin says where the interesting lines are — diagnostics,
 * breakpoints, version-control changes and search hits — over a faint viewport
 * thumb (only when the file is taller than the viewport). Pure and static — it
 * takes what it paints plus the palette, and reads nothing else.
 *
 * There is deliberately no caret mark: the thumb already answers "where am I in
 * this file", and a glyph that painted on every single frame would be noise.
 *
 * The column is one cell wide, so several lines share a row and several kinds
 * compete for it. They resolve by strict PRECEDENCE, worst-first: a diagnostic
 * outranks a breakpoint, which outranks a change mark, which outranks a search
 * hit. Every kind draws a distinct glyph, so the stripe still reads under
 * `--no-ansi` where the roles collapse to nothing.
 */
final class ErrorStripe
{
    /**
     * @return list<string> exactly $rows cells
     */
    public static function cells(StripeMarks $marks, int $rows, int $lineCount, int $vScroll, int $lastVisible, Styles $styles): array
    {
        $width = \max(0, $rows);

        // Worst severity landing on each stripe row (0 = none).
        $severity = \array_fill(0, $width, value: 0);
        foreach ($marks->severityByLine as $line => $worst) {
            $r = self::rowOf($line, $rows, $lineCount);
            $severity[$r] = 0 === $severity[$r] ? $worst : \min($severity[$r], $worst);
        }

        $breakpoints = \array_fill(0, $width, value: false);
        foreach ($marks->breakpointLines as $line) {
            $breakpoints[self::rowOf($line, $rows, $lineCount)] = true;
        }

        // Several changed lines can share a row and they need not agree on the
        // kind; the topmost one in the slice wins, so the mark does not depend
        // on the order the producer happened to push its marks in.
        $changes = \array_fill(0, $width, value: null);
        $changeLine = \array_fill(0, $width, value: \PHP_INT_MAX);
        foreach ($marks->vcsByLine as $line => $kind) {
            $r = self::rowOf($line, $rows, $lineCount);
            if ($line < $changeLine[$r]) {
                $changeLine[$r] = $line;
                $changes[$r] = $kind;
            }
        }

        $hits = \array_fill(0, $width, value: false);
        foreach ($marks->searchLines as $line) {
            $hits[self::rowOf($line, $rows, $lineCount)] = true;
        }

        // Viewport thumb: the stripe rows covering the visible doc-line range,
        // shown only when the file actually scrolls.
        $showThumb = $lineCount > $rows;
        $thumbTop = self::rowOf($vScroll, $rows, $lineCount);
        $thumbBottom = self::rowOf($lastVisible, $rows, $lineCount);

        $cells = [];
        for ($r = 0; $r < $rows; ++$r) {
            $change = $changes[$r];
            $changeMark = null === $change ? null : [$change->glyph(), $change->role()];

            $mark = match (true) {
                0 !== $severity[$r] => [DiagnosticStyle::mark($severity[$r]), DiagnosticStyle::role($severity[$r])],
                $breakpoints[$r] => [Glyphs::BREAKPOINT, Role::Breakpoint],
                null !== $changeMark => $changeMark,
                $hits[$r] => [Glyphs::STRIPE_SEARCH, Role::SearchBg],
                default => null,
            };

            // The leading reset shields the cell from whatever state the row
            // content left open; the styles then close themselves.
            $thumb = $showThumb && $r >= $thumbTop && $r <= $thumbBottom;
            $cells[] = match (true) {
                null !== $mark && $thumb => Sgr::RESET.$styles->merge(Role::RowHighlightBg, $mark[1])->apply($mark[0]),
                null !== $mark => Sgr::RESET.$styles->of($mark[1])->apply($mark[0]),
                $thumb => Sgr::RESET.$styles->of(Role::RowHighlightBg)->apply(' '),
                default => Sgr::RESET.' ',
            };
        }

        return $cells;
    }

    /** The stripe row a document line lands on, proportionally over the whole file. */
    private static function rowOf(int $line, int $rows, int $lineCount): int
    {
        return $lineCount <= 1 ? 0 : IntMath::clamp(\intdiv($line * $rows, $lineCount), 0, $rows - 1);
    }
}
