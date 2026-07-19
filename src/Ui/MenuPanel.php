<?php

declare(strict_types=1);

namespace Porta\Ui;

use Porta\Support\AnsiText;
use Porta\Ui\Theme\Styles;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * Shared geometry + row rendering for a dropdown list of {@see MenuItem}s. Both
 * the application menu bar ({@see \Porta\Widget\MenuBarWidget}) and the
 * right-click context menu ({@see \Porta\Widget\ContextMenu}) render the same
 * panel, so the styling, truncation and selection highlight live here once.
 */
final class MenuPanel
{
    /**
     * Natural width of the panel (unclamped; the caller clamps it to fit the
     * screen). Never narrower than 8 columns so short menus still read as a box.
     *
     * @param list<MenuItem> $items
     */
    public static function width(array $items): int
    {
        $width = 8;
        foreach ($items as $item) {
            if ($item->separator) {
                continue;
            }
            $width = max($width, mb_strwidth($item->label) + ('' !== $item->shortcut ? mb_strwidth($item->shortcut) + 2 : 0) + 4);
        }

        return $width;
    }

    /**
     * The panel's styled rows (one per item, in order), each exactly $panelWidth
     * wide; item $selected is drawn highlighted.
     *
     * @param list<MenuItem> $items
     *
     * @return list<array{segment: string, separator: bool}>
     */
    public static function segments(array $items, int $selected, int $panelWidth, Styles $styles): array
    {
        $out = [];
        foreach ($items as $index => $item) {
            $out[] = [
                'segment' => $item->separator
                    ? $styles->merge(Role::RowHighlightBg, Role::Placeholder)->apply(str_repeat(Glyphs::MENU_SEPARATOR, max(0, $panelWidth)))
                    : self::itemSegment(
                        $item,
                        $panelWidth,
                        $index === $selected ? MenuSelection::Selected : MenuSelection::Unselected,
                        $item->enabled() ? MenuAvailability::Enabled : MenuAvailability::Disabled,
                        $styles,
                    ),
                'separator' => $item->separator,
            ];
        }

        return $out;
    }

    /**
     * Index of the first selectable (non-separator, enabled) item, or 0 if none.
     *
     * @param list<MenuItem> $items
     */
    public static function firstSelectable(array $items): int
    {
        foreach ($items as $i => $item) {
            if (self::selectable($item)) {
                return $i;
            }
        }

        return 0;
    }

    /**
     * Move the selection from $current by $dir, skipping separators and disabled
     * items and wrapping.
     *
     * @param list<MenuItem> $items
     */
    public static function step(array $items, int $current, int $dir): int
    {
        $count = \count($items);
        $i = $current;
        for ($n = 0; $n < $count; ++$n) {
            $i = ($i + $dir + $count) % $count;
            if (self::selectable($items[$i])) {
                return $i;
            }
        }

        return $current;
    }

    /** A row the selection can land on: a runnable, enabled entry. */
    private static function selectable(MenuItem $item): bool
    {
        return !$item->separator && $item->enabled();
    }

    private static function itemSegment(MenuItem $item, int $panelWidth, MenuSelection $selection, MenuAvailability $availability, Styles $styles): string
    {
        $inner = max(0, $panelWidth - 2);
        $shortcut = $item->shortcut;
        $labelMax = max(0, $inner - ('' !== $shortcut ? mb_strwidth($shortcut) + 2 : 0));
        $label = AnsiText::truncate($item->label, $labelMax);
        $gap = max(1, $inner - AnsiUtils::visibleWidth($label) - mb_strwidth($shortcut));

        // Disabled always reads dim and never shows the selected highlight; the
        // SGR dim attribute keeps the state visible without color (monochrome).
        $style = match (true) {
            MenuAvailability::Disabled === $availability => $styles->merge(Role::RowHighlightBg, Role::Gutter)->withDim(),
            MenuSelection::Selected === $selection => $styles->of(Role::SelectedRow),
            default => $styles->of(Role::PopupRow),
        };
        $tail = '' !== $shortcut ? $styles->of(Role::CompletionHint)->apply($shortcut) : str_repeat(' ', max(0, mb_strwidth($shortcut)));

        return $style->apply(' '.$label.str_repeat(' ', $gap).$tail.' ');
    }
}
