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
        $line = MatchRowDecorator::highlight('hello', 5, "\x1b[48;5;1m", 0, "\x1b[48;5;2m", "\x1b[49m", []);

        $this->assertSame("\x1b[48;5;1m".'hello'.Sgr::RESET, $line, 'bg opens, text, then RESET closes — in that exact order');
    }

    public function testTheBgPrefixComesBeforeTheTextNotAfter(): void
    {
        $line = MatchRowDecorator::highlight('ab', 2, 'BG', 0, 'S', 'C', []);

        $this->assertStringStartsWith('BGab', $line);
    }

    public function testTheResetSuffixComesAfterTheTextNotBeforeTheBg(): void
    {
        $line = MatchRowDecorator::highlight('ab', 2, 'BG', 0, 'S', 'C', []);

        $this->assertSame('BGab'.Sgr::RESET, $line);
    }
}
