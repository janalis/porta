<?php

declare(strict_types=1);

namespace Porta\Workbench\Menu;

use Porta\I18n\BaseDirection;
use Porta\I18n\Bidi;
use Porta\Support\AnsiText;
use Porta\Ui\Glyphs;
use Porta\Ui\Role;
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
    /** Never narrower than this, so short menus still read as a box. */
    private const int MIN_WIDTH = 8;

    /** Columns an item row spends beside its text: the edge spaces plus the label↔shortcut breathing room. */
    private const int ITEM_CHROME = 4;

    /** One edge space each side of the row (`⎵label…shortcut⎵`). */
    private const int EDGE_COLS = 2;

    /** Minimum gap between a label and its right-aligned shortcut. */
    private const int SHORTCUT_GAP = 2;

    /**
     * Natural width of the panel (unclamped; the caller clamps it to fit the
     * screen).
     *
     * @param list<MenuItem> $items
     */
    public static function width(array $items): int
    {
        $width = self::MIN_WIDTH;
        foreach ($items as $item) {
            if ($item->separator) {
                continue;
            }
            $width = \max($width, \mb_strwidth($item->label) + ('' !== $item->shortcut ? \mb_strwidth($item->shortcut) + self::SHORTCUT_GAP : 0) + self::ITEM_CHROME);
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
    public static function segments(array $items, int $selected, int $panelWidth, Styles $styles, BaseDirection $direction = BaseDirection::Ltr): array
    {
        $out = [];
        foreach ($items as $index => $item) {
            $out[] = [
                'segment' => $item->separator
                    ? $styles->merge(Role::PopupRow, Role::Placeholder)->apply(\str_repeat(Glyphs::MENU_SEPARATOR, \max(0, $panelWidth)))
                    : self::itemSegment(
                        $item,
                        $panelWidth,
                        $index === $selected ? MenuSelection::Selected : MenuSelection::Unselected,
                        $item->enabled() ? MenuAvailability::Enabled : MenuAvailability::Disabled,
                        $styles,
                        $direction,
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

    private static function itemSegment(MenuItem $item, int $panelWidth, MenuSelection $selection, MenuAvailability $availability, Styles $styles, BaseDirection $direction): string
    {
        $inner = \max(0, $panelWidth - self::EDGE_COLS);
        $shortcut = $item->shortcut;
        $labelMax = \max(0, $inner - ('' !== $shortcut ? \mb_strwidth($shortcut) + self::SHORTCUT_GAP : 0));
        $label = AnsiText::truncate($item->label, $labelMax);
        $gap = \max(1, $inner - AnsiUtils::visibleWidth($label) - \mb_strwidth($shortcut));

        // Disabled reads dim with its label struck through, and never shows
        // the selected highlight. It used to borrow the row-hover shade, which
        // monochrome draws as an underline — a disabled item looked hovered.
        // Strikethrough and dim are attributes, so they survive --no-ansi.
        $style = match (true) {
            MenuAvailability::Disabled === $availability => $styles->merge(Role::PopupRow, Role::Faint)->withDim(),
            MenuSelection::Selected === $selection => $styles->of(Role::SelectedRow),
            default => $styles->of(Role::PopupRow),
        };
        if (MenuAvailability::Disabled === $availability) {
            $label = $style->withStrikethrough()->apply($label).$style->getAnsiRestore();
        }
        $tail = '' !== $shortcut ? $styles->of(Role::CompletionHint)->apply($shortcut) : \str_repeat(' ', \max(0, \mb_strwidth($shortcut)));

        // A mirrored menu spends one gap column on a mark that ENDS the label's
        // run. Four shipped action labels end in a Latin run (`הצג TODO`,
        // `Git: הסחנה (stash)`, …) and a tail like that is strong left-to-right
        // just as the shortcut is, so without it the two merge and the shortcut
        // is drawn inside the label. `$gap` is floored at 1, and Bidi spends the
        // mark for a space, so the row keeps its width either way.
        $divide = BaseDirection::Rtl === $direction
            ? Bidi::RTL_MARK.\str_repeat(' ', $gap - 1)
            : \str_repeat(' ', $gap);

        return $style->apply(' '.$label.$divide.$tail.' ');
    }
}
