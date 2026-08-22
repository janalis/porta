<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget;

use PHPUnit\Framework\TestCase;
use Porta\Editor\Caret;
use Porta\Tests\Support\EditorState;
use Porta\Tests\Support\FakeEditorFileActions;
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
        $this->assertTrue(EditorState::multiCaret($editor)->hasMultiple());
        $this->assertCount(2, EditorState::multiCaret($editor)->carets());

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
        $this->assertCount(2, EditorState::multiCaret($editor)->carets(), '3 carets active');

        $editor->handleInput('X'); // edit at every caret

        // Undo brings back both the text and every secondary caret (previously lost).
        $editor->handleInput("\x1a"); // ctrl+z
        $this->assertSame("aaa\nbbb\nccc\n", $editor->getDocument()->getText());
        $this->assertTrue(EditorState::multiCaret($editor)->hasMultiple(), 'the secondary carets are restored on undo');
        $this->assertCount(2, EditorState::multiCaret($editor)->carets());

        // Redo re-applies the edit and its carets (the restore hook fires either way).
        $editor->getDocument()->redo();
        $this->assertSame("Xaaa\nXbbb\nXccc\n", $editor->getDocument()->getText());
        $this->assertCount(2, EditorState::multiCaret($editor)->carets(), 'redo restores the carets too');
    }

    public function testTabInsertsAtEveryCaretNotBlockIndent(): void
    {
        $editor = $this->editor("aa\nbb\ncc\n");
        $editor->getDocument()->setCursor(0, 0);
        $editor->handleInput("\x1b[1;7B"); // add caret below
        $editor->handleInput("\x1b[1;7B");
        $this->assertCount(2, EditorState::multiCaret($editor)->carets());

        $editor->handleInput("\t"); // multi-caret Tab inserts a unit at each caret
        $this->assertSame("    aa\n    bb\n    cc\n", $editor->getDocument()->getText());
    }

    public function testAddCaretAbove(): void
    {
        $editor = $this->editor("aaa\nbbb\nccc\n");
        $editor->getDocument()->setCursor(2, 0);

        $editor->handleInput("\x1b[1;7A"); // ctrl+alt+up
        $this->assertCount(1, EditorState::multiCaret($editor)->carets());
        $editor->handleInput('Z');
        $this->assertSame("aaa\nZbbb\nZccc\n", $editor->getDocument()->getText());
    }

    public function testAddCaretAtDocumentEdgeIsIgnored(): void
    {
        $editor = $this->editor("only\n");
        $editor->getDocument()->setCursor(0, 0);
        $editor->handleInput("\x1b[1;7A"); // nothing above
        $this->assertFalse(EditorState::multiCaret($editor)->hasMultiple());
    }

    public function testAddNextOccurrence(): void
    {
        $editor = $this->editor("foo bar foo baz foo\n");
        $editor->getDocument()->setCursor(0, 0);

        $editor->handleInput("\x1bj"); // alt+j
        $editor->handleInput("\x1bj");
        $this->assertCount(2, EditorState::multiCaret($editor)->carets());
    }

    public function testAddNextOccurrenceWithNoWordIsNoOp(): void
    {
        $editor = $this->editor("   \n");
        $editor->getDocument()->setCursor(0, 1);
        $editor->handleInput("\x1bj");
        $this->assertFalse(EditorState::multiCaret($editor)->hasMultiple());
    }

    public function testSelectAllOccurrences(): void
    {
        $editor = $this->editor("xy = xy + xy\n");
        $editor->getDocument()->setCursor(0, 0); // on first "xy"

        $editor->handleInput("\x1b[106;8u"); // ctrl+alt+shift+j (CSI-u)
        $this->assertSame(2, \count(EditorState::multiCaret($editor)->carets()), 'three occurrences -> primary + 2 extras');

        $editor->handleInput('!');
        $this->assertSame("xy! = xy! + xy!\n", $editor->getDocument()->getText());
    }

    public function testSelectAllOccurrencesWithNoWordIsNoOp(): void
    {
        $editor = $this->editor("   \n");
        $editor->getDocument()->setCursor(0, 1);
        $editor->handleInput("\x1b[106;8u");
        $this->assertFalse(EditorState::multiCaret($editor)->hasMultiple());
    }

    public function testAltClickRemovesAMiddleCaretSkippingTheEarlierOne(): void
    {
        $editor = $this->editor("aaa\nbbb\nccc\nddd\n");
        $editor->render(new RenderContext(80, 10)); // establish the gutter width (6)
        $editor->getDocument()->setCursor(0, 0);
        $editor->handleInput("\x1b[1;7B"); // extra caret on line 0, primary → line 1
        $editor->handleInput("\x1b[1;7B"); // extras: lines 0 and 1, primary → line 2
        $this->assertCount(2, EditorState::multiCaret($editor)->carets());

        // Alt+click the second extra caret (line 1). Toggling it iterates past the
        // line-0 extra (a non-match → skipped) before removing the line-1 one.
        $editor->addOrRemoveCaretAt(1, 6); // relRow 1 → line 1, relCol 6 → col 0
        $extras = EditorState::multiCaret($editor)->carets();
        $this->assertCount(1, $extras, 'the middle caret was removed, the line-0 one stays');
        $this->assertSame(0, $extras[0]->line, 'the surviving extra is the line-0 caret the loop skipped over');
    }

    public function testMovementMovesAllCaretsAndDedupes(): void
    {
        $editor = $this->editor("abc\nabc\n");
        $editor->getDocument()->setCursor(0, 0);
        $editor->handleInput("\x1b[1;7B"); // caret on line 1 too
        $editor->handleInput("\x1b[C"); // right: both carets move to col 1

        $this->assertSame(1, $editor->getDocument()->getCursorCol());
        $this->assertCount(1, EditorState::multiCaret($editor)->carets());
    }

    public function testEscapeCollapsesToSingleCaret(): void
    {
        $editor = $this->editor("aaa\nbbb\n");
        $editor->getDocument()->setCursor(0, 0);
        $editor->handleInput("\x1b[1;7B");
        $this->assertTrue(EditorState::multiCaret($editor)->hasMultiple());

        $editor->handleInput("\x1b"); // escape
        $this->assertFalse(EditorState::multiCaret($editor)->hasMultiple());
    }

    public function testStrayInputLeavesCaretsIntact(): void
    {
        $editor = $this->editor("word here\nword there\n");
        $editor->getDocument()->setCursor(0, 0);
        $editor->handleInput("\x1b[1;7B");
        $this->assertTrue(EditorState::multiCaret($editor)->hasMultiple());

        // A stray terminal escape sequence (e.g. a focus report) that multi-caret
        // mode does not own must NOT collapse the carets — they are sticky.
        $editor->handleInput("\x1b[I");
        $this->assertTrue(EditorState::multiCaret($editor)->hasMultiple(), 'stray input keeps the carets');

        // Escape still collapses deliberately.
        $editor->handleInput("\x1b");
        $this->assertFalse(EditorState::multiCaret($editor)->hasMultiple());
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

        $this->assertTrue(EditorState::multiCaret($editor)->hasMultiple());
        $carets = EditorState::multiCaret($editor)->carets();
        $this->assertCount(2, $carets, 'rows 0 and 1 are extras; the caret row 2 is primary');
        // The short middle line "bb" clamps its selection to the line end.
        $this->assertEquals(new Caret(1, 2, 1, 1), $carets[1]);
    }

    public function testMouseColumnSelectionZeroWidthMakesVerticalCarets(): void
    {
        $editor = $this->editor("aaaa\nbbbb\ncccc\n");
        $editor->render(new RenderContext(80, 10));
        $editor->beginColumnSelectionAt(0, 6 + 2);
        $editor->extendColumnSelectionTo(2, 6 + 2); // same column: a vertical caret stack, no selection

        $this->assertTrue(EditorState::multiCaret($editor)->hasMultiple());
        $this->assertFalse($editor->getDocument()->hasSelection(), 'the caret row has an empty selection');
        foreach (EditorState::multiCaret($editor)->carets() as $caret) {
            $this->assertSame($caret->col, $caret->anchorCol);
            $this->assertSame($caret->line, $caret->anchorLine);
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
        foreach (EditorState::multiCaret($editor)->carets() as $caret) {
            $this->assertSame($caret->col, $caret->anchorCol, 'carets collapse to points after the edit');
        }
    }

    private function blockCopy(CodeEditorWidget $editor): string
    {
        $copied = '';
        $editor->setCallbacks(new EditorCallbacks(
            file: new FakeEditorFileActions(
                copy: static function (string $text) use (&$copied): void {
                    $copied = $text;
                },
            ),
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
        $this->assertSame(1, substr_count($rendered[0], needle: "\x1b[7m"), 'end-of-line caret on line 0 is visible');
        $this->assertSame(1, substr_count($rendered[1], needle: "\x1b[7m"), 'end-of-line caret on line 1 is visible');
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
        $this->assertSame(2, substr_count($joined, needle: "\x1b[7m"), 'both carets render as reverse-video cells');
        $this->assertStringNotContainsString("\x1b_pi:c", $joined, 'the hardware-cursor bar is suppressed in multi-caret mode');
    }

    public function testMouseColumnSelectionBlockCopyAndAtomicCut(): void
    {
        $editor = $this->editor("aaaa\nbb\ncccc\n");
        $editor->render(new RenderContext(80, 10));
        $copied = null;
        $editor->setCallbacks(new EditorCallbacks(
            file: new FakeEditorFileActions(
                copy: static function (string $text) use (&$copied): void {
                    $copied = $text;
                },
            ),
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

    public function testCaretsThatOutliveTheTextTheyPointedAtAreDropped(): void
    {
        // A wholesale document swap — the filesystem reconciler reloading a
        // file changed on disk, a project-wide replace, a plugin restoring a
        // snapshot — moves every position out from under the extra carets, and
        // Document::replaceAll() has no way to reach them: they are the
        // widget's. Left alone the next keystroke writes at a line that no
        // longer exists.
        $editor = $this->editor("one\ntwo\nthree\nfour\n");
        $editor->getDocument()->setCursor(0, 0);
        $editor->handleInput("\x1b[1;7B"); // ctrl+alt+down ×3 → extras on lines 0, 1, 2
        $editor->handleInput("\x1b[1;7B");
        $editor->handleInput("\x1b[1;7B");
        $this->assertCount(3, EditorState::multiCaret($editor)->carets());

        $editor->getDocument()->replaceAll("tiny\n"); // two lines: "tiny" and the empty tail

        $carets = EditorState::multiCaret($editor)->carets();
        $this->assertCount(2, $carets, 'the caret addressing line 2 is gone; the two that still have text stay');
        foreach ($carets as $caret) {
            $this->assertLessThan($editor->getDocument()->getLineCount(), $caret->line);
        }
    }

    public function testACaretWhoseLineSurvivesIsClampedRatherThanDropped(): void
    {
        // The user's second cursor is still on that line; only the column moved
        // out from under it.
        $editor = $this->editor("aaaaaaaa\nbbbbbbbb\n");
        $editor->getDocument()->setCursor(1, 8);
        $editor->handleInput("\x1b[1;7B"); // the extra stays at line 1, column 8
        $this->assertSame(8, EditorState::multiCaret($editor)->carets()[0]->col);

        $editor->getDocument()->replaceAll("aaaaaaaa\nbb\n");

        $carets = EditorState::multiCaret($editor)->carets();
        $this->assertCount(1, $carets, 'the line is still there');
        $this->assertSame(2, $carets[0]->col, 'clamped to the shorter line');
    }

    public function testTheAliveFilterFollowsBothTheDocumentAndTheCaretSet(): void
    {
        // The filter is memoised because it runs several times a frame, so the
        // memo has to see BOTH kinds of change: the document moving under the
        // carets, and the caret set itself moving.
        $editor = $this->editor("aaaa\nbbbb\ncccc\n");
        $editor->getDocument()->setCursor(1, 4);
        $editor->handleInput("\x1b[1;7B"); // an extra at line 1, column 4
        $this->assertSame(4, EditorState::multiCaret($editor)->carets()[0]->col);

        $editor->getDocument()->replaceAll("aaaa\nbb\ncccc\n"); // same lines, shorter line 1
        $this->assertSame(2, EditorState::multiCaret($editor)->carets()[0]->col, 'the document changed under a cached answer');

        $editor->handleInput("\x1b[1;7B"); // a second extra, same document
        $this->assertCount(2, EditorState::multiCaret($editor)->carets(), 'and the caret set changed without it');
    }

    public function testAliveCacheServesTheSameResultOnAnUnchangedRepeatedRead(): void
    {
        $editor = $this->editor("aaaa\nbbbb\n");
        $editor->getDocument()->setCursor(0, 0);
        $editor->handleInput("\x1b[1;7B"); // one extra

        $first = EditorState::multiCaret($editor)->carets();
        $second = EditorState::multiCaret($editor)->carets(); // same version, same extras: a cache hit
        $this->assertEquals($first, $second);
    }

    public function testExtraOutOfBoundsOnItsOwnLineIsDroppedEvenWithAValidAnchor(): void
    {
        $editor = $this->editor("aaaa\nbbbb\n");
        $lines = $editor->getDocument()->getLineCount();
        EditorState::multiCaret($editor)->setExtras([new Caret($lines, 0, $lines - 1, 0)]); // line out of bounds, anchorLine is not

        $this->assertSame([], EditorState::multiCaret($editor)->carets(), 'the line side alone must drop the caret');
    }

    public function testExtraOutOfBoundsOnItsAnchorLineIsDroppedEvenWithAValidLine(): void
    {
        $editor = $this->editor("aaaa\nbbbb\n");
        $lines = $editor->getDocument()->getLineCount();
        EditorState::multiCaret($editor)->setExtras([new Caret($lines - 1, 0, $lines, 0)]); // line is valid, anchorLine is out of bounds

        $this->assertSame([], EditorState::multiCaret($editor)->carets(), 'the anchor side alone must drop the caret too');
    }

    public function testAnInvalidCaretIsSkippedNotStoppingTheRestOfTheFilter(): void
    {
        $editor = $this->editor("aaaa\nbbbb\n"); // 2 lines
        EditorState::multiCaret($editor)->setExtras([
            Caret::point(0, 0),
            Caret::point(5, 0), // out of bounds, in the middle of the list
            Caret::point(1, 0),
        ]);

        $this->assertCount(2, EditorState::multiCaret($editor)->carets(), 'both valid carets survive, before and after the invalid one');
    }

    public function testClampingOnlyReclampsTheSideThatActuallyMoved(): void
    {
        $editor = $this->editor("aaaaaaaa\nbb\n"); // line 0 is 8 wide, line 1 is 2 wide
        EditorState::multiCaret($editor)->setExtras([
            new Caret(1, 8, 0, 8), // caret col needs clamping (8 -> 2), anchor col does not
            new Caret(0, 3, 1, 8), // anchor col needs clamping (8 -> 2), caret col does not
        ]);

        $carets = EditorState::multiCaret($editor)->carets();
        $this->assertSame(2, $carets[0]->col, 'the caret side was clamped');
        $this->assertSame(8, $carets[0]->anchorCol, 'the anchor side was untouched');
        $this->assertSame(3, $carets[1]->col, 'the caret side was untouched');
        $this->assertSame(2, $carets[1]->anchorCol, 'the anchor side was clamped');
    }

    public function testAddingACaretBelowTheLastLineIsANoOp(): void
    {
        $editor = $this->editor("aaa\nbbb\nccc\n");
        $editor->getDocument()->setCursor($editor->getDocument()->getLineCount() - 1, 0); // the actual last line

        $editor->handleInput("\x1b[1;7B"); // ctrl+alt+down: nothing below the last line

        $this->assertFalse(EditorState::multiCaret($editor)->hasMultiple());
    }

    public function testAddNextOccurrenceSkipsAnOccurrenceExactlyAtTheCursor(): void
    {
        $editor = $this->editor("foo foo\n");
        $editor->getDocument()->setCursor(0, 4); // at the start of the second "foo"

        $editor->handleInput("\x1bj"); // alt+j: the only remaining occurrence sits at the cursor itself

        $this->assertFalse(EditorState::multiCaret($editor)->hasMultiple(), 'an occurrence AT the cursor is not a new one to jump to');
        $this->assertSame(4, $editor->getDocument()->getCursorCol());
    }

    public function testAddNextOccurrenceDedupesAgainstAPreExistingExtraAtTheSameSpot(): void
    {
        $editor = $this->editor("foo foo foo\n");
        EditorState::multiCaret($editor)->setExtras([Caret::point(0, 0)]); // pre-seed an extra where the primary already sits
        $editor->getDocument()->setCursor(0, 0);

        $editor->handleInput("\x1bj"); // alt+j: old primary (0,0) joins extras, colliding with the pre-seeded one

        $this->assertCount(1, EditorState::multiCaret($editor)->carets(), 'the duplicate at (0,0) was deduped away');
    }

    public function testEscapeClearsThePrimarySelectionAsWellAsTheExtraCarets(): void
    {
        $editor = $this->editor("hello\nworld\n");
        $editor->getDocument()->setCursor(0, 0);
        $editor->handleInput("\x1b[1;7B"); // one extra
        $editor->handleInput("\x1b[1;2C"); // shift+right: primary now has a real selection too

        $editor->handleInput("\x1b"); // escape

        $this->assertFalse(EditorState::multiCaret($editor)->hasMultiple());
        $this->assertFalse($editor->getDocument()->hasSelection(), 'the primary selection is cleared too, not just the extras');
    }

    public function testPasteInsertsTheExactClipboardTextAtEveryCaret(): void
    {
        $editor = $this->editor("a\na\n");
        $editor->getDocument()->setCursor(0, 0);
        $editor->handleInput("\x1b[1;2C"); // shift+right selects the single char "a" (single caret, plain copy path)
        $editor->handleInput("\x03"); // ctrl+c copies exactly "a"
        $editor->getDocument()->setCursor(0, 0); // collapse the selection
        $editor->handleInput("\x1b[1;7B"); // ctrl+alt+down: extra at (0,0), primary at (1,0)

        $editor->handleInput("\x16"); // ctrl+v paste at both carets

        $this->assertSame("aa\naa\n", $editor->getDocument()->getText(), 'the exact clipboard text landed at each caret, not just some "a"-ish text');
    }

    public function testMoveAllMovesEachCaretFromItsOwnPositionNotALeftoverOne(): void
    {
        $editor = $this->editor("abcdef\nab\n");
        $editor->getDocument()->setCursor(0, 4);
        EditorState::multiCaret($editor)->setExtras([Caret::point(1, 1)]);

        $editor->handleInput("\x1b[C"); // right: each caret must move from ITS OWN position

        $this->assertSame(5, $editor->getDocument()->getCursorCol(), 'the primary moved from column 4');
        $carets = EditorState::multiCaret($editor)->carets();
        $this->assertCount(1, $carets);
        $this->assertSame(1, $carets[0]->line);
        $this->assertSame(2, $carets[0]->col, 'the extra moved from ITS OWN column 1, not from a leftover position');
    }

    public function testRebuildFromPositionsSortsBeforePickingTheNewPrimary(): void
    {
        $editor = $this->editor("aaaa\naaaa\naaaa\n");
        $editor->getDocument()->setCursor(2, 0); // primary starts on the LOWEST line's caret is NOT this one
        EditorState::multiCaret($editor)->setExtras([Caret::point(0, 0)]); // an extra above the primary

        $editor->handleInput("\x1b[C"); // right: both move to column 1; the topmost must become the new primary

        $this->assertSame(0, $editor->getDocument()->getCursorLine(), 'line 0 is the topmost result and must be the new primary');
        $this->assertSame(1, $editor->getDocument()->getCursorCol());
        $carets = EditorState::multiCaret($editor)->carets();
        $this->assertCount(1, $carets);
        $this->assertSame(2, $carets[0]->line, 'line 2 is left as the sole extra');
    }

    public function testConvergingCaretsCollapseToASingleCaretNotGhostDuplicates(): void
    {
        $editor = $this->editor("xxxxx\n");
        $editor->getDocument()->setCursor(0, 5);
        EditorState::multiCaret($editor)->setExtras([Caret::point(0, 2), Caret::point(0, 4)]);

        $editor->handleInput("\x1b[H"); // Home: every caret converges on column 0

        $this->assertFalse(EditorState::multiCaret($editor)->hasMultiple(), 'three carets collapsed into exactly one, no duplicate ghosts left over');
    }

    public function testTogglingACaretExactlyAtThePrimaryPromotesTheFirstExtraAndMovesTheCursorToIt(): void
    {
        $editor = $this->editor("abc\ndef\n");
        $editor->getDocument()->setCursor(0, 0);
        EditorState::multiCaret($editor)->setExtras([Caret::point(1, 1)]);

        EditorState::multiCaret($editor)->toggleCaretAt(0, 0); // click on the primary's own spot

        $this->assertSame(1, $editor->getDocument()->getCursorLine(), 'the cursor moved to the promoted extra');
        $this->assertSame(1, $editor->getDocument()->getCursorCol());
        $this->assertFalse(EditorState::multiCaret($editor)->hasMultiple(), 'the sole extra was promoted away, none left');
    }

    public function testTogglingOffOneExtraCaretLeavesItsNeighboursIntact(): void
    {
        $editor = $this->editor("aaa\nbbb\nccc\nddd\n");
        $editor->getDocument()->setCursor(3, 0); // primary elsewhere, not an extra
        EditorState::multiCaret($editor)->setExtras([Caret::point(0, 0), Caret::point(1, 0), Caret::point(2, 0)]);

        EditorState::multiCaret($editor)->toggleCaretAt(1, 0); // remove only the middle one

        $carets = EditorState::multiCaret($editor)->carets();
        $this->assertCount(2, $carets, 'only the targeted caret was removed, not its neighbour too');
        $this->assertSame(0, $carets[0]->line);
        $this->assertSame(2, $carets[1]->line);
    }

    public function testDedupeDetectsCollidingExtrasEvenWhenTheirDigitsWouldConcatenateTheSame(): void
    {
        // Without the ':' separator in the dedupe key, (line=1, col=23) and
        // (line=12, col=3) both stringify to "123" and would wrongly collide.
        $editor = $this->editor(str_repeat(string: str_repeat(string: 'x', times: 30)."\n", times: 25));
        EditorState::multiCaret($editor)->setExtras([Caret::point(1, 23), Caret::point(12, 3)]);
        $editor->getDocument()->setCursor(20, 0);

        $editor->handleInput("\x1b[1;7B"); // ctrl+alt+down: pushes (20,0) into extras and runs dedupeCarets()

        $carets = EditorState::multiCaret($editor)->carets();
        $this->assertCount(3, $carets, 'all three distinct positions survive the dedupe pass');
    }

    public function testDedupeRemovesAnExtraThatCoincidesWithTheNewPrimary(): void
    {
        $editor = $this->editor("aaa\nbbb\nccc\n");
        $editor->getDocument()->setCursor(0, 0);
        // Pre-seed an extra at exactly where ctrl+alt+down's new primary will land (1, 0).
        EditorState::multiCaret($editor)->setExtras([Caret::point(1, 0)]);

        $editor->handleInput("\x1b[1;7B"); // ctrl+alt+down: old primary (0,0) also joins as an extra

        $carets = EditorState::multiCaret($editor)->carets();
        $this->assertCount(1, $carets, 'the pre-seeded extra coinciding with the new primary was deduped away');
        $this->assertSame(0, $carets[0]->line);
        $this->assertSame(0, $carets[0]->col, 'only the distinct old-primary extra survives');
    }

    public function testCurrentCaretWordCapturesTheFullWordNotJustTheLastCharacterBeforeTheCursor(): void
    {
        $editor = $this->editor("foobar xxxx foobar\n");
        $editor->getDocument()->setCursor(0, 4); // mid-word, inside the first "foobar"

        $editor->handleInput("\x1bj"); // alt+j: must recognise the FULL word "foobar", not just "ob"

        $this->assertTrue(EditorState::multiCaret($editor)->hasMultiple());
        $this->assertSame(0, $editor->getDocument()->getCursorLine());
        $this->assertSame(18, $editor->getDocument()->getCursorCol(), 'landed after the second "foobar" (col 12 + 6), not a false match from a truncated word');
    }

    public function testRebuildFromRecordsDedupesCollidingRecordsEvenWhenTheirDigitsWouldConcatenateTheSame(): void
    {
        // Same "123" collision as the dedupeCarets test above, but exercised
        // through extendAll()/rebuildFromRecords() (shift+movement) instead.
        $editor = $this->editor(str_repeat(string: str_repeat(string: 'x', times: 30)."\n", times: 25));
        EditorState::multiCaret($editor)->setExtras([Caret::point(1, 22), Caret::point(12, 2)]);
        $editor->getDocument()->setCursor(20, 0);

        $editor->handleInput("\x1b[1;2C"); // shift+right: each caret becomes (1,23), (12,3), (20,1)

        $this->assertCount(2, EditorState::multiCaret($editor)->carets(), 'the (1,23) and (12,3) records must not collide as duplicates');
    }

    public function testRebuildFromRecordsKeepsTwoDistinctColumnsOnTheSameLineApart(): void
    {
        $editor = $this->editor(str_repeat(string: str_repeat(string: 'x', times: 30)."\n", times: 5));
        EditorState::multiCaret($editor)->setExtras([Caret::point(1, 5)]);
        $editor->getDocument()->setCursor(1, 0); // same line, different column

        $editor->handleInput("\x1b[1;2C"); // shift+right: (1,1) and (1,6) — same line, must not collide

        $this->assertCount(1, EditorState::multiCaret($editor)->carets(), 'the two same-line records at different columns are both kept');
    }

    public function testCurrentCaretWordConcatenatesLeftBeforeRightNotReversed(): void
    {
        $editor = $this->editor("foobar xxx barfoo foobar\n");
        $editor->getDocument()->setCursor(0, 3); // mid-word inside "foobar": left="foo", right="bar"

        $editor->handleInput("\x1bj"); // alt+j: the word must be "foobar", not "barfoo"

        $this->assertSame(0, $editor->getDocument()->getCursorLine());
        $this->assertSame(24, $editor->getDocument()->getCursorCol(), 'landed after the second "foobar" (col 18 + 6), nowhere near "barfoo" at col 11');
    }
}
