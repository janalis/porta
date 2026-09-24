<?php

declare(strict_types=1);

namespace Porta\Widget\Render;

use Porta\Support\AnsiText;
use Porta\Ui\Glyphs;
use Porta\Ui\Role;
use Porta\Ui\Theme\Styles;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Style\Style;

/**
 * Every horizontal tab strip — the editor's, the bottom dock's and the git
 * view's mode tabs: cells laid left to right, scrolling on overflow with
 * clickable `‹`/`›` indicators, windowed by {@see StripWindow}. A tab may
 * carry a `marker` (the dirty `●`) before its `×`, and a tab that cannot be
 * closed (`closable: false` — a pinned editor tab, a git mode) draws no `×`
 * and reports a close zone of width 0. Returns the styled run, the columns it used, the hit
 * map the caller feeds its click/close/hover zones from, the indicator zones
 * for `‹`/`›` clicks, and the clamped scroll/active state the caller stores.
 *
 * A tab's optional `family` groups it: a drag reorders within one family and
 * refuses to cross, so the boundary between two families is drawn heavier
 * than the divider between siblings. Without it the strip claimed every gap
 * was the same and then silently ignored half the drags.
 *
 * The single tab cell (` label ●× ` + divider) lives here too, as
 * {@see measureCell}/{@see renderCell}. The strips used to assemble their
 * cells two and three times over, and the git tabs measured theirs with
 * `mb_strwidth` and kept a click zone for a tab the pane had cut off. The shading is {@see TabStyle}'s; the windowing is
 * {@see StripWindow}'s. Cell widths are display columns throughout: `width`
 * includes the divider, `closeOffset` is the ×'s column within the cell, and
 * both are consumed as click zones, so a line whose visible width disagrees
 * with them garbles the strip.
 */
final class TabStripRenderer
{
    /** The × cell plus its trailing space — the close zone's clickable width. */
    public const int CLOSE_WIDTH = 2;

    /** A folder-tab strip's height: the lid, then the labels (mirrored at a bottom edge). */
    public const int ROWS = 2;

    /** Below this many terminal rows a strip is one row, the height it gives back to the code. */
    public const int COMPACT_BELOW = 30;

    /** @return positive-int the strip height a terminal of $terminalRows affords */
    public static function rowsFor(int $terminalRows): int
    {
        return $terminalRows >= self::COMPACT_BELOW ? self::ROWS : 1;
    }

