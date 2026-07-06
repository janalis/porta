<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget;

use PHPUnit\Framework\TestCase;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\EditorCallbacks;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;

final class CodeEditorMultiCaretTest extends TestCase
{
    private function editor(string $content): CodeEditorWidget
    {
        $editor = new CodeEditorWidget();
        $editor->openFile('t.php', $content);
        $editor->setFocused(true);

        return $editor;
    }

    public function testAddCaretBelowAndTypeAtAll(): void
    {
        $editor = $this->editor("aaa\nbbb\nccc\n");
        $editor->getDocument()->setCursor(0, 0);

        $editor->handleInput("\x1b[1;7B"); // ctrl+alt+down
        $editor->handleInput("\x1b[1;7B");
        $this->assertTrue($editor->hasMultipleCarets());
        $this->assertCount(2, $editor->getExtraCarets());

        $editor->handleInput('X');
        $this->assertSame("Xaaa\nXbbb\nXccc\n", $editor->getDocument()->getText());
    }

    public function testMultiCaretEditIsAtomicUndo(): void
    {
        $editor = $this->editor("aaa\nbbb\nccc\n");
        $editor->getDocument()->setCursor(0, 0);
        $editor->handleInput("\x1b[1;7B");
        $editor->handleInput("\x1b[1;7B");
        $editor->handleInput('X');

        $editor->handleInput("\x1a"); // ctrl+z
        $this->assertSame("aaa\nbbb\nccc\n", $editor->getDocument()->getText(), 'one undo reverts every caret');
    }

    public function testUndoRestoresTheMultipleCarets(): void
    {
        $editor = $this->editor("aaa\nbbb\nccc\n");
        $editor->getDocument()->setCursor(0, 0);
        $editor->handleInput("\x1b[1;7B"); // add caret below
        $editor->handleInput("\x1b[1;7B");
        $this->assertCount(2, $editor->getExtraCarets(), '3 carets active');

        $editor->handleInput('X'); // edit at every caret

        // Undo brings back both the text and every secondary caret (previously lost).
        $editor->handleInput("\x1a"); // ctrl+z
        $this->assertSame("aaa\nbbb\nccc\n", $editor->getDocument()->getText());
        $this->assertTrue($editor->hasMultipleCarets(), 'the secondary carets are restored on undo');
        $this->assertCount(2, $editor->getExtraCarets());

        // Redo re-applies the edit and its carets (the restore hook fires either way).
        $editor->getDocument()->redo();
        $this->assertSame("Xaaa\nXbbb\nXccc\n", $editor->getDocument()->getText());
        $this->assertCount(2, $editor->getExtraCarets(), 'redo restores the carets too');
    }

    public function testTabInsertsAtEveryCaretNotBlockIndent(): void
    {
        $editor = $this->editor("aa\nbb\ncc\n");
        $editor->getDocument()->setCursor(0, 0);
        $editor->handleInput("\x1b[1;7B"); // add caret below
        $editor->handleInput("\x1b[1;7B");
        $this->assertCount(2, $editor->getExtraCarets());

        $editor->handleInput("\t"); // multi-caret Tab inserts a unit at each caret
        $this->assertSame("    aa\n    bb\n    cc\n", $editor->getDocument()->getText());
    }

    public function testAddCaretAbove(): void
    {
        $editor = $this->editor("aaa\nbbb\nccc\n");
        $editor->getDocument()->setCursor(2, 0);

        $editor->handleInput("\x1b[1;7A"); // ctrl+alt+up
        $this->assertCount(1, $editor->getExtraCarets());
        $editor->handleInput('Z');
        $this->assertSame("aaa\nZbbb\nZccc\n", $editor->getDocument()->getText());
    }

    public function testAddCaretAtDocumentEdgeIsIgnored(): void
    {
        $editor = $this->editor("only\n");
        $editor->getDocument()->setCursor(0, 0);
        $editor->handleInput("\x1b[1;7A"); // nothing above
        $this->assertFalse($editor->hasMultipleCarets());
    }

    public function testAddNextOccurrence(): void
    {
        $editor = $this->editor("foo bar foo baz foo\n");
        $editor->getDocument()->setCursor(0, 0);

        $editor->handleInput("\x1bj"); // alt+j
        $editor->handleInput("\x1bj");
        $this->assertCount(2, $editor->getExtraCarets());
    }

