<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget;

use PHPUnit\Framework\TestCase;
use Porta\Editor\SelectionMode;
use Porta\Tests\Support\Probe;
use Porta\Widget\CodeEditorWidget;

final class CodeEditorStructuralTest extends TestCase
{
    private function editor(string $content): CodeEditorWidget
    {
        $editor = new CodeEditorWidget();
        $editor->openFile('t.php', $content);
        $editor->setFocused(true);

        return $editor;
    }

    public function testExpandAndShrinkSelectionLadder(): void
    {
        $editor = $this->editor("foo(bar)\nbaz");
        $doc = $editor->getDocument();
        $doc->setCursor(0, 5); // inside "bar"

        $editor->handleInput("\x17"); // ctrl+w: caret -> word
        $this->assertSame('bar', $doc->getSelectedText());
        $editor->handleInput("\x17"); // word -> (bar)
        $this->assertSame('(bar)', $doc->getSelectedText());
        $editor->handleInput("\x17"); // (bar) -> line
        $this->assertSame('foo(bar)', $doc->getSelectedText());
        $editor->handleInput("\x17"); // line -> document
        $this->assertSame("foo(bar)\nbaz", $doc->getSelectedText());
        $editor->handleInput("\x17"); // already whole document: no-op
        $this->assertSame("foo(bar)\nbaz", $doc->getSelectedText());

        // Shrink back down through the same ladder.
        $editor->handleInput("\x1b[119;6u"); // ctrl+shift+w
        $this->assertSame('foo(bar)', $doc->getSelectedText());
        $editor->handleInput("\x1b[119;6u");
        $this->assertSame('(bar)', $doc->getSelectedText());
        $editor->handleInput("\x1b[119;6u");
        $this->assertSame('bar', $doc->getSelectedText());
        $editor->handleInput("\x1b[119;6u"); // back to the bare caret
        $this->assertFalse($doc->hasSelection());
        $editor->handleInput("\x1b[119;6u"); // stack empty: no-op
        $this->assertFalse($doc->hasSelection());
    }

    public function testExpandAndShrinkEachRequestARender(): void
    {
        $editor = $this->editor("foo(bar)\nbaz");
        $doc = $editor->getDocument();
        $doc->setCursor(0, 5); // inside "bar"

        $revision = $editor->getRenderRevision();
        $editor->handleInput("\x17"); // ctrl+w: expand
        $this->assertGreaterThan($revision, $editor->getRenderRevision(), 'expanding must request a redraw');

        $revision = $editor->getRenderRevision();
        $editor->handleInput("\x1b[119;6u"); // ctrl+shift+w: shrink
        $this->assertGreaterThan($revision, $editor->getRenderRevision(), 'shrinking must request a redraw');
    }

    public function testExpandResetsAfterManualCaretMove(): void
    {
        $editor = $this->editor('alpha beta');
        $doc = $editor->getDocument();
        $doc->setCursor(0, 0);

        $editor->handleInput("\x17");
        $this->assertSame('alpha', $doc->getSelectedText());

        // A manual caret move desyncs the expand anchor.
        $doc->setCursor(0, 8); // inside "beta"
        $editor->handleInput("\x1b[119;6u"); // shrink is now a no-op
        $this->assertFalse($doc->hasSelection());

        // A fresh expand starts a new ladder from the new caret.
        $editor->handleInput("\x17");
        $this->assertSame('beta', $doc->getSelectedText());
    }

    public function testMoveStatementUpAndDown(): void
    {
        $editor = $this->editor("a\nb\nc\n");
        $editor->getDocument()->setCursor(1, 0);

        $editor->handleInput("\x1b[1;6A"); // ctrl+shift+up
        $this->assertSame("b\na\nc\n", $editor->getDocument()->getText());

        $editor->handleInput("\x1b[1;6B"); // ctrl+shift+down
        $this->assertSame("a\nb\nc\n", $editor->getDocument()->getText());
    }

    public function testMoveStatementAtEdgeIsNoop(): void
    {
        $editor = $this->editor("x\ny");
        $editor->getDocument()->setCursor(0, 0);

        $editor->handleInput("\x1b[1;6A"); // ctrl+shift+up at top line: no change
        $this->assertSame("x\ny", $editor->getDocument()->getText());
    }

    public function testSurroundSelectionWithParentheses(): void
    {
        $editor = $this->editor('hello world');
        $doc = $editor->getDocument();
        $doc->setCursor(0, 0);
        $doc->beginSelect();
        $doc->setCursor(0, 5, selection: SelectionMode::Keep); // select "hello"

        $editor->handleInput("\x1b\x14"); // ctrl+alt+t opens the surround menu
        $editor->handleInput("\r"); // accept the first item (parentheses)

        $this->assertSame('(hello) world', $doc->getText());
        $this->assertSame('hello', $doc->getSelectedText(), 'wrapped text stays selected');
    }

    public function testSurroundSelectionWithSnippet(): void
    {
        $editor = $this->editor('doStuff();');
        $doc = $editor->getDocument();
        $doc->selectAll();

        $editor->handleInput("\x1b\x14"); // open menu
        $editor->handleInput("\x1b[B"); // move down to "if (…) { … }"
        $editor->handleInput("\x1b[B");
        $editor->handleInput("\x1b[B");
        $editor->handleInput("\x1b[B");
        $editor->handleInput("\x1b[B");
        $editor->handleInput("\r"); // accept

        $this->assertStringContainsString('if (doStuff();) {', $doc->getText());
    }

    public function testSurroundSelectionWithTryCatch(): void
    {
        // The last item in the menu: a removal that shifts only trailing
        // entries would not be caught by the earlier "if" snippet test, which
        // only navigates partway down the list.
        $editor = $this->editor('doStuff();');
        $doc = $editor->getDocument();
        $doc->selectAll();

        $editor->handleInput("\x1b\x14"); // open menu
        $editor->handleInput("\x1b[B"); // parens -> brackets
        $editor->handleInput("\x1b[B"); // -> braces
        $editor->handleInput("\x1b[B"); // -> single quotes
        $editor->handleInput("\x1b[B"); // -> double quotes
        $editor->handleInput("\x1b[B"); // -> if
        $editor->handleInput("\x1b[B"); // -> foreach
        $editor->handleInput("\x1b[B"); // -> try/catch
        $editor->handleInput("\r"); // accept

        $this->assertStringContainsString("try {\ndoStuff();\n} catch", $doc->getText());
    }

    public function testSurroundMenuItemsCarryAHintKeyThePickerLayoutReadsDirectly(): void
    {
        // PickerLayout reads $item['hint'] unguarded; a surround item missing
        // that key would only surface as a warning once the picker is actually
        // laid out, not from picking an item.
        $editor = $this->editor('hello world');
        $doc = $editor->getDocument();
        $doc->beginSelect();
        $doc->setCursor(0, 5, selection: SelectionMode::Keep);

        $editor->handleInput("\x1b\x14"); // open the surround menu

        $host = Probe::object($editor, 'overlayHost');
        $host->layout([0, 0, 24, 80]);

        $this->assertTrue(true, 'laying out the open picker must not warn on a missing hint key');
    }

    public function testSurroundWithNoSelectionDoesNothing(): void
    {
        $editor = $this->editor('hello');
        $editor->handleInput("\x1b\x14"); // ctrl+alt+t with no selection
        $this->assertSame('hello', $editor->getDocument()->getText());
    }
}
