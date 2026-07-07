<?php

declare(strict_types=1);

namespace Porta\Input;

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
     * The widget's rect in the last render, or null when it produced no rows
     * — or when the widget is detached: the renderer keeps last-frame rects
     * for widgets that were since swapped out of the tree (git view tabs, the
     * main-area swap), and a stale rect must never route events to an
     * invisible widget.
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
     * Row offset of a screen cell inside a floating dropdown region
     * (as tracked by the overlay controller), or null when outside.
     *
     * @param array{top: int, col: int, width: int, count: int}|null $region
     */
    public function hitRegion(?array $region, int $row, int $col): ?int
    {
        if (null === $region || $row < $region['top'] || $row >= ($region['top'] + $region['count']) || $col < $region['col'] || $col >= ($region['col'] + $region['width'])) {
            return null;
        }

        return $row - $region['top'];
    }
}
