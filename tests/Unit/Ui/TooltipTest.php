<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;
use Porta\Ui\Tooltip;

final class TooltipTest extends TestCase
{
    public function testMovedCarriesAWidgetsAnswerToTheScreen(): void
    {
        $moved = new Tooltip('find:regex', 'Regular expression', 1, 30, 34, rows: 3)->moved(20, 5);

        $this->assertSame(['find:regex', 'Regular expression', 21, 35, 39, 3], [$moved->key, $moved->text, $moved->row, $moved->colStart, $moved->colEnd, $moved->rows]);
    }
}
