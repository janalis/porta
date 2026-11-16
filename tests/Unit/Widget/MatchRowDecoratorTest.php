<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget;

use PHPUnit\Framework\TestCase;
use Porta\Ui\Sgr;
use Porta\Widget\MatchRowDecorator;

final class MatchRowDecoratorTest extends TestCase
{
    public function testWithNoMatchRangesTheRowIsExactlyBgThenTruncatedTextThenReset(): void
    {
        $line = MatchRowDecorator::highlight('hello', width: 5, bg: "\x1b[48;5;1m", prefixWidth: 0, searchBg: "\x1b[48;5;2m", searchClose: "\x1b[49m", ranges: []);

        $this->assertSame("\x1b[48;5;1m".'hello'.Sgr::RESET, $line, 'bg opens, text, then RESET closes — in that exact order');
    }

    public function testTheBgPrefixComesBeforeTheTextNotAfter(): void
    {
        $line = MatchRowDecorator::highlight('ab', width: 2, bg: 'BG', prefixWidth: 0, searchBg: 'S', searchClose: 'C', ranges: []);

        $this->assertStringStartsWith('BGab', $line);
    }

    public function testTheResetSuffixComesAfterTheTextNotBeforeTheBg(): void
    {
        $line = MatchRowDecorator::highlight('ab', width: 2, bg: 'BG', prefixWidth: 0, searchBg: 'S', searchClose: 'C', ranges: []);

        $this->assertSame('BGab'.Sgr::RESET, $line);
    }
}
