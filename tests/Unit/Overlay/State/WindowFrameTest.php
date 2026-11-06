<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Overlay\State;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\Overlay\Region\ModalBox;
use Porta\Overlay\Region\WindowGeometry;
use Porta\Overlay\Region\WindowPlacement;
use Porta\Overlay\Region\WindowZone;
use Porta\Overlay\State\WindowFrame;
use Porta\Tests\Support\Probe;

/**
 * The one move-and-resize state machine every floating window shares. The
 * bounds are rows 2-21 and columns 0-79 (a menu bar above, a status bar
 * below); the default box is 40 × 10, floored at 20 × 6.
 */
final class WindowFrameTest extends TestCase
{
    private const array BOUNDS = [2, 0, 22, 80];

    private function place(WindowFrame $frame, WindowPlacement $placement = WindowPlacement::Centered): WindowGeometry
    {
        return $frame->place(self::BOUNDS, 40, 10, $placement, minWidth: 20, minRows: 6);
    }

    private static function box(WindowGeometry $g): ModalBox
    {
        return new ModalBox($g->top, $g->col, $g->width, $g->rows, headerRows: 2, windowStart: 0, count: $g->rows - 4);
    }

    /** Place, press at ($row, $col) on the drawn box, drag to ($toRow, $toCol), release, place again. */
    private function drag(WindowFrame $frame, int $row, int $col, int $toRow, int $toCol): WindowGeometry
    {
        $frame->press(self::box($this->place($frame)), $row, $col);
        $this->assertTrue($frame->dragTo($toRow, $toCol, release: true));

        return $this->place($frame);
    }

    public function testCenteredOpensInTheMiddleOfTheBounds(): void
    {
        $g = $this->place(new WindowFrame());

        $this->assertSame([7, 20, 40, 10], [$g->top, $g->col, $g->width, $g->rows], '(20 - 10) / 2 below the top, (80 - 40) / 2 across');
        $this->assertSame(17, $g->bottom());
        $this->assertSame(60, $g->right());
    }

    public function testUpperThirdOpensAThirdOfTheWayDown(): void
    {
        $g = $this->place(new WindowFrame(), WindowPlacement::UpperThird);

        $this->assertSame([5, 20], [$g->top, $g->col], '(20 - 10) / 3 below the top, centred across');
    }

    public function testTheDefaultSizeIsFlooredAndCappedAtTheBounds(): void
    {
        $frame = new WindowFrame();

        $small = $frame->place(self::BOUNDS, 5, 2, WindowPlacement::Centered, minWidth: 20, minRows: 6);
        $this->assertSame([20, 6], [$small->width, $small->rows], 'floored at the minimum');

        $huge = $frame->place(self::BOUNDS, 500, 500, WindowPlacement::Centered, minWidth: 20, minRows: 6);
        $this->assertSame([2, 0, 80, 20], [$huge->top, $huge->col, $huge->width, $huge->rows], 'capped at the bounds, and so placed at their corner');

        $cramped = $frame->place([0, 0, 3, 10], 40, 10, WindowPlacement::Centered, minWidth: 20, minRows: 6);
        $this->assertSame([10, 3], [$cramped->width, $cramped->rows], 'bounds smaller than the floor win: nothing may paint outside them');
    }

    public function testATitleDragMovesTheBoxWithTheGrabOffset(): void
    {
        $frame = new WindowFrame();
        $g = $this->place($frame);

        $this->assertSame(WindowZone::Title, $frame->press(self::box($g), 7, 30));
        $this->assertNotNull(Probe::get($frame, 'dragZone'));
        $this->assertTrue($frame->dragTo(9, 25, release: false));
        $moved = $this->place($frame);

        $this->assertSame([9, 15, 40, 10], [$moved->top, $moved->col, $moved->width, $moved->rows], 'two down, five left, same size');
        $this->assertNotNull(Probe::get($frame, 'dragZone'), 'still held until the release');
        $this->assertNull($frame->hot(), 'a move lights no handle');
    }

