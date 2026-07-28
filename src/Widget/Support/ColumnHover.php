<?php

declare(strict_types=1);

namespace Porta\Widget\Support;

/**
 * Column-stored hover for the strip/bar widgets, where {@see HoverTrait}'s
 * row-index model does not fit: the pointer's COLUMN is stored and re-resolved
 * against each frame's layout in render, so a strip that relabels or loses an
 * item under a resting pointer cannot leave the tint on the item that used to
 * be there — while a still-correct tint survives without a fresh motion event.
 * {@see $hoverResolved} caches the last resolved index only to answer "did the
 * hovered item change?" for the router's repaint decision: the widget's own
 * hover method stores via {@see storeHover} with the index its layout resolves.
 */
trait ColumnHover
{
    /** The pointer's widget-relative column (visual hover), or null. */
    private ?int $hoverCol = null;

    /** The item the column last resolved to — repaint change-detection at item granularity. */
    private ?int $hoverResolved = null;

    /** Satisfied by AbstractWidget. */
    abstract public function invalidate(): void;

    /** The pointer left the strip: drop the hover tint. Returns whether it changed. */
    public function clearHover(): bool
    {
        if (null === $this->hoverCol) {
            return false;
        }
        $this->hoverCol = null;
        $this->hoverResolved = null;
        $this->invalidate();

        return true;
    }

    /** Store the hovered column + its resolved item; returns whether the item changed. */
    private function storeHover(int $col, ?int $resolved): bool
    {
        $this->hoverCol = $col;
        if ($resolved === $this->hoverResolved) {
            return false;
        }
        $this->hoverResolved = $resolved;
        $this->invalidate();

        return true;
    }
}
