<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Search;

use PHPUnit\Framework\TestCase;
use Porta\Search\EditorFindTarget;
use Porta\Widget\CodeEditorWidget;
use Symfony\Component\Tui\Render\RenderContext;

final class EditorFindTargetTest extends TestCase
{
    public function testBridgesTheFindBarToTheEditorDocument(): void
    {
        $editor = new CodeEditorWidget();
        $editor->openFile('demo.php', "alpha\nbeta\n");
        $doc = $editor->getDocument();
        $target = new EditorFindTarget($doc, $editor->decorations, $editor->multiCaret);

        $lines = $target->findLines;
        $this->assertContains('alpha', $lines);
        $this->assertContains('beta', $lines);
        $this->assertSame($doc->version, $target->findVersion);
        $this->assertSame($doc->cursorLine, $target->findCaretLine);
        $this->assertSame($doc->cursorCol, $target->findCaretCol);

        // Revealing a match moves the document caret (the editor scrolls it in).
        $target->revealMatch(1, startByte: 0, endByte: 4);
        $this->assertSame(1, $doc->cursorLine);
        $this->assertSame(0, $doc->cursorCol);

        // Mirroring reaches the editor's actual highlight, not just a no-throw call.
        $target->showSearchMatches([1 => [['start' => 0, 'end' => 4, 'current' => true]]]);
        $this->assertStringContainsString(
            "\x1b[48;5;130m",
            $editor->render(new RenderContext(80, 10))[1],
            'the current match background reaches the editor',
        );

        // The editor is editable, so replace targets its document.
        $this->assertSame($doc, $target->replaceDocument);
    }

    /**
     * Select All Occurrences lands on the editor's carets: every match
     * selected, typing replaces them all, one undo brings them back.
     */
    public function testSelectMatchesPutsASelectingCaretOnEveryMatch(): void
    {
        $editor = new CodeEditorWidget();
        $editor->openFile('demo.php', "foo x foo\nfoo\n");
        $editor->setFocused(true);
        $doc = $editor->getDocument();
        $target = new EditorFindTarget($doc, $editor->decorations, $editor->multiCaret);

        $matches = [['line' => 0, 'start' => 0, 'end' => 3], ['line' => 0, 'start' => 6, 'end' => 9], ['line' => 1, 'start' => 0, 'end' => 3]];
        $this->assertTrue($target->selectMatches($matches, current: 1), 'an editor takes carets');
        $this->assertSame([0, 9], [$doc->cursorLine, $doc->cursorCol], 'the current match is the primary');

        $editor->handleInput('X');
        $this->assertSame("X x X\nX\n", $doc->getText(), 'typing replaces every selected match');
        $editor->handleInput("\x1a"); // ctrl+z
        $this->assertSame("foo x foo\nfoo\n", $doc->getText(), 'in one undo step');
    }

    /**
     * The target holds the carets of the document it searches: after a buffer
     * switch the editor's caret set is another one, and the shown buffer is
     * left alone.
     */
    public function testSelectMatchesAfterABufferSwitchLeavesTheShownBufferAlone(): void
    {
        $editor = new CodeEditorWidget();
        $editor->openFile('a.php', "foo foo\n");
        $editor->setFocused(true);
        $target = new EditorFindTarget($editor->getDocument(), $editor->decorations, $editor->multiCaret);
        $editor->openFile('b.php', "bar bar\n");

        $target->selectMatches([['line' => 0, 'start' => 0, 'end' => 3], ['line' => 0, 'start' => 4, 'end' => 7]], current: 0);

        $this->assertFalse($editor->multiCaret->hasMultiple(), 'b.php gained no carets');
        $editor->handleInput('X');
        $this->assertSame("Xbar bar\n", $editor->getDocument()->getText(), 'and types at its own single caret');
    }
}
