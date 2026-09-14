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

    /** Whether $index is the selected row (the render-loop highlight test). */
    public function isSelected(int $index): bool
    {
        return $index === $this->selected;
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

            return $this->scroll;
        }
        if ($this->selected > ($this->scroll + $visibleRows - 1)) {
            $this->scroll = $this->selected - $visibleRows + 1;
        }

        return $this->scroll;
    }

    /**
     * Move the scroll window by $delta on its own, for a pointer drag that has
     * to reach a row off the end of the pane. Returns whether it moved.
     *
     * The inverse of {@see follow()}, and it has to cooperate with it: `follow()`
     * runs on every render and pulls the window back to CONTAIN the selection,
     * so a window moved past the selection would snap straight back and the drag
     * would never reach anything. The selection is therefore dragged along, but
     * only when it would otherwise fall outside the new window — the smallest
     * change that leaves `follow()` with nothing to undo.
     *
     * Every other mover here works the other way round, moving the selection and
     * letting the window follow. A drag is the one gesture that cannot: the row
     * being aimed at is off-screen, and in the tree the selection decides what
     * the next click acts on.
     */
    public function scrollWindow(int $delta, int $count, int $visibleRows): bool
    {
        $last = max(0, $count - $visibleRows);
        $scroll = min($last, max(0, $this->scroll + $delta));
        if ($scroll === $this->scroll) {
            return false;
        }
        $this->scroll = $scroll;
        $this->selected = min(max($this->selected, $scroll), $scroll + $visibleRows - 1);

        return true;
    }

    /**
     * Variable-height sibling of {@see follow()}: shift the scroll window so
     * the selected item fits within $availableRows, where $heights[$i] is item
     * $i's rendered row count. Walks back from the selection accumulating
     * heights, so as many items above it as fit stay visible; a selection
     * taller than the viewport pins the window to itself. Returns the new
     * scroll offset. Called at render time.
     *
     * @param list<int> $heights
     */
    public function followHeights(array $heights, int $availableRows): int
    {
        if ($this->selected < $this->scroll) {
            $this->scroll = $this->selected;

            return $this->scroll;
        }
        $sum = 0;
        for ($i = $this->selected; $i >= $this->scroll; --$i) {
            $sum += $heights[$i] ?? 0;
            if ($sum > $availableRows) {
                $this->scroll = min($i + 1, $this->selected);
                break;
            }
        }

        return $this->scroll;
    }

    /**
     * How many entries fit in $availableRows starting at $from — the page size
     * for a variable-height list, and the question {@see followHeights()}
     * answers from the other end.
     *
     * Never fewer than one, which the floor on the way out handles rather than
     * a guard in the loop: an entry taller than the whole window breaks on its
     * first step and still has to be reachable, and a page of zero is not a
     * page — it turns Page Down into a key that does nothing.
     *
     * @param list<int> $heights
     */
    public function countFitting(array $heights, int $from, int $availableRows): int
    {
        $fitting = 0;
        $used = 0;
        foreach (\array_slice($heights, $from) as $height) {
            $used += $height;
            if ($used > $availableRows) {
                break;
            }
            ++$fitting;
        }

        return max(1, $fitting);
    }

    /**
     * Map a widget-relative pointer row to the item index under it, or null
     * when the row is a header or falls past the list. $headerRows is how many
     * non-item rows precede the list (1 for a single title row — the common
     * case; 0 when the list starts at the very top).
     */
    public function rowIndexAt(int $relRow, int $count, int $headerRows = 1): ?int
    {
        $index = $this->scroll + $relRow - $headerRows;

        return $relRow >= $headerRows && $index < $count ? $index : null;
    }
}
