<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Porta\Support\ControlChars;

/** Someone else's text reaches the terminal with nothing the terminal would act on. */
final class ControlCharsTest extends TestCase
{
    public function testALineKeepsNoControlCharacterAndNoBidiOverride(): void
    {
        $this->assertSame('a ]52;c;Zm9v b', ControlChars::line("a\x1b]52;c;Zm9v\x07b"), 'an OSC 52 clipboard write is defused');
        $this->assertSame('x y', ControlChars::line("x\u{202E}y"));
        $this->assertSame('one two', ControlChars::line("  one\ntwo\t "));
        $this->assertSame('bad? byte', ControlChars::line("bad\xff byte"), 'invalid UTF-8 cannot defeat the pattern');
    }

    public function testAPageKeepsItsLinesAndTabsAndNothingElse(): void
    {
        $this->assertSame("x\ny[2J\tzw\n", ControlChars::page("x\r\ny\x1b[2J\tz\x07\u{2066}w\n"));
        $this->assertSame('é', ControlChars::page('é'), 'letters are not controls');
    }
}
