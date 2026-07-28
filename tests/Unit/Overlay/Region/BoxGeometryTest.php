<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Overlay\Region;

use PHPUnit\Framework\TestCase;
use Porta\Overlay\Region\BoxPosition;
use Porta\Overlay\Region\BoxSize;
use Porta\Overlay\Region\ModalBox;

final class BoxGeometryTest extends TestCase
{
    public function testPositionAndSizeCarryTheirPairs(): void
    {
        $position = new BoxPosition(5, 12);
        $this->assertSame([5, 12], [$position->row, $position->col]);

        $size = new BoxSize(80, 9);
        $this->assertSame([80, 9], [$size->width, $size->rows]);
    }

    public function testModalBoxChromeRowsDefaultToAbsent(): void
    {
        $box = new ModalBox(2, 4, 40, 10, headerRows: 1, windowStart: 0, count: 8);
        $this->assertSame(-1, $box->optionsRow, 'a box without an options row says so');
        $this->assertSame(-1, $box->footerRow);
    }
}
