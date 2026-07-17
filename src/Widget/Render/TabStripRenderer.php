<?php

declare(strict_types=1);

namespace Porta\Widget\Render;

use Porta\Ui\Glyphs;
use Porta\Ui\Role;
use Porta\Ui\Theme\Styles;

/**
 * The shared one-row tab strip: ` label × │ label × │ …` in the unified
 * strip family — active tab on {@see Role::TabActiveBg} (bright, bold),
 * inactive on Toolbar (subtle), a hovered inactive tab on the bar-chrome
 * hover shade, a {@see TabCloseGlyph} per tab and a Placeholder divider.
 * Overflow drops LEADING tabs so the active tab always stays on the strip.
 * Returns the styled run, the columns it used, and the hit map the caller
 * feeds its click/close/hover zones from.
 */
final class TabStripRenderer
{
    /**
     * @param list<array{label: string, active: bool, hovered: bool}> $tabs
     *
     * @return array{line: string, used: int, hitMap: list<array{index: int, start: int, width: int, closeStart: int, closeWidth: int}>}
     */
    public static function render(array $tabs, int $columns, Styles $styles): array
    {
        /** @var list<array{tab: array{label: string, active: bool, hovered: bool}, text: string, width: int, closeOffset: int}> $parts */
        $parts = [];
        $activeIndex = 0;
        foreach ($tabs as $i => $tab) {
            if ($tab['active']) {
                $activeIndex = $i;
            }
            $prefix = ' '.$tab['label'].' ';
            $text = $prefix.'× ';
            $parts[] = ['tab' => $tab, 'text' => $text, 'width' => mb_strwidth($text) + 1, 'closeOffset' => mb_strwidth($prefix)];
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
            $style = match (true) {
                $tab['active'] => $styles->merge(Role::TabActiveBg, Role::Bright)->withBold(),
                $tab['hovered'] => $styles->merge(Role::BarHoverBg, Role::Subtle),
                default => $styles->merge(Role::Toolbar, Role::Subtle),
            };
            $close = $tab['active'] ? TabCloseGlyph::active($styles) : TabCloseGlyph::inactive($styles);
            $line .= $style->apply(str_replace('×', $close, $part['text'])).$styles->of(Role::Placeholder)->apply(Glyphs::SEPARATOR);
            $hitMap[] = ['index' => $index, 'start' => $used, 'width' => $part['width'], 'closeStart' => $used + $part['closeOffset'], 'closeWidth' => 2];
            $used += $part['width'];
        }

        return ['line' => $line, 'used' => $used, 'hitMap' => $hitMap];
    }
}
