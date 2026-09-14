<?php

declare(strict_types=1);

namespace Porta\Widget\Render;

/**
 * The overflow window shared by the two horizontal tab strips — the editor tab
 * bar ({@see \Porta\Widget\TabBarWidget}) and the bottom dock strip
 * ({@see \Porta\Widget\DockTabsWidget}). Given the tab widths, the pane columns,
 * the widget's stored scroll offset and the active tab, it answers: which tab to
 * start drawing at, how many columns the tabs may use, whether the `‹`/`›`
 * indicators are needed, and the clamped scroll offset (which follows the active
 * tab only when it *changes*, so a user's manual scroll survives parked frames).
 *
 * This is the one piece the two strips must not let drift apart: they window the
 * same way or a tab visible on one is hidden on the other. The cell shading is
 * already shared ({@see TabStyle}, {@see TabCell}); this is the windowing.
 */
final class StripWindow
{
    /**
     * @param list<int> $widths tab display widths, in strip order
     *
     * @return array{start: int, budget: int, overflow: bool, scroll: int, lastActive: int}
     */
    public static function of(array $widths, int $columns, int $scroll, int $activeIndex, ?int $lastActive): array
    {
        // An overflowing strip reserves one column per side for the ‹/› scroll
        // indicators, so the tab geometry never depends on which sides clip.
        $overflow = $columns > 2 && \array_sum($widths) > $columns;
        $budget = $overflow ? $columns - 2 : $columns;

        // Clamp against a blank tail, then follow the active tab — but only when
        // it changed, so a user's manual scroll survives.
        $scroll = \min($scroll, self::maxStart($widths, $budget));
        if ($activeIndex !== $lastActive) {
            $lastActive = $activeIndex;
            $scroll = \min($scroll, $activeIndex);
            while ($scroll < $activeIndex && \array_sum(\array_slice($widths, $scroll, $activeIndex - $scroll + 1)) > $budget) {
                ++$scroll;
            }
        }

        return ['start' => $scroll, 'budget' => $budget, 'overflow' => $overflow, 'scroll' => $scroll, 'lastActive' => $lastActive];
    }

    /**
     * The largest start index that still fills the strip — no blank tail past the last tab.
     *
     * @param list<int> $widths
     */
    private static function maxStart(array $widths, int $budget): int
    {
        $start = \count($widths) - 1;
        $tail = 0;
        for ($i = \count($widths) - 1; $i >= 0; --$i) {
            $tail += $widths[$i];
            if ($tail > $budget) {
                break;
            }
            $start = $i;
        }

        return \max(0, $start);
    }
}
