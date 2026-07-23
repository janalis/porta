<?php

declare(strict_types=1);

namespace Porta\Widget\Support;

use Porta\Support\IntMath;

/**
 * Shared row-count state for the resizable bottom panels (results, diff,
 * terminal). Each widget sets its own default in its constructor; the layout
 * controller drives resizes through {@see setVisibleRows()}.
 */
trait PanelRows
{
    private int $visibleRows;

    /** Satisfied by AbstractWidget. */
    abstract public function invalidate(): void;

    /**
     * Resize the panel to $rows visible rows.
     *
     * The clamp looks redundant beside {@see \Porta\Layout\LayoutController}'s
     * own (3 … rows-8), and for that caller it is — but the two do not
     * disagree, they compose, and this is the widget's own floor on a public
     * setter rather than a restatement of someone else's policy. (The sidebar
     * WIDTH bounds were a different story: there the widget and the controller
     * genuinely contradicted, so those moved onto the pane.)
     */
    public function setVisibleRows(int $rows): void
    {
        $rows = IntMath::clamp($rows, 2, 200);
        if ($rows !== $this->visibleRows) {
            $this->visibleRows = $rows;
            $this->invalidate();
        }
    }

    public function visibleRows(): int
    {
        return $this->visibleRows;
    }
}
