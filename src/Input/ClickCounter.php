<?php

declare(strict_types=1);

namespace Porta\Input;

/**
 * Double/triple-click detection: counts consecutive presses landing in the same
 * cell within a short window, capped at a triple-click. Held by {@see MouseRouter}
 * and extracted so the routing logic stays free of this click-timing state (the
 * only mutable state the router carried that no routing method touched).
 */
final class ClickCounter
{
    private const float WINDOW_SECONDS = 0.4;

    private float $lastTime = 0.0;
    private int $lastRow = -1;
    private int $lastCol = -1;
    private int $count = 0;

    /** The click multiplicity (1, 2 or 3) for a press at ($row, $col) right now. */
    public function countAt(int $row, int $col): int
    {
        $now = microtime(as_float: true);
        $sameCell = ($now - $this->lastTime) < self::WINDOW_SECONDS && $row === $this->lastRow && $col === $this->lastCol;
        $this->count = $sameCell ? min($this->count + 1, 3) : 1;
        $this->lastTime = $now;
        $this->lastRow = $row;
        $this->lastCol = $col;

        return $this->count;
    }
}