    public function testAddNextOccurrenceWithNoWordIsNoOp(): void
    {
        $editor = $this->editor("   \n");
        $editor->getDocument()->setCursor(0, 1);
        $editor->handleInput("\x1bj");
        $this->assertFalse($editor->hasMultipleCarets());
    }

    public function testSelectAllOccurrences(): void
    {
        $editor = $this->editor("xy = xy + xy\n");
        $editor->getDocument()->setCursor(0, 0); // on first "xy"

        $editor->handleInput("\x1b[106;8u"); // ctrl+alt+shift+j (CSI-u)
        $this->assertSame(2, \count($editor->getExtraCarets()), 'three occurrences -> primary + 2 extras');

        $editor->handleInput('!');
        $this->assertSame("xy! = xy! + xy!\n", $editor->getDocument()->getText());
    }

    public function testSelectAllOccurrencesWithNoWordIsNoOp(): void
    {
        $editor = $this->editor("   \n");
        $editor->getDocument()->setCursor(0, 1);
        $editor->handleInput("\x1b[106;8u");
        $this->assertFalse($editor->hasMultipleCarets());
    }

    public function testMovementMovesAllCaretsAndDedupes(): void
    {
        $editor = $this->editor("abc\nabc\n");
        $editor->getDocument()->setCursor(0, 0);
        $editor->handleInput("\x1b[1;7B"); // caret on line 1 too
        $editor->handleInput("\x1b[C"); // right: both carets move to col 1

        $this->assertSame(1, $editor->getDocument()->getCursorCol());
        $this->assertCount(1, $editor->getExtraCarets());
    }

    public function testEscapeCollapsesToSingleCaret(): void
    {
        $editor = $this->editor("aaa\nbbb\n");
        $editor->getDocument()->setCursor(0, 0);
        $editor->handleInput("\x1b[1;7B");
        $this->assertTrue($editor->hasMultipleCarets());

        $editor->handleInput("\x1b"); // escape
        $this->assertFalse($editor->hasMultipleCarets());
    }

    public function testStrayInputLeavesCaretsIntact(): void
    {
        $editor = $this->editor("word here\nword there\n");
        $editor->getDocument()->setCursor(0, 0);
        $editor->handleInput("\x1b[1;7B");
        $this->assertTrue($editor->hasMultipleCarets());

        // A stray terminal escape sequence (e.g. a focus report) that multi-caret
        // mode does not own must NOT collapse the carets — they are sticky.
        $editor->handleInput("\x1b[I");
        $this->assertTrue($editor->hasMultipleCarets(), 'stray input keeps the carets');

        // Escape still collapses deliberately.
        $editor->handleInput("\x1b");
        $this->assertFalse($editor->hasMultipleCarets());
    }

    public function testMultiCaretNewlineDeleteAndPaste(): void
    {
        $editor = $this->editor("ab\nab\n");
        $editor->getDocument()->setCursor(0, 1);
        $editor->handleInput("\x1b[1;7B"); // second caret at (1,1)

        $editor->handleInput("\x7f"); // backspace at both -> "b\nb"
        $this->assertSame("b\nb\n", $editor->getDocument()->getText());

        $editor->handleInput("\t"); // indent (4 spaces) at both
        $this->assertStringContainsString('    b', $editor->getDocument()->getLine(0));
    }

    public function testMultiCaretNewlineDeleteForwardWordDeleteAndPaste(): void
    {
        // Newline at every caret.
        $editor = $this->editor("aXb\naXb\n");
        $editor->getDocument()->setCursor(0, 2);
        $editor->handleInput("\x1b[1;7B"); // caret at (1,2)
        $editor->handleInput("\r"); // split both lines before "b"
        $this->assertSame("aX\nb\naX\nb\n", $editor->getDocument()->getText());

        // Delete-forward at every caret.
        $editor = $this->editor("ab\nab\n");
        $editor->getDocument()->setCursor(0, 0);
        $editor->handleInput("\x1b[1;7B");
        $editor->handleInput("\x1b[3~"); // delete
        $this->assertSame("b\nb\n", $editor->getDocument()->getText());

        // Delete-word-backward at every caret.
        $editor = $this->editor("foo bar\nfoo bar\n");
        $editor->getDocument()->setCursor(0, 7);
        $editor->handleInput("\x1b[1;7B"); // caret at (1,7)
        $editor->handleInput("\x1b\x7f"); // alt+backspace (delete word)
        $this->assertSame("foo \nfoo \n", $editor->getDocument()->getText());

        // Paste at every caret.
        $editor = $this->editor("a\na\n");
        $editor->getDocument()->setCursor(0, 0);
        $editor->handleInput("\x1b[1;7B");
        // Prime the internal clipboard via a copy of a selection.
        $editor->getDocument()->selectAll();
        $editor->handleInput("\x03"); // ctrl+c copies "a\na\n"
        $editor->getDocument()->setCursor(0, 0);
        $editor->clearExtraCarets();
        $editor->handleInput("\x1b[1;7B");
        $editor->handleInput("\x16"); // ctrl+v paste at both carets
        $this->assertStringContainsString('a', $editor->getDocument()->getText());
    }

