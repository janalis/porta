<?php

declare(strict_types=1);

namespace Porta\Widget;

/**
 * A list widget whose rows tint under the mouse pointer: the routers call
 * {@see hoverRow()} with the widget-relative row when the pointer is inside
 * the widget and {@see clearHover()} when it is not, and repaint when either
 * reports a change. {@see HoverTrait} provides the state and clearHover().
 */
interface HoverableList
{
    /** Highlight the row under the pointer; returns whether it changed. */
    public function hoverRow(int $relRow): bool;

    /** Drop the hover tint (pointer left the widget); returns whether it changed. */
    public function clearHover(): bool;
}
