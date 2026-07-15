<?php

declare(strict_types=1);

namespace Porta\Widget;

/**
 * Shared "row under the mouse pointer" tint state for the list widgets. The
 * hovered index is visual only — never the selection. Each widget maps a
 * pointer row to an index in its own {@see hoverRow()}; this trait owns the
 * stored index, the change-detecting setter and the clear helper (both used by
 * the mouse router to decide whether a repaint is needed).
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
