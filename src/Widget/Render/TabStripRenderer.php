<?php

declare(strict_types=1);

namespace Porta\Widget\Render;

use Porta\Support\AnsiText;
use Porta\Ui\Glyphs;
use Porta\Ui\Role;
use Porta\Ui\Theme\Styles;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * The bottom dock's one-row tab strip: cells laid left to right,
 * scrolling on overflow with clickable `‹`/`›` indicators, exactly like the
 * editor tab bar — the two share {@see StripWindow} so a tab visible on one is
 * visible on the other. Returns the styled run, the columns it used, the hit
 * map the caller feeds its click/close/hover zones from, the indicator zones
 * for `‹`/`›` clicks, and the clamped scroll/active state the caller stores.
 *
 * A tab's optional `family` groups it: a drag reorders within one family and
 * refuses to cross, so the boundary between two families is drawn heavier
 * than the divider between siblings. Without it the strip claimed every gap
 * was the same and then silently ignored half the drags.
 *
 * The single tab cell (` label ●× ` + divider) lives here too, as
 * {@see measureCell}/{@see renderCell}: the dock strip and the editor's
 * horizontal bar ({@see \Porta\Widget\TabBarWidget}) window the strip
 * differently on purpose — the dock drops leading tabs, the editor bar
 * carries a sticky scroll offset and reserves its ‹/› columns — but their
 * *cells* were byte-identical, measured and assembled twice; the cell is what
 * must not drift. The shading is {@see TabStyle}'s; the windowing is
 * {@see StripWindow}'s. Cell widths are display columns throughout: `width`
 * includes the divider, `closeOffset` is the ×'s column within the cell, and
 * both are consumed as click zones, so a line whose visible width disagrees
 * with them garbles the strip.
 */
final class TabStripRenderer
{
    /** The × cell plus its trailing space — the close zone's clickable width. */
    public const int CLOSE_WIDTH = 2;

    /**
     * The pointer arrives as a widget-relative column, not a per-tab flag: hover
     * never changes a cell's width, so the tab under the pointer is whichever
     * one the window lays across $hoverCol. Resolving it here, each frame, is
     * what keeps the tint from going stale when the strip relabels or a tab
     * closes under a resting pointer — the caller stores only the column.
     *
     * @param list<array{label: string, active: bool, family?: string}> $tabs
     * @param ?int                                                       $hoverCol   widget-relative column under the pointer, or null when off the strip
     * @param int                                                        $scroll     the caller's stored scroll offset
     * @param ?int                                                       $lastActive the caller's stored last active index (null on the first frame)
     *
     * @return array{line: string, used: int, hitMap: list<array{index: int, start: int, width: int, closeStart: int, closeWidth: int}>, indicatorZones: list<array{row: int, col: int, width: int, step: int}>, scroll: int, lastActive: int}
     */
    public static function render(array $tabs, int $columns, Styles $styles, ?int $hoverCol = null, int $scroll = 0, ?int $lastActive = null): array
    {
        /** @var list<array{tab: array{label: string, active: bool, family?: string}, text: string, width: int, closeOffset: int}> $parts */
        $parts = [];
        $activeIndex = 0;
        foreach ($tabs as $i => $tab) {
            if ($tab['active']) {
                $activeIndex = $i;
            }
            $parts[] = ['tab' => $tab] + self::measureCell($tab['label']);
        }

        $window = StripWindow::of(\array_column($parts, 'width'), $columns, $scroll, $activeIndex, $lastActive);
        $overflow = $window['overflow'];
        $start = $window['start'];

        $line = '';
        $used = 0;
        $last = $start;
        $hitMap = [];
        $indicatorZones = [];
        if ($overflow) {
            // Reserve col 0 for ‹ (a page-left click) when there is anything to its left.
            $prev = ' ';
            if ($start > 0) {
                $indicatorZones[] = ['row' => 0, 'col' => 0, 'width' => 1, 'step' => -1];
                $prev = $styles->of(Role::Faint)->apply(Glyphs::SCROLL_PREV);
            }
            $line .= $prev;
            $used = 1;
        }
        foreach (\array_slice($parts, $start, preserve_keys: true) as $index => $part) {
            if (($used + $part['width']) > ($overflow ? $columns - 1 : $columns)) {
                break;
            }
            $tab = $part['tab'];
            $next = $parts[$index + 1]['tab'] ?? null;
            $crosses = null !== $next && ($tab['family'] ?? null) !== ($next['family'] ?? null);
            $hovered = null !== $hoverCol && $hoverCol >= $used && $hoverCol < ($used + $part['width']);
            $line .= self::renderCell($part['text'], $part['closeOffset'], $styles, TabState::of($tab['active'], $hovered), $crosses ? Glyphs::FAMILY_SEPARATOR : Glyphs::SEPARATOR);
            $hitMap[] = ['index' => $index, 'start' => $used, 'width' => $part['width'], 'closeStart' => $used + $part['closeOffset'], 'closeWidth' => self::CLOSE_WIDTH];
            $used += $part['width'];
            $last = $index;
        }
        if ($overflow) {
            // Pad to the last column and put › there (a page-right click) when tabs remain.
            $line = AnsiText::padRight($line, $columns - 1);
            $next = ' ';
            if ($last < (\count($parts) - 1)) {
                $indicatorZones[] = ['row' => 0, 'col' => $columns - 1, 'width' => 1, 'step' => 1];
                $next = $styles->of(Role::Faint)->apply(Glyphs::SCROLL_NEXT);
            }
            $line .= $next;
            $used = $columns; // the strip is full: the caller adds no status/grab tail
        }

        return ['line' => $line, 'used' => $used, 'hitMap' => $hitMap, 'indicatorZones' => $indicatorZones, 'scroll' => $window['scroll'], 'lastActive' => $window['lastActive']];
    }

