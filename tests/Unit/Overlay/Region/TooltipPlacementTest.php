<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Overlay\Region;

use PHPUnit\Framework\TestCase;
use Porta\Overlay\Region\TooltipPlacement;
use Porta\Overlay\Region\TooltipSide;
use Porta\Ui\Tooltip;

/**
 * Where a card goes: flush against the first side with room — below, above,
 * right, left — centred on the element and slid back inside the bounds.
 */
final class TooltipPlacementTest extends TestCase
{
    /** [top, left, bottom, right) */
    private const array BOUNDS = [1, 0, 21, 80];

    public function testBelowWhenThereIsRoom(): void
    {
        $place = TooltipPlacement::place(new Tooltip('k', 't', 5, 40, 43), 11, 3, self::BOUNDS);

        $this->assertSame([TooltipSide::Below, 6, 36], [$place->side, $place->top, $place->col], 'the row under the element, centred on column 41');
    }

    public function testBelowStartsUnderEveryRowOfATallElement(): void
    {
        $this->assertSame(8, TooltipPlacement::place(new Tooltip('k', 't', 5, 40, 43, rows: 3), 11, 3, self::BOUNDS)->top);
    }

    public function testAboveWhenBelowIsFull(): void
    {
        $place = TooltipPlacement::place(new Tooltip('k', 't', 19, 40, 41), 11, 3, self::BOUNDS);

        $this->assertSame([TooltipSide::Above, 16], [$place->side, $place->top]);
    }

    /** A card that just fits below goes below — the boundary is inclusive. */
    public function testBelowFitsFlushAgainstTheBottom(): void
    {
        $place = TooltipPlacement::place(new Tooltip('k', 't', 17, 40, 41), 11, 3, self::BOUNDS);

        $this->assertSame([TooltipSide::Below, 18], [$place->side, $place->top]);
    }

    public function testAboveFitsFlushAgainstTheTop(): void
    {
        $place = TooltipPlacement::place(new Tooltip('k', 't', 4, 40, 41), 11, 3, [1, 0, 6, 80]);

        $this->assertSame([TooltipSide::Above, 1], [$place->side, $place->top]);
    }

    public function testRightWhenNeitherBelowNorAboveHasRoom(): void
    {
        $place = TooltipPlacement::place(new Tooltip('k', 't', 3, 10, 12), 11, 5, [1, 0, 7, 80]);

        $this->assertSame([TooltipSide::Right, 12, 1], [$place->side, $place->col, $place->top], 'right after the element, centred on row 3 and clamped');
    }

    public function testLeftWhenTheRightIsFullToo(): void
    {
        $place = TooltipPlacement::place(new Tooltip('k', 't', 3, 70, 72), 11, 5, [1, 0, 7, 80]);

        $this->assertSame([TooltipSide::Left, 59], [$place->side, $place->col]);
    }

    public function testBesideCentresOnATallElementsMiddleRow(): void
    {
        $place = TooltipPlacement::place(new Tooltip('k', 't', 2, 10, 12, rows: 3), 11, 3, [1, 0, 7, 80]);

        $this->assertSame([TooltipSide::Right, 2], [$place->side, $place->top], 'middle row 3, minus one');
    }

    public function testNoSideFitsThenTheRoomierOfBelowAndAbove(): void
    {
        $below = TooltipPlacement::place(new Tooltip('k', 't', 2, 30, 40), 60, 9, [1, 0, 10, 60]);
        $this->assertSame([TooltipSide::Below, 1], [$below->side, $below->top], 'clamped up so the card stays on screen');

        $above = TooltipPlacement::place(new Tooltip('k', 't', 8, 30, 40), 60, 9, [1, 0, 10, 60]);
        $this->assertSame([TooltipSide::Above, 1], [$above->side, $above->top]);
    }

    /** Equal room above and below: below, where the eye already is. */
    public function testATieGoesBelow(): void
    {
        $this->assertSame(TooltipSide::Below, TooltipPlacement::place(new Tooltip('k', 't', 5, 30, 31), 60, 9, [1, 0, 10, 60])->side);
    }

    public function testBesideNeedsTheCardToFitTheHeight(): void
    {
        $this->assertNotSame(TooltipSide::Right, TooltipPlacement::place(new Tooltip('k', 't', 3, 10, 12), 11, 7, [1, 0, 7, 80])->side);
    }

    public function testNearAnEdgeTheCardSlidesIn(): void
    {
        $this->assertSame(0, TooltipPlacement::place(new Tooltip('k', 't', 5, 1, 2), 11, 3, self::BOUNDS)->col);
        $this->assertSame(69, TooltipPlacement::place(new Tooltip('k', 't', 5, 78, 79), 11, 3, self::BOUNDS)->col);
    }

    public function testAnEvenSpanCentresOnItsLeftMiddle(): void
    {
        $this->assertSame(36, TooltipPlacement::place(new Tooltip('k', 't', 5, 40, 44), 11, 3, self::BOUNDS)->col, 'middle column 41');
    }
}
