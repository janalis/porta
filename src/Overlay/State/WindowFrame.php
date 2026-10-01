<?php

declare(strict_types=1);

namespace Porta\Overlay\State;

use Porta\Overlay\Region\ModalBox;
use Porta\Overlay\Region\WindowGeometry;
use Porta\Overlay\Region\WindowPlacement;
use Porta\Overlay\Region\WindowZone;
use Porta\Support\IntMath;

/**
 * The one move-and-resize state machine behind every floating window — the
 * pickers, Find in Files, the Welcome modal and the view windows — so each
 * of them drags by its title bar, resizes from the same edges and corners,
 * lights the same handles and clamps the same way.
 *
 * It owns geometry only: where the user put the box and how big they made
 * it, and the drag under way. What goes inside is the window's; it asks
 * {@see place()} once per layout for the box, draws its content at the size
 * left inside, and hands the drawn {@see ModalBox} back to hit-testing.
 *
 * Everything is session-only: {@see reset()} on open puts the window back at
 * its default size and placement, which a surface whose content decides its
 * size cannot carry across opens anyway.
 */
final class WindowFrame
{
    /** Position override (a moved box); null while the placement decides. */
    private ?int $top = null;

    private ?int $col = null;

    /** Size override (a resized box, frame included); null while the default decides. */
    private ?int $width = null;

    private ?int $rows = null;

    /** The handle under way, where it was pressed and the box it started from; null when idle. */
    private ?WindowZone $dragZone = null;

    private int $pressRow = 0;

    private int $pressCol = 0;

    private ?WindowGeometry $dragStart = null;

    /** The handle under the pointer (the × or a resize handle), for the frame's hot glyphs. */
    private ?WindowZone $hovered = null;

    /** The last placement, the floors it was placed with, and the bounds it was clamped to. */
    private ?WindowGeometry $placed = null;

    private int $minWidth = 1;

    private int $minRows = 1;

    private ?int $maxRows = null;

    /** @var array{int, int, int, int} */
    private array $bounds = [0, 0, 0, 0];

    /** Forget the user's position and size, and any drag or hover: the window opens fresh. */
    public function reset(): void
    {
        $this->top = null;
        $this->col = null;
        $this->width = null;
        $this->rows = null;
        $this->placed = null;
        $this->hovered = null;
        $this->cancel();
    }

    /**
     * The box for this frame: the user's size, else $width × $rows, floored at
     * the minimum and capped at the bounds; the user's position, else the
     * placement's, clamped so the box stays inside the bounds. A clamped
     * position is kept, so a box pushed back by a shrinking terminal does not
     * spring out again; a size is not, so it comes back when there is room.
     * $maxRows caps the height of a window whose content has a fixed one (a
     * one-field prompt), so its vertical handles stop where the content does.
     *
     * @param array{int, int, int, int} $bounds [top, left, bottom, right)
     */
    public function place(array $bounds, int $width, int $rows, WindowPlacement $placement, int $minWidth = 1, int $minRows = 1, ?int $maxRows = null): WindowGeometry
    {
        [$boundTop, $boundLeft, $boundBottom, $boundRight] = $bounds;
        $this->bounds = $bounds;
        $this->minWidth = $minWidth;
        $this->minRows = $minRows;
        $width = \max(1, \min($boundRight - $boundLeft, \max($minWidth, $this->width ?? $width)));
        $rows = \max(1, \min($boundBottom - $boundTop, $maxRows ?? \PHP_INT_MAX, \max($minRows, $this->rows ?? $rows)));
        $this->maxRows = $maxRows;

        [$top, $col] = $placement->origin($bounds, $rows, $width);
        if (null !== $this->top && null !== $this->col) {
            $top = IntMath::clamp($this->top, $boundTop, \max($boundTop, $boundBottom - $rows));
            $col = IntMath::clamp($this->col, $boundLeft, \max($boundLeft, $boundRight - $width));
            $this->top = $top;
            $this->col = $col;
        }

        return $this->placed = new WindowGeometry($top, $col, $width, $rows);
    }

