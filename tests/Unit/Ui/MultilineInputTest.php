<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;
use Porta\Ui\MultilineInput;
use Symfony\Component\Tui\Ansi\AnsiUtils;

final class MultilineInputTest extends TestCase
{
    private function type(MultilineInput $input, string ...$keys): void
    {
        foreach ($keys as $key) {
            $input->handleKey($key);
        }
    }

    public function testTypingAndEnterBuildLines(): void
    {
        $input = new MultilineInput();
        $this->assertTrue($input->isEmpty());

        $this->type($input, 'ab', "\r", 'cd');

        $this->assertSame("ab\ncd", $input->value());
        $this->assertFalse($input->isEmpty());
    }

    public function testEnterSplitsAtTheCaret(): void
    {
        $input = new MultilineInput();
        $this->type($input, 'abcd', "\x1b[D", "\x1b[D", "\r");

        $this->assertSame("ab\ncd", $input->value());
    }

    public function testBackspaceDeletesAndJoinsLines(): void
    {
        $input = new MultilineInput();
        $this->type($input, 'ab', "\r", 'c');
        $this->type($input, "\x7f", "\x7f"); // delete 'c', then join onto 'ab'

        $this->assertSame('ab', $input->value());

        $this->type($input, 'X');
        $this->assertSame('abX', $input->value(), 'caret sat at the join point');

        // Backspace at the very start is consumed but changes nothing.
        $fresh = new MultilineInput();
        $this->assertTrue($fresh->handleKey("\x7f"));
        $this->assertSame('', $fresh->value());
    }

    public function testArrowMovementClampsAndWraps(): void
    {
        $input = new MultilineInput();
        $this->type($input, 'long line', "\r", 'ab');

        $this->type($input, "\x1b[A"); // up: col clamps... col was 2, fits in 'long line'
        $this->type($input, 'X');
        $this->assertSame("loXng line\nab", $input->value());

        $input2 = new MultilineInput();
        $this->type($input2, 'ab', "\r", 'cd', "\x1b[A"); // up from col 2
        $this->type($input2, "\x1b[C", "\x1b[C"); // right past end wraps to next line start... first right moves col 2->... col is 2 == strlen('ab') so wraps down to col 0
        $this->type($input2, 'Y');
        $this->assertSame("ab\ncYd", $input2->value());

        $input3 = new MultilineInput();
        $this->type($input3, 'ab', "\r", 'cd', "\x1b[D", "\x1b[D", "\x1b[D"); // left wraps up to end of 'ab'
        $this->type($input3, 'Z');
        $this->assertSame("abZ\ncd", $input3->value());

        // Up on the first line and down on the last are consumed no-ops.
        $this->assertTrue($input3->handleKey("\x1b[B"));
        $this->assertTrue($input3->handleKey("\x1b[B"));
    }

    public function testSetValueMovesTheCaretToTheEnd(): void
    {
        $input = new MultilineInput();
        $input->setValue("one\ntwo");
        $this->type($input, '!');

        $this->assertSame("one\ntwo!", $input->value());

        $input->clear();
        $this->assertTrue($input->isEmpty());
    }

    public function testNonPrintableUnknownKeysAreNotConsumed(): void
    {
        $input = new MultilineInput();
        $this->assertFalse($input->handleKey("\x1b[Z"), 'unknown escape');
        $this->assertFalse($input->handleKey("\x01"), 'control byte');
        $this->assertSame('', $input->value());
    }

    public function testRenderShowsCursorOnlyWhenAsked(): void
    {
        $input = new MultilineInput();
        $this->type($input, 'ab', "\r", 'cd', "\x1b[D");

        $plain = $input->render(10, cursor: false);
        $this->assertSame(['ab', 'cd'], $plain);

        $withCursor = $input->render(10, cursor: true);
        $this->assertStringContainsString('c', AnsiUtils::stripAnsiCodes($withCursor[1]));
        $this->assertNotSame('cd', $withCursor[1], 'cursor marker embedded in the caret line');
    }
}
