<?php

declare(strict_types=1);

namespace Porta\Ui;

/**
 * A surface that can say what the cell under the pointer means. The pointer
 * router asks whichever surface owns the cell, in its own coordinates, on
 * every move; the answer must be cheap and must not change state — it reads
 * what the last render recorded.
 */
interface TooltipProvider
{
    /** The tooltip at the cell ($row, $col) in this surface's own coordinates, or null for none. */
    public function tooltipAt(int $row, int $col): ?Tooltip;
}
