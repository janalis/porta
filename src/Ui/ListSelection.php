<?php

declare(strict_types=1);

namespace Porta\Ui;

/**
 * Selection + scroll-window state for list-style widgets (file tree, results
 * panel, go-to-file). Owns the two idioms every list re-implemented by hand:
 * clamped selection movement and the render-time "scroll follows selection"
 * window shift.
 */
final class ListSelection
{
    private int $selected = 0;
    private int $scroll = 0;

    public function selected(): int
    {
        return $this->selected;
    }

    public function scroll(): int
    {
        return $this->scroll;
    }

    /** Back to the first entry with the window at the top (open / refilter). */
    public function reset(): void
    {
        $this->selected = 0;
        $this->scroll = 0;
    }

    public function select(int $index): void
    {
        $this->selected = $index;
    }

    /**
     * Select $index when it names a row and differs from the current
     * selection; returns whether it changed (the hover-highlight idiom).
     */
    public function selectIfChanged(?int $index): bool
    {
        if (null === $index || $index === $this->selected) {
            return false;
        }
        $this->selected = $index;

        return true;
    }

    /** Move the selection up $step entries, stopping at the first. */
    public function moveUp(int $step = 1): void
    {
        $this->selected = max(0, $this->selected - $step);
    }

    /** Move the selection down $step entries within a $count-entry list. */
    public function moveDown(int $count, int $step = 1): void
    {
        $this->selected = min(max(0, $count - 1), $this->selected + $step);
    }

    /** Re-clamp the selection after the list shrank (e.g. a tree refresh). */
    public function clamp(int $count): void
    {
        $this->selected = min($this->selected, max(0, $count - 1));
    }

    /**
     * Shift the scroll window so the selection stays within $visibleRows
     * visible entries; returns the new scroll offset. Called at render time.
     */
    public function follow(int $visibleRows): int
    {
        if ($this->selected < $this->scroll) {
            $this->scroll = $this->selected;
        } elseif ($this->selected > ($this->scroll + $visibleRows - 1)) {
            $this->scroll = $this->selected - $visibleRows + 1;
        }

        return $this->scroll;
    }
}
