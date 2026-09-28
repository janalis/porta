<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Input;

use PHPUnit\Framework\TestCase;
use Porta\Input\TextInput;

final class TextInputTest extends TestCase
{
    public function testPrintableText(): void
    {
        $this->assertTrue(TextInput::isPrintable('a'));
        $this->assertTrue(TextInput::isPrintable('Hello'));
        $this->assertTrue(TextInput::isPrintable('é')); // multibyte printable
    }

    public function testEmptyIsNotPrintable(): void
    {
        $this->assertFalse(TextInput::isPrintable(''));
    }

    public function testEscapeSequenceIsNotPrintable(): void
    {
        $this->assertFalse(TextInput::isPrintable("\x1b[A")); // arrow-up escape
    }

    public function testControlCharactersAreNotPrintable(): void
    {
        $this->assertFalse(TextInput::isPrintable("\n"));
        $this->assertFalse(TextInput::isPrintable("\t"));
        $this->assertFalse(TextInput::isPrintable("\x7f")); // DEL
        $this->assertFalse(TextInput::isPrintable("a\x00b"));
    }

    public function testPastedIsNullForAnythingButABracketedPaste(): void
    {
        $this->assertNull(TextInput::pasted('abc'));
        $this->assertNull(TextInput::pasted("\x1b[D"));
    }

    public function testPastedToleratesAMissingEndMarker(): void
    {
        $this->assertSame('cut short', TextInput::pasted("\x1b[200~cut short"));
    }

    public function testPastedDropsInvalidUtf8(): void
    {
        $this->assertSame('ok', TextInput::pasted("\x1b[200~o\xffk\x1b[201~"));
    }
}
