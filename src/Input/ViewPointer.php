<?php

declare(strict_types=1);

namespace Porta\Input;

/**
 * The pointer surface of a view that owns a region: five stages of the mouse
 * pipeline, in the order {@see MouseRouter} drives them.
 *
 * Defined by STAGE rather than by verb, which is why it fits more than one
 * consumer where a previous attempt's eleven-verb union fitted none. Two of
 * those eleven were `separatorRect()` and `leftRect()`, and they existed only
 * because the separator and the sidebar arrived at the view through
 * `#[LayoutSlot]`. Core mints the region now, so it hit-tests its own widgets
 * and those two verbs are gone rather than shared.
 *
 * Three implementations: git, settings and the Welcome modal — which already
 * had four of these five under its own names.
 */
interface ViewPointer
{
    /** An armed drag continuing (diff text selection, modal move); false when nothing is armed. */
    public function continueDrag(MouseEvent $mouse): bool;

    /** A fresh press disarms whatever was mid-drag. */
    public function cancelDrag(): void;

    /** A press on one of the view's panes; false when the pointer missed them all. */
    public function press(MouseEvent $mouse): bool;

    /** A bare move: tint the row under the pointer. */
    public function hover(MouseEvent $mouse): void;

    /** A wheel notch over one of the view's panes; false when it missed them all. */
    public function wheel(WheelGesture $gesture): bool;
}
