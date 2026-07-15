<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Terminal;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\Terminal\TerminalKeyEncoder;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

final class TerminalKeyEncoderTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function kittyCases(): iterable
    {
        yield 'key release is dropped' => ["\x1b[97;1:3u", ''];
        yield 'unrecognised CSI-u dropped' => ["\x1b[57444u", '']; // e.g. a media key
        yield 'mouse sequence passes through' => ["\x1b[<0;5;5M", "\x1b[<0;5;5M"];
        yield 'plain letter' => ['a', 'a'];
        yield 'shift+letter uppercases' => ["\x1b[97;2u", 'A'];
        yield 'shift+digit stays' => ["\x1b[49;2u", '1'];
        yield 'ctrl+letter → control byte' => ["\x1b[99;5u", "\x03"]; // ctrl+c
        yield 'ctrl+[ → escape' => ["\x1b[91;5u", "\x1b"];
        yield 'ctrl+@ → NUL' => ["\x1b[64;5u", "\x00"];
        yield 'ctrl+backslash → FS' => ["\x1b[92;5u", "\x1c"];
        yield 'ctrl+] → GS' => ["\x1b[93;5u", "\x1d"];
        yield 'ctrl+^ → RS' => ["\x1b[94;5u", "\x1e"];
        yield 'ctrl+_ → US' => ["\x1b[95;5u", "\x1f"];
        yield 'ctrl+? → DEL' => ["\x1b[63;5u", "\x7f"];
        yield 'ctrl+digit has no control byte' => ["\x1b[49;5u", '']; // ctrl+1 dropped
        yield 'alt+letter → ESC prefix' => ["\x1b[98;3u", "\x1bb"]; // alt+b
        yield 'enter' => ["\x1b[13u", "\r"];
        yield 'alt+enter' => ["\x1b[13;3u", "\x1b\r"];
        yield 'space' => ["\x1b[32u", ' '];
        yield 'ctrl+space → NUL' => ["\x1b[32;5u", "\x00"];
        yield 'escape' => ["\x1b[27u", "\x1b"];
        yield 'backspace' => ["\x1b[127u", "\x7f"];
        yield 'tab' => ["\t", "\t"];
        yield 'shift+tab' => ["\x1b[Z", "\x1b[Z"];
        yield 'alt+tab' => ["\x1b[9;3u", "\x1b\t"];
        yield 'up arrow round-trips' => ["\x1b[A", "\x1b[A"];
        yield 'ctrl+right (modified arrow)' => ["\x1b[1;5C", "\x1b[1;5C"];
        yield 'home' => ["\x1b[H", "\x1b[H"];
        yield 'delete' => ["\x1b[3~", "\x1b[3~"];
        yield 'shift+delete (modified tilde)' => ["\x1b[3;2~", "\x1b[3;2~"];
    }

    #[DataProvider('kittyCases')]
    public function testEncodesWhenKittyActive(string $input, string $expected): void
    {
        $encoder = new TerminalKeyEncoder(new Tui(terminal: new VirtualTerminal(kittyProtocolActive: true)));

        $this->assertSame($expected, $encoder->encode($input));
    }

    public function testForwardsUnchangedWhenKittyInactive(): void
    {
        $encoder = new TerminalKeyEncoder(new Tui(terminal: new VirtualTerminal()));

        // Nothing is translated or dropped on a legacy terminal.
        $this->assertSame("\x1b[97;1:3u", $encoder->encode("\x1b[97;1:3u"));
        $this->assertSame('a', $encoder->encode('a'));
    }
}
