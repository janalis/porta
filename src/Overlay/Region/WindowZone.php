<?php

declare(strict_types=1);

namespace Porta\Overlay\Region;

/**
 * Which part of a floating window's frame a cell falls on — the one answer
 * every modal's press, drag and hover reads, so each surface moves, resizes
 * and closes the same way.
 *
 * The title bar is the move handle, so the top edge has no resize band of its
 * own: only its two corner cells resize. The ✖ sits on the title bar and wins
 * over it.
 */
enum WindowZone
{
    case Close;
    case Title;
    case Left;
    case Right;
    case Bottom;
    case TopLeft;
    case TopRight;
    case BottomLeft;
    case BottomRight;
    case Content;
    case Outside;

    /** Whether a press here arms a resize rather than a move, a close or a click. */
    public function isResize(): bool
    {
        return match ($this) {
            self::Left, self::Right, self::Bottom, self::TopLeft, self::TopRight, self::BottomLeft, self::BottomRight => true,
            default => false,
        };
    }

    /** Whether dragging this handle moves the box's left edge (the right one stays put). */
    public function movesLeft(): bool
    {
        return self::Left === $this || self::TopLeft === $this || self::BottomLeft === $this;
    }

    /** Whether dragging this handle moves the box's right edge. */
    public function movesRight(): bool
    {
        return self::Right === $this || self::TopRight === $this || self::BottomRight === $this;
    }

    /** Whether dragging this handle moves the box's top edge (the bottom one stays put). */
    public function movesTop(): bool
    {
        return self::TopLeft === $this || self::TopRight === $this;
    }

    /** Whether dragging this handle moves the box's bottom edge. */
    public function movesBottom(): bool
    {
        return self::Bottom === $this || self::BottomLeft === $this || self::BottomRight === $this;
    }
}
