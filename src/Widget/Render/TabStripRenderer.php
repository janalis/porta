<?php

declare(strict_types=1);

namespace Porta\Widget\Render;

use Porta\Ui\Glyphs;
use Porta\Ui\Theme\Styles;

/**
 * The bottom dock's one-row tab strip: {@see TabCell}s laid left to right,
 * dropping LEADING tabs on overflow so the active tab always stays on the
 * strip. Returns the styled run, the columns it used, and the hit map the
 * caller feeds its click/close/hover zones from.
 *
 * A tab's optional `family` groups it: a drag reorders within one family and
 * refuses to cross, so the boundary between two families is drawn heavier
 * than the divider between siblings. Without it the strip claimed every gap
 * was the same and then silently ignored half the drags.
 *
 * The cell and its shading are the strip family's ({@see TabCell},
 * {@see TabStyle}); what is this renderer's own is the windowing.
 */
final class TabStripRenderer
{
    /**
     * @param list<array{label: string, active: bool, hovered: bool, family?: string}> $tabs
     *
     * @return array{line: string, used: int, hitMap: list<array{index: int, start: int, width: int, closeStart: int, closeWidth: int}>}
     */
    public static function render(array $tabs, int $columns, Styles $styles): array
    {
        /** @var list<array{tab: array{label: string, active: bool, hovered: bool, family?: string}, text: string, width: int, closeOffset: int}> $parts */
        $parts = [];
        $activeIndex = 0;
        foreach ($tabs as $i => $tab) {
            if ($tab['active']) {
                $activeIndex = $i;
            }
            $parts[] = ['tab' => $tab] + TabCell::measure($tab['label']);
        }
        // Drop leading tabs until the active one fits — an unbounded pile
        // must never hide the tab the user is looking at.
        $start = 0;
        while ($start < $activeIndex && array_sum(array_column(\array_slice($parts, $start, $activeIndex - $start + 1), 'width')) > $columns) {
            ++$start;
        }

        $line = '';
        $used = 0;
        $hitMap = [];
        foreach (\array_slice($parts, $start, length: null, preserve_keys: true) as $index => $part) {
            if (($used + $part['width']) > $columns) {
                break;
            }
            $tab = $part['tab'];
            $next = $parts[$index + 1]['tab'] ?? null;
            $crosses = null !== $next && ($tab['family'] ?? null) !== ($next['family'] ?? null);
            $line .= TabCell::render($part['text'], $part['closeOffset'], $styles, TabState::of($tab['active'], $tab['hovered']), $crosses ? Glyphs::FAMILY_SEPARATOR : Glyphs::SEPARATOR);
            $hitMap[] = ['index' => $index, 'start' => $used, 'width' => $part['width'], 'closeStart' => $used + $part['closeOffset'], 'closeWidth' => TabCell::CLOSE_WIDTH];
            $used += $part['width'];
        }

        return ['line' => $line, 'used' => $used, 'hitMap' => $hitMap];
    }
}
