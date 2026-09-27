<?php

declare(strict_types=1);

namespace Porta\Input;

use Porta\Overlay\Region\DropdownRegion;
use Porta\Ui\Tooltip;
use Porta\Ui\TooltipProvider;
use Porta\Widget\HoverableList;
use Porta\Widget\SeparatorWidget;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Render\WidgetRect;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * Screen-coordinate hit-testing against the last rendered layout: the single
 * home for the "widget rect → contains → to-relative" idiom the mouse router
 * repeats for every pane, and for hit-testing the floating dropdown regions
 * that render outside any widget's rect.
 */
final readonly class HitTester
{
    public function __construct(
        private Renderer $renderer,
    ) {}

    /** Whether $rect exists and the pointer is inside it — the wheel-scroll hit guard. */
    public function contains(?WidgetRect $rect, MouseEvent $mouse): bool
    {
        return null !== $rect && $rect->contains($mouse->row, $mouse->col);
    }

    /**
     * The widget's rect in the last render, or null when the widget is
     * detached: the renderer keeps last-frame rects for widgets that were
     * since swapped out of the tree (git view tabs, the main-area swap), and a
     * stale rect must never route events to an invisible widget.
     *
     * Rendering zero rows is NOT enough to null the rect — the renderer keeps
     * the last non-empty one — so a widget that hides itself while staying
     * attached (the find bar, go-to-file, the dock panels) must gate its own
     * hit-testing on its liveness rather than on a null rect here.
     */
    public function rect(AbstractWidget $widget): ?WidgetRect
    {
        if (null === $widget->getContext()) {
            return null;
        }

        return $this->renderer->getWidgetRect($widget);
    }

    /**
     * Widget-relative coordinates of a screen cell when it is inside the
     * widget's last-rendered rect, else null.
     *
     * @return array{row: int, col: int}|null
     */
    public function hit(AbstractWidget $widget, int $row, int $col): ?array
    {
        $rect = $this->rect($widget);
        if (null === $rect || !$rect->contains($row, $col)) {
            return null;
        }

        return $rect->toRelative($row, $col);
    }

    /**
     * What the widget under a screen cell says about it, placed back on the
     * screen, or null when the cell is outside the widget or means nothing.
     */
    public function tooltip(AbstractWidget&TooltipProvider $widget, int $row, int $col): ?Tooltip
    {
        $rect = $this->rect($widget);
        if (null === $rect || !$rect->contains($row, $col)) {
            return null;
        }

        return $widget->tooltipAt($row - $rect->row, $col - $rect->col)?->moved($rect->row, $rect->col);
    }

    /**
     * Tint the row under the pointer in each list — a miss clears that
     * list's tint (pointer over another pane) — and report whether any
     * of them changed, so the caller repaints once.
     */
    public function hover(int $row, int $col, AbstractWidget&HoverableList ...$lists): bool
    {
        $changed = false;
        foreach ($lists as $list) {
            $rel = $this->hit($list, $row, $col);
            $changed = (null !== $rel ? $list->hoverRow($rel['row']) : $list->clearHover()) || $changed;
        }

        return $changed;
    }

    /**
     * Thicken the pane divider under the pointer and thin it on a miss;
     * reports whether it changed, so the caller repaints once. The sibling of
     * {@see hover()} for the one-column dividers, which key off the pointer
     * being anywhere on them rather than off a row.
     */
    public function hoverDivider(int $row, int $col, SeparatorWidget $divider): bool
    {
        return null !== $this->hit($divider, $row, $col) ? $divider->hover() : $divider->clearHover();
    }

    /**
     * Item index of a screen cell inside a floating dropdown region (as
     * tracked by the overlay controller), or null when outside the region —
     * or on a framed region's border cells ({@see DropdownRegion::itemAt}).
     */
    public function hitRegion(?DropdownRegion $region, int $row, int $col): ?int
    {
        return $region?->itemAt($row, $col);
    }
}
