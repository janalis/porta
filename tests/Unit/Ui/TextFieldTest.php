<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Ui;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\Tests\Support\Probe;
use Porta\Ui\CaretVisibility;
use Porta\Ui\CursorStyle;
use Porta\Ui\PastedNewlines;
use Porta\Ui\TextEcho;
use Porta\Ui\TextField;
use Porta\Widget\CodeEditorWidget;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Input\Keybindings;
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

    public function testInsertNewlineAppendsAndRendersAsAGlyph(): void
    {
        $field = new TextField('foo');
        $field->insertNewline();
        $this->assertTrue($field->insert('bar'));

        $this->assertSame("foo\nbar", $field->value(), 'the value holds a real newline');
        $this->assertSame('foo⏎bar', $field->render(cursor: CaretVisibility::Hidden), 'the newline renders as a visible glyph');
        $this->assertStringContainsString('foo⏎bar', $field->render(cursor: CaretVisibility::Shown, width: 40));
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
        $this->assertSame('abc', $field->render(cursor: CaretVisibility::Hidden));
        $this->assertSame('abc'.AnsiUtils::cursorMarker(CursorShape::Bar), $field->render(cursor: CaretVisibility::Shown));
    }

    public function testRenderUsesTheSharedCursorStyleShape(): void
    {
        $style = new CursorStyle(CursorShape::Block);
        $field = new TextField('abc', $style);
        $this->assertSame('abc'.AnsiUtils::cursorMarker(CursorShape::Block), $field->render(cursor: CaretVisibility::Shown), 'block marker from the holder');

        $style->switchTo(CursorShape::Underline);
        $this->assertSame('abc'.AnsiUtils::cursorMarker(CursorShape::Underline), $field->render(cursor: CaretVisibility::Shown), 'a live switch changes the next render');
        $this->assertStringContainsString(AnsiUtils::cursorMarker(CursorShape::Underline), $field->render(cursor: CaretVisibility::Shown, width: 10), 'the windowed path follows too');
    }

    public function testRenderWithWidthKeepsAShortValueWithTheMarkerAtTheEnd(): void
    {
        $field = new TextField('abc');
        $this->assertSame('abc'.AnsiUtils::cursorMarker(CursorShape::Bar), $field->render(cursor: CaretVisibility::Shown, width: 10));
    }

    public function testRenderScrollsToTheTailWhenTheFocusedValueOverflowsWidth(): void
    {
        $field = new TextField(\str_repeat('x', times: 60));
        $marker = AnsiUtils::cursorMarker(CursorShape::Bar);

        $out = $field->render(cursor: CaretVisibility::Shown, width: 10);

        $this->assertSame(1, \substr_count($out, $marker), 'the end caret marker stays visible');
        $this->assertStringEndsWith($marker, $out, 'scrolled to the tail, caret last');
        $this->assertLessThanOrEqual(10, AnsiUtils::visibleWidth($out), 'clipped to width');
    }

    public function testBackspaceDeletesAWholeMultibyteCharacterKeepingValidUtf8(): void
    {
        $field = new TextField('café'); // 'é' is 2 bytes

        $this->assertTrue($field->backspace());
        $this->assertSame('caf', $field->value(), 'the whole é is removed, not one byte');
        $this->assertTrue(\mb_check_encoding($field->value(), encoding: 'UTF-8'), 'never leaves a partial sequence');
    }

    public function testBackspaceDeletesAWholeEmojiAndEmDash(): void
    {
        $field = new TextField("done \u{1F41B}"); // 4-byte emoji
        $field->backspace();
        $this->assertSame('done ', $field->value());
        $this->assertTrue(\mb_check_encoding($field->value(), encoding: 'UTF-8'));

        $dash = new TextField("slow \u{2014}"); // 3-byte em-dash
        $dash->backspace();
        $this->assertSame('slow ', $dash->value());
        $this->assertTrue(\mb_check_encoding($dash->value(), encoding: 'UTF-8'));
    }

    public function testTheCaretMovesAndTypingLandsWhereItIs(): void
    {
        $field = new TextField('worl');
        $field->insert('d');
        $field->moveHome();
        $field->insert('hello ');

        $this->assertSame('hello world', $field->value());
    }

    /** The gesture that made append-only editing untenable: a typo at the start. */
    public function testATypoAtTheStartIsFixableWithoutRetypingTheRest(): void
    {
        $field = new TextField('{"stopOnEntry": true}');
        $field->moveHome();
        $field->deleteForward();
        $field->insert('[');

        $this->assertSame('["stopOnEntry": true}', $field->value());
    }

    public function testLeftAndRightStopAtTheEnds(): void
    {
        $field = new TextField('ab');

        $this->assertFalse($field->moveRight(), 'already past the last character');
        $this->assertTrue($field->moveLeft());
        $this->assertTrue($field->moveLeft());
        $this->assertFalse($field->moveLeft(), 'already at the start');
        $this->assertTrue($field->moveRight());
    }

    public function testHomeAndEndReportWhetherTheyMoved(): void
    {
        $field = new TextField('ab');

        $this->assertFalse($field->moveEnd(), 'the caret starts at the end');
        $this->assertTrue($field->moveHome());
        $this->assertFalse($field->moveHome(), 'already there');
        $this->assertTrue($field->moveEnd());
    }

    public function testBackspaceAndDeleteActAroundTheCaretNotTheTail(): void
    {
        $field = new TextField('abcd');
        $field->moveLeft();
        $field->moveLeft(); // caret between b and c

        $this->assertTrue($field->backspace());
        $this->assertSame('acd', $field->value(), 'the character BEFORE the caret went');

        $this->assertTrue($field->deleteForward());
        $this->assertSame('ad', $field->value(), 'and then the one after it');
    }

    public function testDeleteForwardAtTheEndReportsNoChange(): void
    {
        $this->assertFalse(new TextField('ab')->deleteForward());
    }

    public function testTheCaretMovesByWholeMultibyteCharacters(): void
    {
        $field = new TextField('a😀b');
        $field->moveLeft();
        $field->moveLeft();

        $this->assertTrue($field->backspace());
        $this->assertSame('😀b', $field->value(), 'a whole character either side, never a stray byte');
    }

    public function testDeleteWordBackwardTakesTheWordAndItsTrailingSpaces(): void
    {
        $field = new TextField('php -d memory_limit=1G');

        $this->assertTrue($field->deleteWordBackward());
        $this->assertSame('php -d ', $field->value());

        $this->assertTrue($field->deleteWordBackward());
        $this->assertSame('php ', $field->value(), 'the spaces before the word go with it');
    }

    public function testDeleteWordBackwardOnAnEmptyFieldReportsNoChange(): void
    {
        $this->assertFalse(new TextField()->deleteWordBackward());
    }

    public function testTheMarkerRendersAtTheCaretNotTheTail(): void
    {
        $field = new TextField('abcd');
        $field->moveLeft();
        $field->moveLeft();

        $this->assertSame('ab'.new CursorStyle()->marker().'cd', $field->render(CaretVisibility::Shown));
    }

    public function testAnOverflowingValueScrollsToTheCaretWhereverItIs(): void
    {
        $field = new TextField(\str_repeat('x', times: 40).'END');
        $field->moveHome();

        $rendered = $field->render(CaretVisibility::Shown, width: 10);

        $this->assertStringNotContainsString('END', $rendered, 'the window followed the caret to the start');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function graphemes(): iterable
    {
        yield 'e with a combining acute' => ["e\u{0301}"];
        yield 'a flag, two regional indicators' => ["\u{1F1EB}\u{1F1F7}"];
        yield 'a ZWJ family' => ["\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}"];
    }

    #[DataProvider('graphemes')]
    public function testEditingStepsOverAWholeCharacterNotACodePoint(string $grapheme): void
    {
        $field = new TextField('a'.$grapheme.'b');
        $field->moveLeft();
        $this->assertTrue($field->backspace());
        $this->assertSame('ab', $field->value(), 'backspace took all of it, not its last code point');

        $field = new TextField('a'.$grapheme.'b');
        $field->moveHome();
        $field->moveRight();
        $this->assertTrue($field->deleteForward());
        $this->assertSame('ab', $field->value(), 'Delete took all of it');

        $field = new TextField($grapheme);
        $field->moveLeft();
        $field->insert('x');
        $this->assertSame('x'.$grapheme, $field->value(), 'one ← crossed the whole character');
    }

    public function testCtrlWStillTakesAWholeArgumentButAltBackspaceOnlyOneClass(): void
    {
        $field = new TextField('open src/Foo/Bar.php');
        $this->assertTrue($field->editKey("\x1b\x7f", new Keybindings()));
        $this->assertSame('open src/Foo/Bar.', $field->value(), 'Alt+Backspace stops at the punctuation');

        $this->assertTrue($field->editKey("\x17", new Keybindings()));
        $this->assertSame('open ', $field->value(), 'Ctrl+W goes back to the space, readline-style');
    }

    public function testTheWordDeletesAlsoArriveInTheirKittyEncodings(): void
    {
        $kb = new Keybindings();
        $kb->setKittyProtocolActive(true);

        $field = new TextField('open src/Foo/Bar.php');
        $this->assertTrue($field->editKey("\x1b[127;3u", $kb), 'kitty Alt+Backspace');
        $this->assertSame('open src/Foo/Bar.', $field->value());

        $this->assertFalse($field->editKey("\x1b[119;5:3u", $kb), 'a key release is not a keystroke');
        $this->assertTrue($field->editKey("\x1b[119;5u", $kb), 'kitty Ctrl+W');
        $this->assertSame('open ', $field->value());
    }

    public function testCtrlArrowsMoveTheCaretAWordAtATime(): void
    {
        $field = new TextField('foo bar baz');
        $this->assertTrue($field->editKey("\x1b[1;5D", new Keybindings()));
        $field->insert('X');
        $this->assertSame('foo bar Xbaz', $field->value());

        $field->moveHome();
        $this->assertTrue($field->editKey("\x1b[1;5C", new Keybindings()));
        $field->insert('Y');
        $this->assertSame('fooY bar Xbaz', $field->value());
    }

    public function testAPasteInsertsAtTheCaret(): void
    {
        $field = new TextField('ad');
        $field->moveLeft();

        $this->assertTrue($field->editKey("\x1b[200~bc\x1b[201~", new Keybindings()));
        $this->assertSame('abcd', $field->value());
        $field->insert('!');
        $this->assertSame('abc!d', $field->value(), 'the caret sits after the pasted text');
    }

    public function testAPastedLineBreakJoinsWithASpaceByDefault(): void
    {
        $field = new TextField();
        $field->editKey("\x1b[200~one\r\ntwo\n\nthree\n\x1b[201~", new Keybindings());

        $this->assertSame('one two three', $field->value(), 'inner runs become one space, the copied trailing break goes');
    }

    public function testAFieldThatTakesNewlinesKeepsThePastedOnes(): void
    {
        $field = new TextField(pastedNewlines: PastedNewlines::Keep);
        $field->editKey("\x1b[200~one\r\ntwo\n\x1b[201~", new Keybindings());

        $this->assertSame("one\ntwo", $field->value(), 'the inner break kept, the copied line terminator not');
    }

    public function testAPastedEscapeSequenceArrivesAsInertText(): void
    {
        $field = new TextField();
        $field->editKey("\x1b[200~\x1b[31mred\ttab\x07\x1b[201~", new Keybindings());

        $this->assertSame('[31mred tab', $field->value(), 'no ESC, no BEL; the tab is a space');
    }

    public function testAnEmptyPasteChangesNothing(): void
    {
        $field = new TextField('keep');

        $this->assertFalse($field->editKey("\x1b[200~\n\x1b[201~", new Keybindings()));
        $this->assertSame('keep', $field->value());
    }

    /** A password: a bullet per character typed — a grapheme, not a byte — and the value untouched. */
    public function testAMaskedFieldDrawsABulletPerCharacter(): void
    {
        $field = new TextField('pä🇫🇷', echo: TextEcho::Masked);

        $this->assertSame('pä🇫🇷', $field->value());
        $this->assertSame('•••', $field->render(CaretVisibility::Hidden));
        $this->assertSame('', new TextField('', echo: TextEcho::Masked)->render(CaretVisibility::Hidden), 'nothing typed, nothing drawn');
        $field->moveLeft();
        $this->assertSame('••'.new CursorStyle()->marker().'•', $field->render(CaretVisibility::Shown));
        $this->assertSame("a\nb", new TextField("a\nb", pastedNewlines: PastedNewlines::Keep)->value(), 'unmasked, as it was');
        $this->assertSame('a⏎b', new TextField("a\nb")->render(CaretVisibility::Hidden));
    }

    /** The prompt a plugin opens for a password is masked all the way to the field. */
    public function testAMaskedPromptKeepsItsValueOffTheScreen(): void
    {
        $editor = new CodeEditorWidget();
        $editor->openPrompt('Password', 'secret', static function (): void {}, echo: TextEcho::Masked);

        /** @var array{value: TextField} $prompt */
        $prompt = Probe::get(Probe::object($editor, 'overlayHost'), 'prompt');
        $this->assertSame('••••••', $prompt['value']->render(CaretVisibility::Hidden));
    }
}