    /**
     * The pointer arrives as a widget-relative column, not a per-tab flag: hover
     * never changes a cell's width, so the tab under the pointer is whichever
     * one the window lays across $hoverCol. Resolving it here, each frame, is
     * what keeps the tint from going stale when the strip relabels or a tab
     * closes under a resting pointer — the caller stores only the column.
     *
     * The pointer on a tab's `×` lights the `×` alone, underlined: which of
     * the two a click would do is shown before it is done.
     *
     * At {@see ROWS} rows each tab is a folder tab — its own rails, and a lid
     * whose shape is its state: `╭─╮` for the rest, `┍━┑` for the active tab
     * of a pane without focus, `┏━┓` and heavy rails for the active tab of the
     * pane that has it. At a bottom edge the labels come first and the lid is
     * the tab's bottom. One row is the compact strip, each cell closed by a
     * divider. The caller pads the rows past `used`: the tail is its own (the
     * dock's status and resize grab).
     *
     * @param list<array{label: string, active: bool, family?: string, marker?: string, closable?: bool}> $tabs
     * @param ?int                                                                                         $hoverCol   widget-relative column under the pointer, or null when off the strip
     * @param int                                                                                          $scroll     the caller's stored scroll offset
     * @param ?int                                                                                         $lastActive the caller's stored last active index (null on the first frame)
     * @param ?int                                                                                         $hoverRow   the pointer's row in the strip, or null for the label row — a `×` lights only where a click on it closes
     *
     * @return array{lines: list<string>, labelRow: int, used: int, hitMap: list<array{index: int, start: int, width: int, closeStart: int, closeWidth: int}>, indicatorZones: list<array{row: int, col: int, width: int, step: int}>, scroll: int, lastActive: int}
     */
    public static function render(
        array $tabs,
        int $columns,
        Styles $styles,
        ?int $hoverCol = null,
        int $scroll = 0,
        ?int $lastActive = null,
        int $rows = 1,
        StripEdge $edge = StripEdge::Top,
        bool $paneFocused = false,
        ?int $hoverRow = null,
    ): array {
        $folder = $rows >= self::ROWS;
        $labelRow = $folder && StripEdge::Top === $edge ? 1 : 0;
        // A folder tab's two rails replace the compact cell's one divider.
        $rails = $folder ? 2 : 1;
        /** @var list<array{tab: array{label: string, active: bool, family?: string, marker?: string, closable?: bool}, text: string, width: int, closeOffset: int|null, labelWidth: int}> $parts */
        $parts = [];
        $activeIndex = 0;
        foreach ($tabs as $i => $tab) {
            if ($tab['active']) {
                $activeIndex = $i;
            }
            $cell = self::measureCell($tab['label'], $tab['marker'] ?? '', $tab['closable'] ?? true);
            $cell['width'] += $rails - 1;
            $parts[] = ['tab' => $tab] + $cell;
        }

        $window = StripWindow::of(\array_column($parts, 'width'), $columns, $scroll, $activeIndex, $lastActive);
        $overflow = $window['overflow'];
        $start = $window['start'];
        $zoneRows = $folder ? \range(start: 0, end: self::ROWS - 1) : [0];

        $label = '';
        $lid = '';
        $used = 0;
        $last = $start;
        $hitMap = [];
        $indicatorZones = [];
        if ($overflow) {
            // Reserve col 0 for ‹ (a page-left click) when there is anything to its left.
            $prev = ' ';
            if ($start > 0) {
                foreach ($zoneRows as $row) {
                    $indicatorZones[] = ['row' => $row, 'col' => 0, 'width' => 1, 'step' => -1];
                }
                $prev = $styles->of(Role::Faint)->apply(Glyphs::SCROLL_PREV);
            }
            $label .= $prev;
            $lid .= ' ';
            $used = 1;
        }
        foreach (\array_slice($parts, $start, preserve_keys: true) as $index => $part) {
            if (($used + $part['width']) > ($overflow ? $columns - 1 : $columns)) {
                break;
            }
            $tab = $part['tab'];
            $hovered = null !== $hoverCol && $hoverCol >= $used && $hoverCol < ($used + $part['width']);
            $body = $used + ($folder ? 1 : 0); // past the tab's own left rail
            $closeStart = $body + ($part['closeOffset'] ?? 0);
            $closeWidth = null === $part['closeOffset'] ? 0 : self::CLOSE_WIDTH;
            $onClose = $hovered && (null === $hoverRow || $labelRow === $hoverRow) && $hoverCol >= $closeStart && $hoverCol < ($closeStart + $closeWidth);
            $state = TabState::of($tab['active'], $hovered);
            $shape = TabShape::of($tab['active'], $paneFocused);
            $outline = $styles->of($shape->role());
            $next = $parts[$index + 1]['tab'] ?? null;
            $crosses = null !== $next && ($tab['family'] ?? null) !== ($next['family'] ?? null);
            // A folder tab between its own rails, over its lid; a compact cell
            // closed by its divider, heavier where a drag family ends.
            $label .= $folder
                ? $outline->apply($shape->rail()).self::renderBody($part, $styles, $state, $onClose).$outline->apply($shape->rail())
                : self::renderCell($part, $styles, $state, $crosses ? Glyphs::FAMILY_SEPARATOR : Glyphs::SEPARATOR, $onClose);
            $lid .= $outline->apply($shape->lid($part['width'], $edge));
            $hitMap[] = ['index' => $index, 'start' => $used, 'width' => $part['width'], 'closeStart' => $closeStart, 'closeWidth' => $closeWidth];
            $used += $part['width'];
            $last = $index;
        }
        if ($overflow) {
            // Pad to the last column and put › there (a page-right click) when tabs remain.
            $label = AnsiText::padRight($label, $columns - 1);
            $lid = AnsiText::padRight($lid, $columns);
            $next = ' ';
            if ($last < (\count($parts) - 1)) {
                foreach ($zoneRows as $row) {
                    $indicatorZones[] = ['row' => $row, 'col' => $columns - 1, 'width' => 1, 'step' => 1];
                }
                $next = $styles->of(Role::Faint)->apply(Glyphs::SCROLL_NEXT);
            }
            $label .= $next;
            $used = $columns; // the strip is full: the caller adds no status/grab tail
        }

        $lines = match (true) {
            !$folder => [$label],
            0 === $labelRow => [$label, $lid],
            default => [$lid, $label],
        };

        return [
            'lines' => $lines,
            'labelRow' => $labelRow,
            'used' => $used,
            'hitMap' => $hitMap,
            'indicatorZones' => $indicatorZones,
            'scroll' => $window['scroll'],
            'lastActive' => $window['lastActive'],
        ];
    }

