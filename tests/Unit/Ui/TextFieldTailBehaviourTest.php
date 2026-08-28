<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;
use Porta\Ui\CaretVisibility;
use Porta\Ui\CursorStyle;
use Porta\Ui\TextField;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * Characterization: what a caret pinned at the tail does, byte for byte.
 *
 * {@see TextField} is shared by nine surfaces — the find bar, find-in-files,
 * the results query, go-to-file, the overlay prompt and search, the settings
 * form, and the git commit box. Every one of them appends at the end today, so
 * giving the field a movable caret is additive ONLY if a caret that has never
 * moved behaves exactly as the append-only field did.
 *
 * Written BEFORE that change, deliberately: these assertions are the contract
 * the caret must not break, and writing them afterwards would only describe
 * whatever it happened to do.
 */
final class TextFieldTailBehaviourTest extends TestCase
{
    public function testInsertAppendsAtTheEnd(): void
    {
        $field = new TextField('ab');
        $field->insert('c');
        $field->insert('d');

        $this->assertSame('abcd', $field->value());
    }

    public function testBackspaceDeletesFromTheEnd(): void
    {
        $field = new TextField('abc');

        $this->assertTrue($field->backspace());
        $this->assertSame('ab', $field->value());
    }

    public function testBackspaceOnEmptyReportsNoChange(): void
    {
        $this->assertFalse(new TextField()->backspace());
    }

    public function testSetValueLeavesTheCaretReadyToAppend(): void
    {
        // Every consumer seeds a field from an existing value and then types.
        $field = new TextField();
        $field->setValue('seeded');
        $field->insert('!');

        $this->assertSame('seeded!', $field->value());
    }

    public function testClearLeavesTheFieldReadyToAppend(): void
    {
        $field = new TextField('something');
        $field->clear();
        $field->insert('x');

        $this->assertSame('x', $field->value());
    }

    public function testTheMarkerRendersAfterTheWholeValue(): void
    {
        $field = new TextField('abc');
        $marker = new CursorStyle()->marker();

        $this->assertSame('abc'.$marker, $field->render(CaretVisibility::Shown), 'the caret sits past the last character');
        $this->assertSame('abc', $field->render(CaretVisibility::Hidden));
    }

    public function testAnOverflowingValueScrollsSoTheTailCaretStaysOnScreen(): void
    {
        $field = new TextField(str_repeat('x', times: 40));

        $rendered = $field->render(CaretVisibility::Shown, width: 10);

        $this->assertStringEndsWith(new CursorStyle()->marker(), $rendered, 'the caret is the thing kept in view');
        $this->assertSame(9, AnsiUtils::visibleWidth($rendered), 'the window is the width less the caret cell');
    }

    public function testMultibyteTextAppendsAndDeletesWholeCharacters(): void
    {
        $field = new TextField();
        $field->insert('é');
        $field->insert('😀');
        $this->assertSame('é😀', $field->value());

        $field->backspace();
        $this->assertSame('é', $field->value(), 'a whole emoji, not one of its bytes');
    }

    public function testANewlineAppendsAndRendersAsAGlyph(): void
    {
        $field = new TextField('a');
        $field->insertNewline();
        $field->insert('b');

        $this->assertSame("a\nb", $field->value());
        $this->assertStringContainsString('⏎', $field->render(CaretVisibility::Hidden));
    }
}
