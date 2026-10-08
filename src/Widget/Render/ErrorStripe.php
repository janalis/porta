<?php

declare(strict_types=1);

namespace Porta\Widget\Render;

use Porta\Editor\ChangeMarkKind;
use Porta\Editor\DiagnosticSeverity;
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
 * compete for it. They resolve by strict PRECEDENCE: the debugger's stopped
 * line, then a diagnostic, a breakpoint, the current search hit, a change
 * mark, a bookmark, any other search hit. Every kind draws a distinct glyph,
 * and the thumb has its own on the rows no mark claims, so the stripe still
 * reads under `--no-ansi` where the roles collapse to nothing.
 */
final class ErrorStripe
{
    /**
     * @return list<string> exactly $rows cells
     */
    public static function cells(StripeMarks $marks, int $rows, int $lineCount, int $vScroll, int $lastVisible, Styles $styles, ?int $hoveredRow = null): array
    {
        $winners = self::winners($marks, $rows, $lineCount);

        // Viewport thumb: the stripe rows covering the visible doc-line range,
        // shown only when the file actually scrolls.
        $showThumb = $lineCount > $rows;
        $thumbTop = self::rowOf($vScroll, $rows, $lineCount);
        $thumbBottom = self::rowOf($lastVisible, $rows, $lineCount);

        $cells = [];
        for ($r = 0; $r < $rows; ++$r) {
            $mark = $winners[$r] ?? null;
            // The leading reset shields the cell from whatever state the row
            // content left open; the styles then close themselves. The mark
            // under the pointer underlines, as every click target does.
            $thumb = $showThumb && $r >= $thumbTop && $r <= $thumbBottom;
            $role = null === $mark ? null : $mark['kind']->role($mark['severity'], $mark['change']);
            $fill = null === $mark ? null : self::fill($mark, $styles);
            $style = match (true) {
                null !== $role && null !== $fill => $styles->merge($fill, $role),
                null !== $role && $thumb => $styles->merge(Role::RowHighlightBg, $role),
                null !== $role => $styles->of($role),
                $thumb => $styles->merge(Role::RowHighlightBg, Role::Faint),
                default => null,
            };
            $glyph = match (true) {
                null !== $mark => $mark['kind']->glyph($mark['severity'], $mark['change']),
                $thumb => Glyphs::STRIPE_THUMB,
                default => ' ',
            };
            if (null !== $mark && $r === $hoveredRow) {
                $style = $style?->withUnderline();
            }
            $cells[] = Sgr::RESET.(null === $style ? $glyph : $style->apply($glyph));
        }

        return $cells;
    }

    /**
     * A problem's cell is filled with its severity's colour, as its line's
     * gutter is — the glyph drawn in that colour too, so the cell reads as
     * one block whatever its density. Not for a hint (the gutter marks none),
     * and not without colour, where the glyph's density is what says the
     * severity and a reversed full block would read as empty.
     *
     * @param array{kind: StripeMark, line: int, severity: ?DiagnosticSeverity, change: ?ChangeMarkKind} $mark
     */
    private static function fill(array $mark, Styles $styles): ?Role
    {
        if (StripeMark::Diagnostic !== $mark['kind'] || !$styles->hasColor()) {
            return null;
        }

        return match ($mark['severity']) {
            DiagnosticSeverity::Error, null => Role::GutterError,
            DiagnosticSeverity::Warning => Role::GutterWarning,
            DiagnosticSeverity::Information => Role::GutterNotice,
            DiagnosticSeverity::Hint => null,
        };
    }

    /**
     * The mark a stripe row shows — its kind and the first line of that kind
     * in the row's slice of the file — or null for a row with none. What the
     * pointer over the stripe is told, by the same precedence the cell drew.
     *
     * @return array{kind: StripeMark, line: int, severity: ?DiagnosticSeverity, change: ?ChangeMarkKind}|null
     */
    public static function markAt(StripeMarks $marks, int $row, int $rows, int $lineCount): ?array
    {
        return self::winners($marks, $rows, $lineCount)[$row] ?? null;
    }

    /**
     * Each row's winning mark. The column is one cell wide, so several lines
     * share a row and several kinds compete for it; the precedence is
     * {@see StripeMark}'s case order, and within a kind the topmost line in the
     * slice is the one kept, so the mark does not depend on the order the
     * producer happened to push its marks in. A diagnostic keeps the WORST
     * severity landing on its row.
     *
     * @return array<int, array{kind: StripeMark, line: int, severity: ?DiagnosticSeverity, change: ?ChangeMarkKind}>
     */
    private static function winners(StripeMarks $marks, int $rows, int $lineCount): array
    {
        /** @var array<int, array<string, array{kind: StripeMark, line: int, severity: ?DiagnosticSeverity, change: ?ChangeMarkKind}>> $byRow */
        $byRow = [];
        $offer = static function (StripeMark $kind, int $line, ?DiagnosticSeverity $severity = null, ?ChangeMarkKind $change = null) use (&$byRow, $rows, $lineCount): void {
            $r = self::rowOf($line, $rows, $lineCount);
            $held = $byRow[$r][$kind->name] ?? null;
            $better = null === $held || (null !== $severity && null !== $held['severity'] && $severity->outranks($held['severity'])) || ($severity === $held['severity'] && $line < $held['line']);
            if ($better) {
                $byRow[$r][$kind->name] = ['kind' => $kind, 'line' => $line, 'severity' => $severity, 'change' => $change];
            }
        };
        if (null !== $marks->debugLine) {
            $offer(StripeMark::Paused, $marks->debugLine);
        }
        foreach ($marks->severityByLine as $line => $worst) {
            $offer(StripeMark::Diagnostic, $line, $worst);
        }
        foreach ($marks->breakpointLines as $line) {
            $offer(StripeMark::Breakpoint, $line);
        }
        if (null !== $marks->currentSearchLine) {
            $offer(StripeMark::CurrentHit, $marks->currentSearchLine);
        }
        foreach ($marks->vcsByLine as $line => $kind) {
            $offer(StripeMark::Change, $line, change: $kind);
        }
        foreach ($marks->bookmarkLines as $line) {
            $offer(StripeMark::Bookmark, $line);
        }
        foreach ($marks->commentLines as $line) {
            $offer(StripeMark::Comment, $line);
        }
        foreach ($marks->searchLines as $line) {
            $offer(StripeMark::Hit, $line);
        }

        $winners = [];
        foreach ($byRow as $r => $kinds) {
            foreach (StripeMark::cases() as $kind) {
                if (!\array_key_exists($kind->name, $kinds)) {
                    continue;
                }
                $winners[$r] = $kinds[$kind->name];

                break;
            }
        }

        return $winners;
    }

    /** The stripe row a document line lands on, proportionally over the whole file. */
    private static function rowOf(int $line, int $rows, int $lineCount): int
    {
        return $lineCount <= 1 ? 0 : IntMath::clamp(\intdiv($line * $rows, $lineCount), 0, $rows - 1);
    }
}
