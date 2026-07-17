<?php

declare(strict_types=1);

namespace Porta\Widget;

/**
 * A pane the mouse wheel scrolls. The routers ask for the distance in rows
 * ({@see \Porta\Input\WheelStep}) and the pane decides what moving means:
 * a real viewport shifts its window, while a selection-driven list feeds the
 * arrow keys through itself ({@see ArrowScrollTrait}) so the keyboard's side
 * effects fire too.
 *
 * The wheel moves a list's selection where hover does not, and the split is
 * deliberate: the wheel is an act, hover is incidental pointer motion. See
 * {@see HoverTrait}.
 */
interface WheelScrollable
{
    /** Scroll by $delta rows; negative scrolls up. */
    public function scrollBy(int $delta): void;
}
