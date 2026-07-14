<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;
use Porta\Ui\CursorStyle;
use Porta\Ui\MultilineInput;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Style\CursorShape;

final class MultilineInputTest extends TestCase
{
    private function type(MultilineInput $input, string ...$keys): void
    {
        foreach ($keys as $key) {
            $input->handleKey($key);
        }
    }

    public function testRenderUsesTheSharedCursorStyleShape(): void
    {
        $style = new CursorStyle(CursorShape::Underline);
        $input = new MultilineInput($style);
        $input->handleKey('a');

        $out = implode('', $input->render(20, 3, cursor: true));
        $this->assertStringContainsString(AnsiUtils::cursorMarker(CursorShape::Underline), $out, 'the marker carries the holder shape');
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

        $plain = $input->render(10, 3, cursor: false);
        $this->assertSame(['ab', 'cd'], $plain);

        $withCursor = $input->render(10, 3, cursor: true);
        $this->assertStringContainsString('c', AnsiUtils::stripAnsiCodes($withCursor[1]));
        $this->assertNotSame('cd', $withCursor[1], 'cursor marker embedded in the caret line');
    }

    private function markers(string ...$lines): int
    {
        return array_sum(array_map(fn (string $l): int => substr_count($l, AnsiUtils::cursorMarker(CursorShape::Bar)), $lines));
    }

    public function testRenderScrollsToKeepTheCaretLineVisible(): void
    {
        $input = new MultilineInput();
        $this->type($input, 'l1', "\r", 'l2', "\r", 'l3', "\r", 'l4', "\r", 'l5'); // caret on line 4 (0-based)

        $window = $input->render(10, 3, cursor: true);

        $this->assertCount(3, $window, 'only the 3-row window is returned');
        $this->assertSame(['l3', 'l4', 'l5'], array_map(AnsiUtils::stripAnsiCodes(...), $window), 'scrolled so the caret line shows');
        $this->assertSame(1, $this->markers(...$window), 'the caret marker is inside the window');
        $this->assertNotSame('l5', $window[2], 'the marker is on the caret (last) line');
    }

    public function testRenderKeepsTheTopWhenTheCaretIsHigh(): void
    {
        $input = new MultilineInput();
        $this->type($input, 'l1', "\r", 'l2', "\r", 'l3', "\r", 'l4');
        $this->type($input, "\x1b[A", "\x1b[A", "\x1b[A"); // caret back up to line 0

        $window = $input->render(10, 3, cursor: true);

        $this->assertSame(['l1', 'l2', 'l3'], array_map(AnsiUtils::stripAnsiCodes(...), $window), 'window anchored at the top');
        $this->assertSame(1, $this->markers(...$window), 'the caret marker is still shown');
        $this->assertNotSame('l1', $window[0], 'the marker is on the caret (first) line');
    }

    public function testRenderEmptyWithCursorReturnsOneMarkedLine(): void
    {
        $input = new MultilineInput();

        $window = $input->render(10, 3, cursor: true);

        $this->assertCount(1, $window);
        $this->assertSame(1, $this->markers(...$window), 'the caret marker shows on the empty line');
    }

    public function testBackspaceDeletesAWholeMultibyteCharacter(): void
    {
        $input = new MultilineInput();
        $this->type($input, 'slow ', "\u{2014}"); // trailing em-dash (3 bytes)

        $this->assertTrue($input->handleKey("\x7f"));
        $this->assertSame('slow ', $input->value(), 'the whole em-dash is removed');
        $this->assertTrue(mb_check_encoding($input->value(), 'UTF-8'), 'never leaves a partial sequence');
    }

    public function testArrowsMoveByWholeCharacterAcrossMultibyte(): void
    {
        $input = new MultilineInput();
        $this->type($input, "a\u{2014}b"); // 'a' + 3-byte em-dash + 'b', caret at end (col 5)

        $input->handleKey("\x1b[D"); // left over 'b'
        $input->handleKey("\x1b[D"); // left over the em-dash in one step
        // Inserting here must land between 'a' and the em-dash, not mid-sequence.
        $input->handleKey('X');
        $this->assertSame("aX\u{2014}b", $input->value());
        $this->assertTrue(mb_check_encoding($input->value(), 'UTF-8'));

        $input->handleKey("\x1b[C"); // right over the em-dash in one step
        $input->handleKey('Y');
        $this->assertSame("aX\u{2014}Yb", $input->value());
        $this->assertTrue(mb_check_encoding($input->value(), 'UTF-8'));
    }

    public function testUpDownNeverLandMidCharacter(): void
    {
        $input = new MultilineInput();
        $this->type($input, "a\u{2014}bc", "\r", 'xy'); // line 0 has a wide char, caret on line 1 col 2

        $input->handleKey("\x1b[A"); // up onto the multibyte line at byte col 2 (mid em-dash)
        $input->handleKey('Z'); // insert must not split the em-dash
        $this->assertTrue(mb_check_encoding($input->value(), 'UTF-8'));
        $this->assertStringContainsString("\u{2014}", $input->value(), 'the em-dash survived intact');
    }

    public function testRenderHorizontallyWindowsALongCaretLine(): void
    {
        $input = new MultilineInput();
        $this->type($input, str_repeat('x', 60)); // one 60-column line, caret at the end

        $window = $input->render(10, 3, cursor: true);

        $this->assertCount(1, $window);
        $this->assertSame(1, $this->markers(...$window), 'the caret marker survives horizontal overflow');
        $this->assertLessThanOrEqual(10, AnsiUtils::visibleWidth($window[0]), 'the caret line is clipped to width');
    }
}
