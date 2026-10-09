<?php

declare(strict_types=1);

namespace Porta\Overlay\Region;

use Porta\Support\IntMath;
use Porta\Ui\Tooltip;

/**
 * Where a tooltip card of a given size goes: flush against the first side of
 * the element with room for all of it — below, above, right, left — centred
 * on the element (on its middle cell; the left or upper of two when even)
 * and slid back inside the pane union when an edge is near. When no side has
 * room it takes the roomier of below and above and clamps, so a card on a
 * tiny terminal covers the element rather than leaving the screen.
 */
final readonly class TooltipPlacement
{
    public function __construct(
        public int $top,
        public int $col,
        public TooltipSide $side,
    ) {}

    /**
     * @param array{int, int, int, int} $bounds [top, left, bottom, right)
     */
    public static function place(Tooltip $anchor, int $width, int $height, array $bounds): self
    {
        [$boundTop, $boundLeft, $boundBottom, $boundRight] = $bounds;
        $below = $anchor->row + \max(1, $anchor->rows);
        $fitsBeside = $height <= ($boundBottom - $boundTop);
        // The side the element asks for, where the card fits there.
        $asked = match ($anchor->side) {
            TooltipSide::Below => ($below + $height) <= $boundBottom ? self::vertical($anchor, $width, $below, TooltipSide::Below, $bounds) : null,
            TooltipSide::Above => ($anchor->row - $height) >= $boundTop ? self::vertical($anchor, $width, $anchor->row - $height, TooltipSide::Above, $bounds) : null,
            TooltipSide::Right => $fitsBeside && ($anchor->colEnd + $width) <= $boundRight ? self::beside($anchor, $height, $anchor->colEnd, TooltipSide::Right, $bounds) : null,
            TooltipSide::Left => $fitsBeside && ($anchor->colStart - $width) >= $boundLeft ? self::beside($anchor, $height, $anchor->colStart - $width, TooltipSide::Left, $bounds) : null,
            null => null,
        };

        // Else the first side that fits, or the roomier of below and above, clamped.
        return $asked ?? match (true) {
            ($below + $height) <= $boundBottom => self::vertical($anchor, $width, $below, TooltipSide::Below, $bounds),
            ($anchor->row - $height) >= $boundTop => self::vertical($anchor, $width, $anchor->row - $height, TooltipSide::Above, $bounds),
            $fitsBeside && ($anchor->colEnd + $width) <= $boundRight => self::beside($anchor, $height, $anchor->colEnd, TooltipSide::Right, $bounds),
            $fitsBeside && ($anchor->colStart - $width) >= $boundLeft => self::beside($anchor, $height, $anchor->colStart - $width, TooltipSide::Left, $bounds),
            ($boundBottom - $below) >= ($anchor->row - $boundTop) => self::vertical($anchor, $width, \max($boundTop, \min($below, $boundBottom - $height)), TooltipSide::Below, $bounds),
            default => self::vertical($anchor, $width, \max($boundTop, $anchor->row - $height), TooltipSide::Above, $bounds),
        };
    }

    /** @param array{int, int, int, int} $bounds */
    private static function vertical(Tooltip $anchor, int $width, int $top, TooltipSide $side, array $bounds): self
    {
        [, $boundLeft, , $boundRight] = $bounds;
        $middle = $anchor->colStart + \intdiv(\max(1, $anchor->colEnd - $anchor->colStart) - 1, num2: 2);

        return new self($top, \max($boundLeft, \min($middle - \intdiv($width, num2: 2), $boundRight - $width)), $side);
    }

    /** @param array{int, int, int, int} $bounds */
    private static function beside(Tooltip $anchor, int $height, int $col, TooltipSide $side, array $bounds): self
    {
        [$boundTop, , $boundBottom] = $bounds;
        $middle = $anchor->row + \intdiv(\max(1, $anchor->rows) - 1, num2: 2);

        return new self(IntMath::clamp($middle - \intdiv($height, num2: 2), $boundTop, $boundBottom - $height), $col, $side);
    }
}
