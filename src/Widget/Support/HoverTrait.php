<?php

declare(strict_types=1);

namespace Porta\Widget\Support;

/**
 * Shared "row under the mouse pointer" tint state for the list widgets. The
 * hovered index is visual only — never the selection. Each widget maps a
 * pointer row to an index in its own {@see hoverRow()}; this trait owns the
 * stored index, the change-detecting setter and the clear helper (both used by
 * the mouse router to decide whether a repaint is needed).
 *
 * Hover is tint-only in every pane whose selection outlives the pointer — ask
 * "can the selection change while the pointer sits still?", and if it can, the
 * pointer must not own it. a list widget's `hoverRow()`
 * is the load-bearing case (its selection drives a diff reload) and the
 * query-field panels are the subtle one: a resting pointer would silently
 * re-target Enter while you type.
 *
 * The two menu surfaces are the deliberate exception. In {@see MenuBarWidget}
 * and {@see ContextMenu} the highlight *is* the selection: there is no second
 * selected-row concept, no field to type into, the surface closes on any
 * click, and nothing but the item's own run() reads the index. Keyboard arrows
 * and mouse hover must agree there, which is exactly what a tint/selection
 * split would break.
 *
 * Single-index lists only. A widget with two independent sub-list tints
 * implements {@see HoverableList} directly — see
 * a paged history list.
 */
trait HoverTrait
{
    /** Row tinted under the mouse pointer; visual only, never the selection. */
    private ?int $hoveredIndex = null;

    /** Satisfied by AbstractWidget. */
    abstract public function invalidate(): void;

    /** The pointer left the list: drop the hover tint. Returns whether it changed. */
    public function clearHover(): bool
    {
        return $this->setHovered(null);
    }

    /** Store $index as the hovered row; returns whether the tint changed. */
    private function setHovered(?int $index): bool
    {
        if ($index === $this->hoveredIndex) {
            return false;
        }
        $this->hoveredIndex = $index;
        $this->invalidate();

        return true;
    }
}