    public function testSecondaryCaretsRender(): void
    {
        $editor = $this->editor("aaa\nbbb\nccc\n");
        $editor->getDocument()->setCursor(0, 1);
        $editor->handleInput("\x1b[1;7B");

        $rendered = $editor->render(new RenderContext(40, 6));
        // The secondary caret renders as a reverse-video cell.
        $this->assertStringContainsString("\x1b[7m", implode('', $rendered));
        foreach ($rendered as $line) {
            $this->assertSame(40, AnsiUtils::visibleWidth($line));
        }
    }

    public function testMouseColumnSelectionBuildsRaggedBlock(): void
    {
        $editor = $this->editor("aaaa\nbb\ncccc\n");
        $editor->render(new RenderContext(80, 10)); // gutter width = 6
        $editor->beginColumnSelectionAt(0, 6 + 1); // anchor display col 1
        $editor->extendColumnSelectionTo(2, 6 + 3); // caret display col 3

        $this->assertTrue($editor->hasMultipleCarets());
        $carets = $editor->getExtraCarets();
        $this->assertCount(2, $carets, 'rows 0 and 1 are extras; the caret row 2 is primary');
        // The short middle line "bb" clamps its selection to the line end.
        $this->assertSame(['line' => 1, 'col' => 2, 'anchorLine' => 1, 'anchorCol' => 1], $carets[1]);
    }

    public function testMouseColumnSelectionZeroWidthMakesVerticalCarets(): void
    {
        $editor = $this->editor("aaaa\nbbbb\ncccc\n");
        $editor->render(new RenderContext(80, 10));
        $editor->beginColumnSelectionAt(0, 6 + 2);
        $editor->extendColumnSelectionTo(2, 6 + 2); // same column: a vertical caret stack, no selection

        $this->assertTrue($editor->hasMultipleCarets());
        $this->assertFalse($editor->getDocument()->hasSelection(), 'the caret row has an empty selection');
        foreach ($editor->getExtraCarets() as $caret) {
            $this->assertSame($caret['col'], $caret['anchorCol']);
            $this->assertSame($caret['line'], $caret['anchorLine']);
        }
    }

    public function testMouseColumnSelectionTypingReplacesEveryRow(): void
    {
        $editor = $this->editor("aaaa\nbb\ncccc\n");
        $editor->render(new RenderContext(80, 10));
        $editor->beginColumnSelectionAt(0, 6 + 1);
        $editor->extendColumnSelectionTo(2, 6 + 3);

        $editor->handleInput('X');

        $this->assertSame("aXa\nbX\ncXc\n", $editor->getDocument()->getText());
        foreach ($editor->getExtraCarets() as $caret) {
            $this->assertSame($caret['col'], $caret['anchorCol'], 'carets collapse to points after the edit');
        }
    }

    private function blockCopy(CodeEditorWidget $editor): string
    {
        $copied = '';
        $editor->setCallbacks(new EditorCallbacks(
            onCopy: function (string $text) use (&$copied): void {
                $copied = $text;
            },
        ));
        $editor->handleInput("\x03"); // ctrl+c

        return $copied;
    }

