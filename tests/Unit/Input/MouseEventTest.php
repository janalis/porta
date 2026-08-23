<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Input;

use PHPUnit\Framework\TestCase;
use Porta\Input\MouseEvent;

final class MouseEventTest extends TestCase
{
    public function testLeftPress(): void
    {
        $e = MouseEvent::parse("\x1b[<0;10;5M");

        $this->assertNotNull($e);
        $this->assertSame(MouseEvent::LEFT, $e->button);
        $this->assertSame(9, $e->col, '1-based 10 -> 0-based 9');
        $this->assertSame(4, $e->row);
        $this->assertTrue($e->pressed);
        $this->assertFalse($e->wheel);
        $this->assertTrue($e->isLeftPress());
    }

    public function testRelease(): void
    {
        $e = MouseEvent::parse("\x1b[<0;10;5m");

        $this->assertNotNull($e);
        $this->assertFalse($e->pressed);
        $this->assertFalse($e->isLeftPress());
    }

    public function testWheelUpAndDown(): void
    {
        $up = MouseEvent::parse("\x1b[<64;3;3M");
        $this->assertTrue($up?->wheel);
        $this->assertSame(-1, $up?->wheelDelta);
        $this->assertSame(0, $up?->wheelHDelta, 'a vertical wheel carries no horizontal delta');
        $this->assertFalse($up->isLeftPress());

        $down = MouseEvent::parse("\x1b[<65;3;3M");
        $this->assertTrue($down?->wheel);
        $this->assertSame(1, $down?->wheelDelta);
        $this->assertSame(0, $down?->wheelHDelta);
    }

    public function testHorizontalWheelDecodes(): void
    {
        $left = MouseEvent::parse("\x1b[<66;3;3M");
        $this->assertTrue($left?->wheel);
        $this->assertSame(-1, $left?->wheelHDelta, 'code 66 = wheel left');
        $this->assertSame(0, $left?->wheelDelta, 'horizontal must not leak into the vertical delta');

        $right = MouseEvent::parse("\x1b[<67;3;3M");
        $this->assertSame(1, $right?->wheelHDelta, 'code 67 = wheel right');
        $this->assertSame(0, $right?->wheelDelta);
    }

    public function testShiftVerticalWheelMapsToHorizontal(): void
    {
        $left = MouseEvent::parse("\x1b[<68;3;3M"); // shift + wheel up
        $this->assertTrue($left?->wheel);
        $this->assertTrue($left?->shift);
        $this->assertSame(-1, $left?->wheelHDelta, 'shift+up scrolls left (mouse convention)');
        $this->assertSame(0, $left?->wheelDelta);

        $right = MouseEvent::parse("\x1b[<69;3;3M"); // shift + wheel down
        $this->assertSame(1, $right?->wheelHDelta, 'shift+down scrolls right');
        $this->assertSame(0, $right?->wheelDelta);
    }

    public function testMotionAndRightButton(): void
    {
        $motion = MouseEvent::parse("\x1b[<32;3;3M");
        $this->assertTrue($motion?->motion);
        $this->assertFalse($motion->isLeftPress(), 'drag motion is not a plain left press');

        $right = MouseEvent::parse("\x1b[<2;5;5M");
        $this->assertSame(MouseEvent::RIGHT, $right?->button);
        $this->assertFalse($right->isLeftPress());
    }

    public function testRightPress(): void
    {
        $press = MouseEvent::parse("\x1b[<2;5;5M");
        $this->assertTrue($press->isRightPress());
        $this->assertFalse($press->isLeftPress());

        // A release, a right-drag, a wheel and a left press are not right presses.
        $this->assertFalse(MouseEvent::parse("\x1b[<2;5;5m")->isRightPress(), 'a release is not a press');
        $this->assertFalse(MouseEvent::parse("\x1b[<34;5;5M")->isRightPress(), 'a right drag (motion) is not a press');
        $this->assertFalse(MouseEvent::parse("\x1b[<64;5;5M")->isRightPress(), 'a wheel is not a right press');
        $this->assertFalse(MouseEvent::parse("\x1b[<0;5;5M")->isRightPress(), 'a left press is not a right press');
    }

    public function testLeftDrag(): void
    {
        $drag = MouseEvent::parse("\x1b[<32;3;3M"); // left button held + motion
        $this->assertTrue($drag->isLeftDrag());
        $this->assertFalse($drag->isLeftPress());
        $this->assertFalse($drag->isLeftRelease());
        $this->assertFalse($drag->isMove(), 'a left drag holds a button, so it is not a bare move');

        // A plain press, a release and a wheel are not drags.
        $this->assertFalse(MouseEvent::parse("\x1b[<0;3;3M")->isLeftDrag());
        $this->assertFalse(MouseEvent::parse("\x1b[<0;3;3m")->isLeftDrag());
        $this->assertFalse(MouseEvent::parse("\x1b[<64;3;3M")->isLeftDrag());
    }