    public function testAMovedBoxClampsIntoTheBoundsAndStaysWhereTheClampPutIt(): void
    {
        $frame = new WindowFrame();
        $moved = $this->drag($frame, 7, 30, 500, 500);

        $this->assertSame([12, 40], [$moved->top, $moved->col], 'pinned to the bottom-right: 22 - 10, 80 - 40');

        $narrower = $frame->place([2, 0, 22, 60], 40, 10, WindowPlacement::Centered, minWidth: 20, minRows: 6);
        $this->assertSame(20, $narrower->col, 'a narrower terminal pushed it in');

        $wider = $this->place($frame);
        $this->assertSame(20, $wider->col, 'and it stays there rather than springing back');
    }

    public function testAnUnmovedBoxFollowsItsPlacementAcrossResizes(): void
    {
        $frame = new WindowFrame();
        $this->place($frame);

        $g = $frame->place([2, 0, 42, 120], 40, 10, WindowPlacement::Centered);

        $this->assertSame([17, 40], [$g->top, $g->col], 're-centred in the larger bounds');
    }

    /**
     * Each handle moves only its own edges; the opposite edges stay put.
     * The box starts at rows 7-16, columns 20-59.
     *
     * @return iterable<string, array{int, int, int, int, list<int>}>
     */
    public static function handles(): iterable
    {
        // [press row, press col, drag rows, drag cols, [top, col, width, rows]]
        yield 'right edge' => [10, 59, 3, 5, [7, 20, 45, 10]];
        yield 'left edge' => [10, 20, 3, 5, [7, 25, 35, 10]];
        yield 'bottom edge' => [16, 30, 2, 9, [7, 20, 40, 12]];
        yield 'bottom-right corner' => [16, 59, 2, 5, [7, 20, 45, 12]];
        yield 'bottom-left corner' => [16, 20, 2, -5, [7, 15, 45, 12]];
        yield 'top-right corner' => [7, 59, -3, 5, [4, 20, 45, 13]];
        yield 'top-left corner' => [7, 20, 2, 4, [9, 24, 36, 8]];
    }

    /** @param list<int> $expected */
    #[DataProvider('handles')]
    public function testEachHandleResizesOnlyItsOwnEdges(int $row, int $col, int $rows, int $cols, array $expected): void
    {
        $g = $this->drag(new WindowFrame(), $row, $col, $row + $rows, $col + $cols);

        $this->assertSame($expected, [$g->top, $g->col, $g->width, $g->rows]);
    }

    public function testAResizeStopsAtTheFloorWithoutPushingTheFarEdge(): void
    {
        $left = $this->drag(new WindowFrame(), 10, 20, 10, 500);
        $this->assertSame([40, 20], [$left->col, $left->width], 'floored at 20 wide; the right edge (60) stays put');

        $top = $this->drag(new WindowFrame(), 7, 20, 500, 20);
        $this->assertSame([11, 6], [$top->top, $top->rows], 'floored at 6 rows; the bottom edge (17) stays put');

        $right = $this->drag(new WindowFrame(), 10, 59, 10, -500);
        $this->assertSame([20, 20], [$right->col, $right->width], 'floored at 20 wide; the left edge stays put');

        $bottom = $this->drag(new WindowFrame(), 16, 30, -500, 30);
        $this->assertSame([7, 6], [$bottom->top, $bottom->rows], 'floored at 6 rows; the top edge stays put');
    }

    public function testALeftOrTopResizeStopsAtTheBoundsWithoutPushingTheFarEdge(): void
    {
        $left = $this->drag(new WindowFrame(), 10, 20, 10, -500);
        $this->assertSame([0, 60], [$left->col, $left->width], 'grown to the left bound; the right edge (60) stays put');

        $top = $this->drag(new WindowFrame(), 7, 20, -500, 20);
        $this->assertSame([2, 15], [$top->top, $top->rows], 'grown to the top bound; the bottom edge (17) stays put');
    }

    public function testAResizePastTheFarBoundsIsCappedAtThem(): void
    {
        $g = $this->drag(new WindowFrame(), 16, 59, 500, 500);

        $this->assertSame([2, 0, 80, 20], [$g->top, $g->col, $g->width, $g->rows], 'the whole bounds, and no further');
    }

