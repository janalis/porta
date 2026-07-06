<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;
use Porta\Ui\TextField;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Style\CursorShape;

final class TextFieldTest extends TestCase
{
    public function testStartsEmptyOrWithInitialValue(): void
    {
        $this->assertSame('', new TextField()->value());
        $this->assertSame('seed', new TextField('seed')->value());
    }

    public function testInsertAppendsPrintableText(): void
    {
        $field = new TextField();
        $this->assertTrue($field->insert('a'));
        $this->assertTrue($field->insert('bc'));
        $this->assertSame('abc', $field->value());
    }

    public function testInsertRejectsNonPrintableInput(): void
    {
        $field = new TextField('kept');
        $this->assertFalse($field->insert(''));
        $this->assertFalse($field->insert("\x1b[A"), 'escape sequence');
        $this->assertFalse($field->insert("\x08"), 'control char');
        $this->assertFalse($field->insert("\x7f"), 'DEL');
        $this->assertSame('kept', $field->value());
    }

    public function testInsertAcceptsMultibyteText(): void
    {
        $field = new TextField();
        $this->assertTrue($field->insert('é'));
        $this->assertSame('é', $field->value());
    }

    public function testBackspaceDropsTheLastByte(): void
    {
        $field = new TextField('ab');
        $this->assertTrue($field->backspace());
        $this->assertSame('a', $field->value());
        $this->assertTrue($field->backspace());
        $this->assertSame('', $field->value());
    }

    public function testBackspaceOnEmptyReportsNoChange(): void
    {
        $field = new TextField();
        $this->assertFalse($field->backspace());
        $this->assertSame('', $field->value());
    }

    public function testSetValueAndClear(): void
    {
        $field = new TextField();
        $field->setValue('query');
        $this->assertSame('query', $field->value());
        $field->clear();
        $this->assertSame('', $field->value());
    }

    public function testRenderAppendsTheBarCursorOnlyWhenAsked(): void
    {
        $field = new TextField('abc');
        $this->assertSame('abc', $field->render(cursor: false));
        $this->assertSame('abc'.AnsiUtils::cursorMarker(CursorShape::Bar), $field->render(cursor: true));
    }
}
