<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Porta\Support\AnsiText;

final class AnsiTextTest extends TestCase
{
    public function testPadsWithTrailingSpacesToReachTheWidth(): void
    {
        $this->assertSame('ab   ', AnsiText::padRight('ab', 5));
    }

    public function testIsANoOpWhenAlreadyAtLeastAsWide(): void
    {
        $this->assertSame('abcd', AnsiText::padRight('abcd', 4));
        $this->assertSame('abcdef', AnsiText::padRight('abcdef', 4));
    }

    public function testCountsVisibleWidthOnlyIgnoringSgrCodes(): void
    {
        // "ab" styled is two visible columns, so it pads to five.
        $this->assertSame("\x1b[31mab\x1b[0m   ", AnsiText::padRight("\x1b[31mab\x1b[0m", 5));
    }
}
