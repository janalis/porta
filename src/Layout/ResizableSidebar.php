<?php

declare(strict_types=1);

namespace Porta\Layout;

/**
 * A pane the mouse or keyboard can resize by columns, and the bounds it may be
 * resized within. The bounds live here because they are the pane's own fact —
 * they used to be spelled out again per call site in {@see LayoutController}
 * (apply, drag, nudge), which is how the git sidebar ended up declaring a
 * floor of 24 that the controller's 30 silently overrode.
 *
 * The controller still owns the bound that is about the NEIGHBOUR rather than
 * the pane: whatever is beside it must stay usable, at any terminal width.
 */
interface ResizableSidebar
{
    public function width(): int;

    public function setWidth(int $width): void;

    /** Narrowest this pane may be resized to. */
    public function minWidth(): int;

    /** Widest this pane may be resized to, in columns. */
    public function maxWidth(): int;

    /** Widest this pane may be resized to, as a fraction of the terminal. */
    public function maxRatio(): float;
}
