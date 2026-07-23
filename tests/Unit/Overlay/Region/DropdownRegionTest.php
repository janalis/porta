<?php

declare(strict_types=1);

namespace Porta\Tests\Overlay\Region;

use PHPUnit\Framework\TestCase;
use Porta\Overlay\Region\DropdownRegion;

/**
 * The dropdown hit-test geometry, framed and bare: contains() means the whole
 * box (borders included) while itemAt() yields only on item cells — the split
 * the mouse router's three-way click (run / swallow / dismiss) rides on.
 */
final class DropdownRegionTest extends TestCase
{
    public function testAnUnframedRegionMapsEveryRowToAnItem(): void
    {
        $region = new DropdownRegion(top: 5, col: 10, width: 8, count: 3);

        $this->assertSame(0, $region->itemAt(5, 10), 'the first row is item 0');
        $this->assertSame(2, $region->itemAt(7, 17), 'the last row and column still hit');
        $this->assertNull($region->itemAt(8, 10), 'below the box');
        $this->assertNull($region->itemAt(5, 18), 'right of the box');
    }

    public function testAFramedRegionYieldsNullOnItsBorderRing(): void
    {
        // 3 items framed: rows 5..9 (top border, 3 items, bottom border),
        // cols 10..17 (rails at 10 and 17).
        $region = new DropdownRegion(top: 5, col: 10, width: 8, count: 5, framed: true);

        $this->assertNull($region->itemAt(5, 12), 'top border row');
        $this->assertNull($region->itemAt(9, 12), 'bottom border row');
        $this->assertNull($region->itemAt(6, 10), 'left rail');
        $this->assertNull($region->itemAt(6, 17), 'right rail');
        $this->assertSame(0, $region->itemAt(6, 11), 'first item, one row below the top border');
        $this->assertSame(2, $region->itemAt(8, 16), 'last item, one column inside the rail');
        $this->assertNull($region->itemAt(4, 12), 'outside stays outside');
    }

    /** contains() keeps meaning the whole box — the border ring included. */
    public function testContainsCoversTheBordersItemAtExcludes(): void
    {
        $region = new DropdownRegion(top: 5, col: 10, width: 8, count: 5, framed: true);

        $this->assertTrue($region->contains(5, 10), 'the top-left corner is inside the box');
        $this->assertTrue($region->contains(9, 17), 'the bottom-right corner too');
        $this->assertFalse($region->contains(10, 10), 'one row below is not');
        $this->assertTrue($region->coversRow(9));
        $this->assertFalse($region->coversRow(10));
    }
}