    public function testShiftArrowSelectsAtEveryCaret(): void
    {
        $editor = $this->editor("hello\nworld\nfudge\n");
        $editor->getDocument()->setCursor(0, 0);
        $editor->handleInput("\x1b[1;7B"); // ctrl+alt+down
        $editor->handleInput("\x1b[1;7B"); // carets on lines 0,1,2 at col 0

        $editor->handleInput("\x1b[1;2C"); // shift+right at every caret

        $this->assertSame("h\nw\nf", $this->blockCopy($editor), 'one char selected at each caret');
        $editor->handleInput('X'); // replaces every selection
        $this->assertSame("Xello\nXorld\nXudge\n", $editor->getDocument()->getText());
    }

    public function testShiftDownSelectsCrossLineAtEveryCaret(): void
    {
        $editor = $this->editor("aaaa\nbbbb\ncccc\ndddd\n");
        $editor->getDocument()->setCursor(0, 1);
        $editor->handleInput("\x1b[1;7B"); // carets: line 0 (extra) + line 1 (primary), col 1

        $editor->handleInput("\x1b[1;2B"); // shift+down at both -> cross-line selections

        // Caret from line 0 now spans (0,1)..(1,1); from line 1 spans (1,1)..(2,1).
        $this->assertSame("aaa\nb\nbbb\nc", $this->blockCopy($editor));
    }

    public function testStrayInputBetweenKeystrokesKeepsBroadcast(): void
    {
        $editor = $this->editor("one\ntwo\nthree\n");
        $editor->getDocument()->setCursor(0, 0);
        $editor->handleInput("\x1b[1;7B");
        $editor->handleInput("\x1b[1;7B"); // carets on lines 0,1,2

        $editor->handleInput('a');
        $editor->handleInput("\x1b[I"); // stray focus report between keystrokes
        $editor->handleInput('b');

        $this->assertSame("abone\nabtwo\nabthree\n", $editor->getDocument()->getText());
    }

    public function testEndOfLineCaretsStillRender(): void
    {
        $editor = $this->editor("ab\ncd\n");
        $editor->getDocument()->setCursor(0, 2); // end of "ab"
        $editor->handleInput("\x1b[1;7B"); // extra at (0,2 EOL), primary at (1,2 EOL)

        $rendered = $editor->render(new RenderContext(30, 5));

        // Both carets sit past their line's text; each must still render a cell.
        $this->assertSame(1, substr_count($rendered[0], "\x1b[7m"), 'end-of-line caret on line 0 is visible');
        $this->assertSame(1, substr_count($rendered[1], "\x1b[7m"), 'end-of-line caret on line 1 is visible');
        foreach ($rendered as $line) {
            $this->assertSame(30, AnsiUtils::visibleWidth($line));
        }
    }

    public function testAllCaretsRenderUniformlyAndBarSuppressed(): void
    {
        $editor = $this->editor("aaa\nbbb\nccc\n");
        $editor->getDocument()->setCursor(0, 1);
        $editor->handleInput("\x1b[1;7B"); // 2 carets: line 0 (extra) + line 1 (primary)

        $rendered = $editor->render(new RenderContext(40, 6));
        $joined = implode('', $rendered);

        // Every caret (primary + extra) is the same reverse-video cell; no bar.
        $this->assertSame(2, substr_count($joined, "\x1b[7m"), 'both carets render as reverse-video cells');
        $this->assertStringNotContainsString("\x1b_pi:c", $joined, 'the hardware-cursor bar is suppressed in multi-caret mode');
    }

    public function testMouseColumnSelectionBlockCopyAndAtomicCut(): void
    {
        $editor = $this->editor("aaaa\nbb\ncccc\n");
        $editor->render(new RenderContext(80, 10));
        $copied = null;
        $editor->setCallbacks(new EditorCallbacks(
            onCopy: function (string $text) use (&$copied): void {
                $copied = $text;
            },
        ));
        $editor->beginColumnSelectionAt(0, 6 + 1);
        $editor->extendColumnSelectionTo(2, 6 + 3);

        $editor->handleInput("\x03"); // ctrl+c: block copy joins rows with newlines
        $this->assertSame("aa\nb\ncc", $copied);

        $editor->handleInput("\x18"); // ctrl+x: delete the block
        $this->assertSame("aa\nb\ncc\n", $editor->getDocument()->getText());

        $editor->handleInput("\x1a"); // ctrl+z: one atomic undo restores every row
        $this->assertSame("aaaa\nbb\ncccc\n", $editor->getDocument()->getText());
    }
}