    public function testAResizedSizeComesBackWhenTheBoundsDo(): void
    {
        $frame = new WindowFrame();
        $this->drag($frame, 16, 59, 18, 69);

        $cramped = $frame->place([2, 0, 12, 40], 40, 10, WindowPlacement::Centered, minWidth: 20, minRows: 6);
        $this->assertSame([40, 10], [$cramped->width, $cramped->rows], 'capped while the terminal is small');

        $roomy = $this->place($frame);
        $this->assertSame([50, 12], [$roomy->width, $roomy->rows], 'the user size survives the squeeze');
    }

    public function testTheReleaseEndsTheDragAndCancelDropsIt(): void
    {
        $frame = new WindowFrame();
        $this->drag($frame, 7, 30, 8, 30);
        $this->assertNull(Probe::get($frame, 'dragZone'));
        $this->assertFalse($frame->dragTo(12, 12, release: false), 'nothing armed after the release');

        $frame->press(self::box($this->place($frame)), 8, 30);
        $frame->cancel();
        $this->assertFalse($frame->dragTo(12, 12, release: false), 'a fresh press elsewhere disarmed it');
    }

    public function testNothingIsArmedBeforeTheBoxIsPlaced(): void
    {
        $frame = new WindowFrame();

        $zone = $frame->press(new ModalBox(7, 20, 40, 10, headerRows: 2, windowStart: 0, count: 6), 7, 30);

        $this->assertSame(WindowZone::Title, $zone, 'the zone is still answered');
        $this->assertNull(Probe::get($frame, 'dragZone'), 'but with no placement there is nothing to move');
    }

    /** @return iterable<string, array{int, int, WindowZone}> */
    public static function inert(): iterable
    {
        yield 'the ✖' => [7, 58, WindowZone::Close];
        yield 'the content' => [10, 30, WindowZone::Content];
        yield 'outside' => [0, 0, WindowZone::Outside];
    }

    #[DataProvider('inert')]
    public function testPressesOffTheHandlesArmNothing(int $row, int $col, WindowZone $zone): void
    {
        $frame = new WindowFrame();

        $this->assertSame($zone, $frame->press(self::box($this->place($frame)), $row, $col));
        $this->assertNull(Probe::get($frame, 'dragZone'));
        $this->assertFalse($frame->dragTo(12, 12, release: true));
    }

    public function testResetForgetsThePositionTheSizeAndTheDrag(): void
    {
        $frame = new WindowFrame();
        $this->drag($frame, 16, 59, 18, 69);
        $this->drag($frame, 7, 30, 3, 20);
        $frame->press(self::box($this->place($frame)), 3, 30);
        $frame->hover(self::box($this->place($frame)), 3, 20);

        $frame->reset();

        $g = $this->place($frame);
        $this->assertSame([7, 20, 40, 10], [$g->top, $g->col, $g->width, $g->rows], 'back to the default, centred');
        $this->assertNull(Probe::get($frame, 'dragZone'));
        $this->assertNull($frame->hot());
    }

    public function testHoverLightsTheCloseAndTheResizeHandlesOnly(): void
    {
        $frame = new WindowFrame();
        $box = self::box($this->place($frame));

        $this->assertTrue($frame->hover($box, 7, 58), 'onto the ✖');
        $this->assertSame(WindowZone::Close, $frame->hot());
        $this->assertFalse($frame->hover($box, 7, 57), 'still on the ✖ zone: no change');
        $this->assertTrue($frame->hover($box, 12, 59), 'onto the right edge');
        $this->assertSame(WindowZone::Right, $frame->hot());
        $this->assertTrue($frame->hover($box, 7, 30), 'onto the title bar, which is not lit');
        $this->assertNull($frame->hot());
        $this->assertFalse($frame->hover($box, 10, 30), 'the content is not lit either');
        $this->assertTrue($frame->hover($box, 16, 20), 'the bottom-left corner');
        $this->assertTrue($frame->hover(null, 16, 20), 'no box: nothing lit');
        $this->assertNull($frame->hot());
    }

    public function testTheHandleBeingDraggedStaysLitOffIt(): void
    {
        $frame = new WindowFrame();
        $box = self::box($this->place($frame));
        $frame->press($box, 16, 59);
        $frame->hover($box, 0, 0);

        $this->assertSame(WindowZone::BottomRight, $frame->hot(), 'the pointer outran the corner; the corner stays lit');

        $frame->dragTo(18, 61, release: true);
        $this->assertNull($frame->hot(), 'released: the hover decides again');
    }
}