    /**
     * Measure ` $label $marker× ` + its divider. $marker is the dirty ● (or the
     * session ○ some callers bake into the label); pass '' for a bare tab.
     *
     * @return array{text: string, width: int, closeOffset: int}
     */
    public static function measureCell(string $label, string $marker = ''): array
    {
        $prefix = ' '.$label.' ';
        $text = $prefix.$marker.Glyphs::CLOSE.' ';

        // visibleWidth, not mb_strwidth: the dirty marker arrives pre-styled
        // (Role::Modified), and its SGR codes must not count as columns.
        return [
            'text' => $text,
            'width' => AnsiUtils::visibleWidth($text) + 1, // + the divider column
            'closeOffset' => AnsiUtils::visibleWidth($prefix.$marker),
        ];
    }

    /**
     * The styled cell + its divider, from the $text and $closeOffset that
     * {@see measureCell()} built.
     *
     * The × is measured as a bare glyph and only styled here, then spliced in
     * BY COLUMN: styling it earlier would leave measure() counting escape bytes
     * as display columns, and matching it by string would restyle a × that
     * happens to sit in the label itself (a file `a×b.php`). $closeOffset is the
     * real close glyph's column, so the splice lands on it alone. It is safe
     * because {@see TabCloseGlyph} emits a foreground-only span (closed with
     * `\e[39m`, never a full reset), so the tab's background runs underneath it
     * uninterrupted.
     *
     * $divider closes the cell; every one is a single column, so which one a
     * strip picks cannot move its hit map.
     */
    public static function renderCell(string $text, int $closeOffset, Styles $styles, TabState $state, string $divider = Glyphs::SEPARATOR): string
    {
        $close = match ($state) {
            TabState::Active => TabCloseGlyph::active($styles),
            TabState::Hovered, TabState::Plain => TabCloseGlyph::inactive($styles),
        };

        $before = AnsiUtils::sliceByColumn($text, 0, $closeOffset);
        $after = AnsiUtils::sliceByColumn($text, $closeOffset + 1, AnsiUtils::visibleWidth($text) - $closeOffset - 1);

        return TabStyle::of($styles, $state)->apply($before.$close.$after).$styles->of(Role::Placeholder)->apply($divider);
    }
}
