<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Overlay\Region;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\Overlay\Region\CloseButton;
use Porta\Overlay\Region\ModalBox;
use Porta\Overlay\Region\WindowZone;

/**
 * The pooled modal geometry: item mapping under the header rows, the framed
 * content's column shift, the close zone, and the box-as-region round trip.
 */
final class ModalBoxTest extends TestCase
{
    /** A 20×6 box at (4,10): title bar, one query row, then a 3-row item window. */
    private function box(): ModalBox
    {
        return new ModalBox(startRow: 4, col: 10, width: 20, rows: 6, headerRows: 2, windowStart: 5, count: 3);
    }

    public function testContainsCoversTheWholeBoxBordersIncluded(): void
    {
        $box = $this->box();

        $this->assertTrue($box->contains(4, 10), 'top-left corner');
        $this->assertTrue($box->contains(9, 29), 'bottom-right corner');
        $this->assertFalse($box->contains(3, 10), 'above');
        $this->assertFalse($box->contains(10, 10), 'below');
        $this->assertFalse($box->contains(4, 9), 'left of');
        $this->assertFalse($box->contains(4, 30), 'right of');
    }

    public function testItemAtMapsWindowRowsToAbsoluteIndices(): void
    {
        $box = $this->box();

        $this->assertSame(5, $box->itemAt(6, 15), 'first window row → windowStart');
        $this->assertSame(7, $box->itemAt(8, 15), 'last window row');
        $this->assertNull($box->itemAt(5, 15), 'the header rows are not items');
        $this->assertNull($box->itemAt(9, 15), 'the bottom border is not an item');
        $this->assertNull($box->itemAt(6, 9), 'outside the box columns');
        $this->assertNull($box->itemAt(6, 30), 'outside on the right');
        $this->assertNull($box->itemAt(6, 10), 'the left rail is a resize handle, not the row');
        $this->assertNull($box->itemAt(6, 29), 'the right rail neither');
        $this->assertSame(5, $box->itemAt(6, 11), 'the first column inside the rail is the row');
        $this->assertSame(5, $box->itemAt(6, 28), 'and the last');
    }

    /**
     * Every frame cell answers the handle it is, and nothing else does: the
     * box spans rows 4-9 and columns 10-29.
     *
     * @return iterable<string, array{int, int, WindowZone}>
     */
    public static function zones(): iterable
    {
        yield 'the button\'s ]' => [4, 28, WindowZone::Close];
        yield 'the button\'s ✖' => [4, 27, WindowZone::Close];
        yield 'the button\'s [' => [4, 26, WindowZone::Close];
        yield 'the title bar' => [4, 15, WindowZone::Title];
        yield 'the title bar just short of the button' => [4, 25, WindowZone::Title];
        yield 'the top-left corner' => [4, 10, WindowZone::TopLeft];
        yield 'the top-right corner' => [4, 29, WindowZone::TopRight];
        yield 'the left rail' => [6, 10, WindowZone::Left];
        yield 'the right rail' => [6, 29, WindowZone::Right];
        yield 'the bottom border' => [9, 15, WindowZone::Bottom];
        yield 'the bottom-left corner' => [9, 10, WindowZone::BottomLeft];
        yield 'the bottom-right corner' => [9, 29, WindowZone::BottomRight];
        yield 'inside, next to the left rail' => [5, 11, WindowZone::Content];
        yield 'inside, next to the right rail' => [8, 28, WindowZone::Content];
        yield 'left of the box' => [6, 9, WindowZone::Outside];
        yield 'right of the box' => [6, 30, WindowZone::Outside];
        yield 'above the box' => [3, 15, WindowZone::Outside];
        yield 'below the box' => [10, 15, WindowZone::Outside];
    }

    #[DataProvider('zones')]
    public function testEveryCellAnswersItsZone(int $row, int $col, WindowZone $zone): void
    {
        $this->assertSame($zone, $this->box()->zoneAt($row, $col));
    }

    public function testInnerColShiftsPastTheRailAndPaddingColumn(): void
    {
        $this->assertSame(0, $this->box()->innerCol(12), 'first content column');
        $this->assertSame(4, $this->box()->innerCol(16));
    }

    /** The whole `[✖]`, on the title row only; the corner stays out. */
    public function testCloseHitCoversTheCloseZone(): void
    {
        $box = $this->box();

        $this->assertTrue($box->closeHit(4, 28), 'the ] (width − 2)');
        $this->assertTrue($box->closeHit(4, 27), 'the ✖');
        $this->assertTrue($box->closeHit(4, 26), 'the [ (width − 4)');
        $this->assertFalse($box->closeHit(4, 29), 'the corner glyph');
        $this->assertFalse($box->closeHit(4, 25), 'the rule before it');
        $this->assertFalse($box->closeHit(5, 28), 'not on the title row');
    }

    public function testRegionMirrorsTheFullBox(): void
    {
        $region = $this->box()->region();

        $this->assertSame(4, $region->top);
        $this->assertSame(10, $region->col);
        $this->assertSame(20, $region->width);
        $this->assertSame(6, $region->count);
    }

    /**
     * On a left-close platform the (✖) sits just inside the top-left corner;
     * the right end of the title bar is plain title bar again.
     *
     * @return iterable<string, array{int, int, WindowZone}>
     */
    public static function leftCloseZones(): iterable
    {
        yield 'the button\'s (' => [4, 11, WindowZone::Close];
        yield 'the button\'s ✖' => [4, 12, WindowZone::Close];
        yield 'the button\'s )' => [4, 13, WindowZone::Close];
        yield 'the title past it' => [4, 14, WindowZone::Title];
        yield 'the top-left corner' => [4, 10, WindowZone::TopLeft];
        yield 'where the [✖] would be on the right' => [4, 28, WindowZone::Title];
    }

    #[DataProvider('leftCloseZones')]
    public function testALeftCloseSitsInsideTheTopLeftCorner(int $row, int $col, WindowZone $zone): void
    {
        $box = new ModalBox(startRow: 4, col: 10, width: 20, rows: 6, headerRows: 2, windowStart: 5, count: 3, closeButton: CloseButton::Mac);

        $this->assertSame($zone, $box->zoneAt($row, $col));
    }
}
