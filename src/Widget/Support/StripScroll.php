<?php

declare(strict_types=1);

namespace Porta\Widget\Support;

/**
 * Manual scroll state for a horizontal tab strip that overflows with clickable
 * ‹/› indicators — shared by the editor tab bar and the dock strip so the two
 * behave identically (they already share {@see \Porta\Widget\Render\StripWindow}
 * for the windowing itself). The widget's render records each indicator's cell
 * into {@see $indicatorZones}; the router asks {@see scrollIndicatorAt} before
 * the close/tab hit zones.
 */
trait StripScroll
{
    /** Index of the first visible tab — the manual scroll offset, clamped at render. */
    private int $scroll = 0;

    /** @var list<array{row: int, col: int, width: int, step: int}> the last strip's ‹/› indicator zones */
    private array $indicatorZones = [];

    /** Satisfied by AbstractWidget. */
    abstract public function invalidate(): void;

    /**
     * Scroll the strip by $tabs (mouse wheel / overflow indicators); negative
     * scrolls toward the first tab. Clamping against the tab widths happens at
     * render time, where the geometry is known.
     */
    public function scrollBy(int $tabs): void
    {
        $this->scroll = \max(0, $this->scroll + $tabs);
        $this->invalidate();
    }

    /**
     * The scroll step of the overflow indicator at the given widget-relative
     * cell (negative pages toward the first tab), or null when the cell is
     * not a clickable indicator. Checked before the close/tab hit zones.
     */
    public function scrollIndicatorAt(int $row, int $col): ?int
    {
        foreach ($this->indicatorZones as $zone) {
            if ($zone['row'] === $row && $col >= $zone['col'] && $col < ($zone['col'] + $zone['width'])) {
                return $zone['step'];
            }
        }

        return null;
    }
}
