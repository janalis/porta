<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget;

use PHPUnit\Framework\TestCase;
use Porta\Editor\SelectionMode;
use Porta\Widget\CodeEditorWidget;

final class CodeEditorAutoPairsTest extends TestCase
{
    private function editor(string $content = "\n"): CodeEditorWidget
    {
        $editor = new CodeEditorWidget();
        $editor->openFile('t.php', $content);
        $editor->setFocused(true);

        return $editor;
    }

    public function testDefaultsOn(): void
    {
        $this->assertTrue($this->editor()->display->autoPairs);
    }

    public function testInsertsMatchingPairWithCursorBetween(): void
    {
        $editor = $this->editor();
        $editor->getDocument()->setCursor(0, 0);
        $editor->handleInput('(');

        $this->assertSame('()', $editor->getDocument()->getLine(0));
        $this->assertSame(1, $editor->getDocument()->cursorCol, 'cursor between the pair');
    }

    public function testAllBracketAndQuoteKinds(): void
    {
        foreach (['(' => ')', '[' => ']', '{' => '}', '"' => '"', "'" => "'", '`' => '`'] as $open => $close) {
            $editor = $this->editor();
            $editor->getDocument()->setCursor(0, 0);
            $editor->handleInput($open);
            $this->assertSame($open.$close, $editor->getDocument()->getLine(0), "pair for {$open}");
        }
    }

    public function testTypeOverExistingCloser(): void
    {
        $editor = $this->editor();
        $editor->getDocument()->setCursor(0, 0);
        $editor->handleInput('('); // "()", cursor at 1
        $editor->handleInput(')'); // type over

        $this->assertSame('()', $editor->getDocument()->getLine(0), 'no doubled closer');
        $this->assertSame(2, $editor->getDocument()->cursorCol);
    }

    public function testBackspaceRemovesEmptyPairAtomically(): void
    {
        $editor = $this->editor();
        $editor->getDocument()->setCursor(0, 0);
        $editor->handleInput('['); // "[]"
        $editor->handleInput("\x7f"); // backspace between

        $this->assertSame('', $editor->getDocument()->getLine(0));

        // The pair-delete is one undo step.
        $editor->handleInput("\x1a"); // ctrl+z
        $this->assertSame('[]', $editor->getDocument()->getLine(0));
    }

    public function testBackspaceOnNonEmptyPairIsNormal(): void
    {
        $editor = $this->editor('(x)');
        $editor->getDocument()->setCursor(0, 2); // between x and )
        $editor->handleInput("\x7f"); // deletes only x

        $this->assertSame('()', $editor->getDocument()->getLine(0));
    }

    public function testWrapSingleLineSelection(): void
    {
        $editor = $this->editor("foo bar\n");
        $doc = $editor->getDocument();
        $doc->setCursor(0, 0);
        $doc->beginSelect();
        $doc->setCursor(0, 3, selection: SelectionMode::Keep);

        $editor->handleInput('(');
        $this->assertSame('(foo) bar', $doc->getLine(0));
        $this->assertSame('foo', $doc->getSelectedText(), 'wrapped text stays selected');
    }

    public function testWrapMultiLineSelection(): void
    {
        $editor = $this->editor("aaa\nbbb\nccc\n");
        $doc = $editor->getDocument();
        $doc->setCursor(0, 1);
        $doc->beginSelect();
        $doc->setCursor(2, 1, selection: SelectionMode::Keep);

        $editor->handleInput('{');
        $this->assertSame('a{aa', $doc->getLine(0));
        $this->assertSame('c}cc', $doc->getLine(2));
        $this->assertSame("aa\nbbb\nc", $doc->getSelectedText());
    }

    public function testWrapIsOneUndoStep(): void
    {
        $editor = $this->editor("word\n");
        $doc = $editor->getDocument();
        $doc->selectAll();
        $editor->handleInput('[');
        $this->assertStringContainsString('[word', $doc->getLine(0));

        $editor->handleInput("\x1a"); // ctrl+z
        $this->assertSame('word', $doc->getLine(0), 'wrap reverts in a single undo');
    }

    public function testApostropheAfterWordIsNotPaired(): void
    {
        $editor = $this->editor("it\n");
        $editor->getDocument()->setCursor(0, 2);
        $editor->handleInput("'");

        $this->assertSame("it'", $editor->getDocument()->getLine(0), 'apostrophe, not a pair');
    }

    public function testOpeningBracketBeforeWordIsNotPaired(): void
    {
        // Auto-pairing before an identifier char would be noise.
        $editor = $this->editor("word\n");
        $editor->getDocument()->setCursor(0, 0);
        $editor->handleInput('(');

        $this->assertSame('(word', $editor->getDocument()->getLine(0));
    }

    public function testPairInsertedBeforeClosingBracket(): void
    {
        // Next char is a non-word closer, so pairing is allowed.
        $editor = $this->editor("()\n");
        $editor->getDocument()->setCursor(0, 1); // between ( and )
        $editor->handleInput('[');

        $this->assertSame('([])', $editor->getDocument()->getLine(0));
    }

    public function testDisabledFallsBackToPlainInsert(): void
    {
        $editor = $this->editor();
        $editor->display->setAutoPairs(false);
        $editor->getDocument()->setCursor(0, 0);
        $editor->handleInput('(');

        $this->assertSame('(', $editor->getDocument()->getLine(0));
        $this->assertFalse($editor->display->autoPairs);
    }

    public function testMultiCaretFallsBackToPlainInsert(): void
    {
        $editor = $this->editor("a\nb\n");
        $editor->getDocument()->setCursor(0, 0);
        $editor->handleInput("\x1b[1;7B"); // add caret below
        $editor->handleInput('('); // typed at both carets, no pairing

        $this->assertSame("(a\n(b\n", $editor->getDocument()->getText());
    }

    public function testBackspaceAtStartOfLineIsNeverTreatedAsAnEmptyPair(): void
    {
        // Column 0 has no preceding char to pair with — ")(" at the very start
        // must not be misread as an empty pair straddling the cursor.
        $editor = $this->editor(")(\n");
        $editor->getDocument()->setCursor(0, 0);
        $editor->handleInput("\x7f");

        $this->assertSame(')(', $editor->getDocument()->getLine(0), 'backspace at column 0 is a no-op');
    }

    public function testTypeOverForQuote(): void
    {
        $editor = $this->editor();
        $editor->getDocument()->setCursor(0, 0);
        $editor->handleInput('"'); // ""  cursor between
        $editor->handleInput('"'); // type over closing quote

        $this->assertSame('""', $editor->getDocument()->getLine(0));
        $this->assertSame(2, $editor->getDocument()->cursorCol);
    }
}