    /**
     * Measure ` $label $marker× ` + its divider — ` $label $marker` when the
     * tab cannot be closed. $marker is the dirty ● (or the session ○ some
     * callers bake into the label); pass '' for a bare tab.
     *
     * @return array{text: string, width: int, closeOffset: int|null, labelWidth: int}
     */
    // @mago-expect lint:no-boolean-flag-parameter -- whether the tab closes IS the cell's shape, not a second responsibility.
    public static function measureCell(string $label, string $marker = '', bool $closable = true): array
    {
        $prefix = ' '.$label.' ';
        $text = $closable ? $prefix.$marker.Glyphs::CLOSE.' ' : $prefix.$marker;

        // visibleWidth, not mb_strwidth: the dirty marker arrives pre-styled
        // (Role::Modified), and its SGR codes must not count as columns.
        return [
            'text' => $text,
            'width' => AnsiUtils::visibleWidth($text) + 1, // + the divider column
            'closeOffset' => $closable ? AnsiUtils::visibleWidth($prefix.$marker) : null,
            'labelWidth' => AnsiUtils::visibleWidth($label),
        ];
    }

    /**
     * The styled cell + its divider, from what {@see measureCell()} built.
     *
     * The × is measured as a bare glyph and only styled here, then spliced in
     * BY COLUMN: styling it earlier would leave measure() counting escape bytes
     * as display columns, and matching it by string would restyle a × that
     * happens to sit in the label itself (a file `a×b.php`). The close offset
     * is the real close glyph's column, so the splice lands on it alone. It is
     * safe because {@see TabCloseGlyph} emits a foreground-only span, so the
     * tab's background runs underneath it uninterrupted.
     *
     * Under the pointer the label underlines, a link's hover — or, with the
     * pointer on the × ($onClose), the × alone does, bright.
     *
     * $divider closes the cell; every one is a single column, so which one a
     * strip picks cannot move its hit map.
     *
     * @param array{text: string, width: int, closeOffset: int|null, labelWidth: int, ...} $cell
     */
    public static function renderCell(array $cell, Styles $styles, TabState $state, string $divider = Glyphs::SEPARATOR, bool $onClose = false): string
    {
        return self::renderBody($cell, $styles, $state, $onClose).$styles->of(Role::Rule)->apply($divider);
    }

    /**
     * The cell's shaded text, no divider — the compact cell's inside, and a
     * folder tab's between its rails.
     *
     * @param array{text: string, width: int, closeOffset: int|null, labelWidth: int, ...} $cell
     */
    // @mago-expect lint:no-boolean-flag-parameter -- where the pointer rests on the cell is its hover state, not a second responsibility.
    private static function renderBody(array $cell, Styles $styles, TabState $state, bool $onClose): string
    {
        $text = $cell['text'];
        $width = AnsiUtils::visibleWidth($text);
        $labelEnd = 1 + $cell['labelWidth'];
        $label = AnsiUtils::sliceByColumn($text, 1, $cell['labelWidth']);
        if (TabState::Hovered === $state && !$onClose) {
            $label = new Style(underline: true)->apply($label);
        }
        $closeOffset = $cell['closeOffset'];
        $body = ' '.$label.AnsiUtils::sliceByColumn($text, $labelEnd, ($closeOffset ?? $width) - $labelEnd);
        if (null !== $closeOffset) {
            $close = match (true) {
                $onClose => $styles->of(Role::Bright)->withUnderline()->apply(Glyphs::CLOSE),
                TabState::Active === $state => TabCloseGlyph::active($styles),
                default => TabCloseGlyph::inactive($styles),
            };
            $body .= $close.AnsiUtils::sliceByColumn($text, $closeOffset + 1, $width - $closeOffset - 1);
        }

        return TabStyle::of($styles, $state)->apply($body);
    }
}
