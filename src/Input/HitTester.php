<?php

declare(strict_types=1);

namespace Porta\Input;

use Porta\Overlay\DropdownRegion;
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
final class HitTester
{
    public function __construct(
        private readonly Renderer $renderer,
    ) {}

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
