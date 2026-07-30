<?php

declare(strict_types=1);

namespace Porta\Widget;

/**
 * An {@see EditorPaneWidget} the pointer can move around in: a zoomable,
 * pannable document. This is the type `Input/WheelRouter` and `Input/MouseRouter`
 * route against, replacing the `ImageViewerWidget|GraphViewerWidget` union that
 * approximated it — an implicit interface that could not be implemented.
 *
 * Separate from the base because it is genuinely optional: the help viewer is a
 * pane and is not navigable (its wheel scrolls whole rows, a different verb with
 * a different signature), and a pane that does not extend this simply receives
 * no wheel or drag routing.
 *
 * Every method takes coordinates RELATIVE to the pane's rect, already converted
 * by the router through `WidgetRect::toRelative()`.
 */
abstract class NavigablePaneWidget extends EditorPaneWidget
{
    /** A wheel/drag pan: columns and HALF-rows, since one cell is two vertical pixels. */
    abstract public function scrollBy(int $dxCells, int $dyHalfRows): void;

    /** One notch of the zoom ladder toward ($relCol, $relRow); $dir is +1 in, -1 out. */
    abstract public function zoomAt(int $dir, int $relCol, int $relRow): void;

    /** A continuous zoom by $factor toward ($relCol, $relRow) — the trackpad glide. */
    abstract public function magnifyAt(float $factor, int $relCol, int $relRow): void;

    /** A click that selects what is under it; panes with nothing selectable keep the no-op. */
    public function selectAt(int $relRow, int $relCol): void {}
}