    public function testMove(): void
    {
        // Mode 1003 reports a no-button motion as code 35 (32 motion + 3 "no button").
        $move = MouseEvent::parse("\x1b[<35;7;4M");
        $this->assertNotNull($move);
        $this->assertSame(3, $move->button, 'no button held → button bits 3');
        $this->assertTrue($move->isMove());
        $this->assertFalse($move->isLeftDrag());
        $this->assertFalse($move->isLeftPress());

        // A left drag, a plain press and a wheel are not bare moves.
        $this->assertFalse(MouseEvent::parse("\x1b[<32;7;4M")->isMove(), 'a held-button drag is not a move');
        $this->assertFalse(MouseEvent::parse("\x1b[<0;7;4M")->isMove(), 'a press is not a move');
        $this->assertFalse(MouseEvent::parse("\x1b[<67;7;4M")->isMove(), 'a wheel event is not a move');
    }

    public function testLeftRelease(): void
    {
        $release = MouseEvent::parse("\x1b[<0;3;3m");
        $this->assertTrue($release->isLeftRelease());
        $this->assertFalse($release->isLeftPress());
        $this->assertFalse($release->isLeftDrag());

        $this->assertFalse(MouseEvent::parse("\x1b[<0;3;3M")->isLeftRelease(), 'a press is not a release');
        $this->assertFalse(MouseEvent::parse("\x1b[<32;3;3M")->isLeftRelease(), 'a drag is not a release');
    }

    public function testShiftModifierDecoded(): void
    {
        $shiftClick = MouseEvent::parse("\x1b[<4;7;2M"); // 0 (left) + 4 (shift)
        $this->assertTrue($shiftClick?->shift);
        $this->assertSame(MouseEvent::LEFT, $shiftClick?->button, 'the shift bit does not corrupt the button');
        $this->assertTrue($shiftClick->isLeftPress());

        $this->assertFalse(MouseEvent::parse("\x1b[<0;7;2M")?->shift);
    }

    public function testAltModifierDecoded(): void
    {
        $altClick = MouseEvent::parse("\x1b[<8;7;2M"); // 0 (left) + 8 (alt)
        $this->assertTrue($altClick?->alt);
        $this->assertFalse($altClick?->shift);
        $this->assertSame(MouseEvent::LEFT, $altClick?->button, 'the alt bit does not corrupt the button');
        $this->assertTrue($altClick->isLeftPress());

        $altShift = MouseEvent::parse("\x1b[<12;7;2M"); // 0 + 4 (shift) + 8 (alt)
        $this->assertTrue($altShift?->alt);
        $this->assertTrue($altShift?->shift);

        $this->assertFalse(MouseEvent::parse("\x1b[<0;7;2M")?->alt);
    }

    public function testCtrlModifierDecoded(): void
    {
        $ctrlClick = MouseEvent::parse("\x1b[<16;7;2M"); // 0 (left) + 16 (ctrl)
        $this->assertTrue($ctrlClick?->ctrl);
        $this->assertFalse($ctrlClick?->shift);
        $this->assertFalse($ctrlClick?->alt);
        $this->assertSame(MouseEvent::LEFT, $ctrlClick?->button, 'the ctrl bit does not corrupt the button');
        $this->assertTrue($ctrlClick->isLeftPress());

        $ctrlAltShift = MouseEvent::parse("\x1b[<28;7;2M"); // 0 + 4 (shift) + 8 (alt) + 16 (ctrl)
        $this->assertTrue($ctrlAltShift?->ctrl);
        $this->assertTrue($ctrlAltShift?->alt);
        $this->assertTrue($ctrlAltShift?->shift);

        $this->assertFalse(MouseEvent::parse("\x1b[<0;7;2M")?->ctrl);
    }

    public function testCoordinatesClampAtOrigin(): void
    {
        $e = MouseEvent::parse("\x1b[<0;0;0M"); // degenerate 0 coords
        $this->assertSame(0, $e?->col);
        $this->assertSame(0, $e?->row);
    }

    public function testNonMouseInputReturnsNull(): void
    {
        $this->assertNull(MouseEvent::parse('x'));
        $this->assertNull(MouseEvent::parse("\x1b[A"));
        $this->assertNull(MouseEvent::parse("\x1b[<0;10;5")); // no final byte
        $this->assertNull(MouseEvent::parse("\x1b[<0;10;5Mtail")); // trailing junk
        $this->assertNull(MouseEvent::parse("x\x1b[<0;10;5M"), 'a valid sequence must start at position 0, not appear anywhere in the string');
    }

    public function testWheelButtonIsExactlyNegativeOne(): void
    {
        $up = MouseEvent::parse("\x1b[<64;3;3M");

        $this->assertSame(-1, $up?->button);
    }

    public function testMiddlePressWithNoModifierBitsSetsNoneOfThem(): void
    {
        // Code 1 = middle button, all higher bits (motion/shift/alt/ctrl) clear.
        // Bit 0 alone must not leak into any of the higher single-bit checks.
        $e = MouseEvent::parse("\x1b[<1;5;5M");

        $this->assertSame(MouseEvent::MIDDLE, $e?->button);
        $this->assertFalse($e?->motion);
        $this->assertFalse($e?->shift);
        $this->assertFalse($e?->alt);
        $this->assertFalse($e?->ctrl);
    }
}