    /**
     * A left press on the drawn $box: the title bar arms a move, an edge or a
     * corner arms a resize from the box as it was last placed. Answers the
     * zone, so the window can close on the × or an outside press and route a
     * content press to what it holds.
     */
    public function press(ModalBox $box, int $row, int $col): WindowZone
    {
        $zone = $box->zoneAt($row, $col);
        $start = $this->placed;
        if (null === $start || WindowZone::Title !== $zone && !$zone->isResize()) {
            return $zone;
        }
        $this->dragZone = $zone;
        $this->pressRow = $row;
        $this->pressCol = $col;
        $this->dragStart = $start;
        $this->top = $start->top;
        $this->col = $start->col;

        return $zone;
    }

    /**
     * Follow the pointer with the armed handle; false when none is armed, so
     * the router's chain falls through. A move carries the box with the grab
     * offset. A resize moves only the edges the handle holds: the opposite
     * edge stays where it was, which for a left or top handle means the
     * origin moves as the size changes, and the floor stops the handle
     * rather than pushing the far edge. $release ends the drag.
     */
    // @mago-expect lint:no-boolean-flag-parameter -- the bool IS the domain here, not a hidden second responsibility.
    public function dragTo(int $row, int $col, bool $release): bool
    {
        $zone = $this->dragZone;
        $start = $this->dragStart;
        if (null === $zone || null === $start) {
            return false;
        }
        $rowDelta = $row - $this->pressRow;
        $colDelta = $col - $this->pressCol;
        WindowZone::Title === $zone
            ? $this->moveBy($start, $rowDelta, $colDelta)
            : $this->resizeBy($zone, $start, $rowDelta, $colDelta);
        if ($release) {
            $this->cancel();
        }

        return true;
    }

    private function moveBy(WindowGeometry $start, int $rowDelta, int $colDelta): void
    {
        $this->top = $start->top + $rowDelta;
        $this->col = $start->col + $colDelta;
    }

    private function resizeBy(WindowZone $zone, WindowGeometry $start, int $rowDelta, int $colDelta): void
    {
        [$boundTop, $boundLeft] = $this->bounds;
        $width = $start->width;
        $rows = $start->rows;
        $this->top = $start->top;
        $this->col = $start->col;
        if ($zone->movesRight()) {
            $width = \max($this->minWidth, $start->width + $colDelta);
        }
        if ($zone->movesLeft()) {
            $width = \min($start->right() - $boundLeft, \max($this->minWidth, $start->width - $colDelta));
            $this->col = $start->right() - $width;
        }
        if ($zone->movesBottom()) {
            $rows = \min($this->maxRows ?? \PHP_INT_MAX, \max($this->minRows, $start->rows + $rowDelta));
        }
        if ($zone->movesTop()) {
            $rows = \min($start->bottom() - $boundTop, $this->maxRows ?? \PHP_INT_MAX, \max($this->minRows, $start->rows - $rowDelta));
            $this->top = $start->bottom() - $rows;
        }
        $this->width = $width;
        $this->rows = $rows;
    }

    /** Drop the drag under way; a fresh press anywhere disarms a stale one. */
    public function cancel(): void
    {
        $this->dragZone = null;
        $this->dragStart = null;
    }

    public function isDragging(): bool
    {
        return null !== $this->dragZone;
    }

    /**
     * Track the handle under the pointer on the drawn $box (null: no box);
     * true when it changed, so the window repaints only on a real move.
     */
    public function hover(?ModalBox $box, int $row, int $col): bool
    {
        $zone = $box?->zoneAt($row, $col);
        $hovered = WindowZone::Close === $zone || true === $zone?->isResize() ? $zone : null;
        $changed = $hovered !== $this->hovered;
        $this->hovered = $hovered;

        return $changed;
    }

    /** The handle to draw hot: the one being dragged to a new size, else the one under the pointer. */
    public function hot(): ?WindowZone
    {
        return true === $this->dragZone?->isResize() ? $this->dragZone : $this->hovered;
    }
}
