<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Overlay\Region;

use PHPUnit\Framework\TestCase;
use Porta\Overlay\Region\ModalBox;

final class BoxGeometryTest extends TestCase
{
    public function testModalBoxChromeRowsDefaultToAbsent(): void
    {
        $box = new ModalBox(2, 4, 40, 10, headerRows: 1, windowStart: 0, count: 8);
        $this->assertSame(-1, $box->optionsRow, 'a box without an options row says so');
        $this->assertSame(-1, $box->footerRow);
    }
}
