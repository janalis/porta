<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Overlay;

use PHPUnit\Framework\TestCase;
use Porta\Overlay\ModalBox;

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
    }

    public function testInnerColShiftsPastTheRailAndPaddingColumn(): void
    {
        $this->assertSame(0, $this->box()->innerCol(12), 'first content column');
        $this->assertSame(4, $this->box()->innerCol(16));
    }

    /** The `×` and the space before it, on the title row only; the corner stays out. */
    public function testCloseHitCoversTheCloseZone(): void
    {
        $box = $this->box();

        $this->assertTrue($box->closeHit(4, 28), 'the × itself (width − 2)');
        $this->assertTrue($box->closeHit(4, 27), 'the space before it');
        $this->assertFalse($box->closeHit(4, 29), 'the corner glyph');
        $this->assertFalse($box->closeHit(4, 26), 'the hint area');
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
}
