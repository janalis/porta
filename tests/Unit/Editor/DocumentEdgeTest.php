<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\TestCase;
use Porta\Editor\CommentSyntax;
use Porta\Editor\Document;
use Porta\Editor\SelectionMode;

/**
 * Boundary and branch coverage for Document beyond the happy paths.
 */
final class DocumentEdgeTest extends TestCase
{
    public function testMovementAtDocumentBoundaries(): void
    {
        $doc = new Document("ab\ncd");

        $this->assertFalse($doc->moveLeft(), 'left at (0,0)');
        $doc->setCursor(0, 2);
        $this->assertTrue($doc->moveRight(), 'right at EOL crosses to next line');
        $this->assertSame([1, 0], [$doc->getCursorLine(), $doc->getCursorCol()]);
        $this->assertTrue($doc->moveLeft(), 'left at BOL crosses back');
        $this->assertSame([0, 2], [$doc->getCursorLine(), $doc->getCursorCol()]);

        $doc->setCursor(1, 2);
        $this->assertFalse($doc->moveRight(), 'right at document end');
    }

    public function testMoveUpOnFirstLine(): void
    {
        $doc = new Document('abc');
        $doc->setCursor(0, 2);

        $this->assertTrue($doc->moveUp(), 'jumps to column 0');
        $this->assertSame(0, $doc->getCursorCol());
        $this->assertFalse($doc->moveUp(), 'already at (0,0)');
    }

    public function testMoveDownOnLastLine(): void
    {
        $doc = new Document('abc');
        $doc->setCursor(0, 1);

        $this->assertTrue($doc->moveDown(), 'jumps to line end');
        $this->assertSame(3, $doc->getCursorCol());
        $this->assertFalse($doc->moveDown(), 'already at end');
    }

    public function testWordMovementFallbacks(): void
    {
        $doc = new Document("  \nword");
        $doc->setCursor(0, 2); // end of whitespace-only line
        $this->assertTrue($doc->moveWordLeft(), 'whitespace-only prefix falls back to column 0');
        $this->assertSame(0, $doc->getCursorCol());

        $doc->setCursor(0, 0);
        $this->assertFalse($doc->moveWordLeft(), 'at col 0 delegates to moveLeft, false at document start');

        $doc->setCursor(0, 2);
        $this->assertTrue($doc->moveWordRight(), 'at EOL delegates to moveRight, crossing lines');
        $this->assertSame(1, $doc->getCursorLine());

        $punct = new Document('a +*/- b');
        $punct->setCursor(0, 2);
        $punct->moveWordRight();
        $this->assertSame(6, $punct->getCursorCol(), 'punctuation run is one word');
    }

    public function testWordRightOnTrailingSpaces(): void
    {
        $doc = new Document('ab   ');
        $doc->setCursor(0, 2);

        $this->assertTrue($doc->moveWordRight());
        $this->assertSame(5, $doc->getCursorCol(), 'trailing spaces: jump to EOL');
    }

    public function testLineEndAndStartNoOps(): void
    {
        $doc = new Document('abc');
        $doc->setCursor(0, 3);
        $this->assertFalse($doc->moveToLineEnd(), 'already at end');

        $blank = new Document('    ');
        $blank->setCursor(0, 2);
        $blank->moveToLineStart();
        $this->assertSame(0, $blank->getCursorCol(), 'all-blank line goes straight to 0');
    }

    public function testSelectionEdgeCases(): void
    {
        $doc = new Document('abc');
        $doc->beginSelect();
        $doc->beginSelect(); // second call keeps the original anchor
        $this->assertNull($doc->getSelectionRange(), 'anchor == cursor is an empty selection');
        $this->assertSame('', $doc->getSelectedText());
        $this->assertFalse($doc->deleteSelection(), 'empty selection deletes nothing');
        $this->assertFalse($doc->hasSelection());

        // Reversed selection (cursor before anchor) normalizes
        $doc->setCursor(0, 3);
        $doc->beginSelect();
        $doc->setCursor(0, 1, selection: SelectionMode::Keep);
        $this->assertSame('bc', $doc->getSelectedText());
    }

    public function testSelectionEndingAtColumnZeroExcludesThatLine(): void
    {
        $doc = new Document("one\ntwo\nthree");
        $doc->setCursor(0, 0);
        $doc->beginSelect();
        $doc->setCursor(1, 0, selection: SelectionMode::Keep); // selects line 0 + newline only

        $doc->duplicateLines();
        $this->assertSame(['one', 'one', 'two', 'three'], $doc->getLines(), 'line "two" not part of the block');
    }

    public function testInsertReplacesSelection(): void
    {
        $doc = new Document('hello world');
        $doc->setCursor(0, 0);
        $doc->beginSelect();
        $doc->setCursor(0, 5, selection: SelectionMode::Keep);

        $doc->insert('goodbye');
        $this->assertSame('goodbye world', $doc->getLine(0));
    }

    public function testInsertNewLineReplacesSelection(): void
    {
        $doc = new Document('hello world');
        $doc->setCursor(0, 5);
        $doc->beginSelect();
        $doc->setCursor(0, 11, selection: SelectionMode::Keep);

        $doc->insertNewLine();
        $this->assertSame(['hello', ''], $doc->getLines());
    }

    public function testDeleteOperationsConsumeSelectionFirst(): void
    {
        foreach (['deleteBackward', 'deleteForward', 'deleteWordBackward', 'deleteToLineEnd'] as $method) {
            $doc = new Document('hello world');
            $doc->setCursor(0, 0);
            $doc->beginSelect();
            $doc->setCursor(0, 6, selection: SelectionMode::Keep);

            $this->assertTrue($doc->{$method}(), $method);
            $this->assertSame('world', $doc->getLine(0), $method.' removed only the selection');
        }
    }

    public function testInsertEmptyStringIsNoOp(): void
    {
        $doc = new Document('a');
        $version = $doc->getVersion();
        $doc->insert('');
        $this->assertSame($version, $doc->getVersion());
    }

    public function testDeleteToLineEndAtEolMergesLines(): void
    {
        $doc = new Document("ab\ncd");
        $doc->setCursor(0, 2);

        $this->assertTrue($doc->deleteToLineEnd());
        $this->assertSame('abcd', $doc->getLine(0));
    }

    public function testDeleteWordBackwardAtColumnZero(): void
    {
        $doc = new Document("ab\ncd");
        $doc->setCursor(1, 0);

        $this->assertTrue($doc->deleteWordBackward(), 'delegates to deleteBackward, merging lines');
        $this->assertSame('abcd', $doc->getLine(0));
    }

    public function testDeleteAllSelectedLinesLeavesOneEmptyLine(): void
    {
        $doc = new Document("a\nb");
        $doc->selectAll();

        $this->assertTrue($doc->deleteLines());
        $this->assertSame([''], $doc->getLines());
    }

    public function testMoveLinesUpAtTopFails(): void
    {
        $doc = new Document("a\nb");
        $doc->setCursor(0, 0);

        $this->assertFalse($doc->moveLines(-1));
    }

    public function testMoveLinesWithSelectionMovesBlockAndAnchor(): void
    {
        $doc = new Document("a\nb\nc\nd");
        $doc->setCursor(1, 0);
        $doc->beginSelect();
        $doc->setCursor(2, 1, selection: SelectionMode::Keep);

        $this->assertTrue($doc->moveLines(1));
        $this->assertSame(['a', 'd', 'b', 'c'], $doc->getLines());
        $this->assertSame('b\nc', \str_replace("\n", replace: '\n', subject: $doc->getSelectedText()), 'selection follows the block');
    }

    public function testDuplicateSelectionBlock(): void
    {
        $doc = new Document("a\nb\nc");
        $doc->setCursor(0, 0);
        $doc->beginSelect();
        $doc->setCursor(1, 1, selection: SelectionMode::Keep);

        $doc->duplicateLines();
        $this->assertSame(['a', 'b', 'a', 'b', 'c'], $doc->getLines());
    }

    public function testToggleCommentSkipsBlankLines(): void
    {
        $doc = new Document("code\n\nmore");
        $doc->setCursor(0, 0);
        $doc->beginSelect();
        $doc->setCursor(2, 4, selection: SelectionMode::Keep);

        $doc->toggleComment(CommentSyntax::lineOnly('//'));
        $this->assertSame(['// code', '', '// more'], $doc->getLines());

        $doc->toggleComment(CommentSyntax::lineOnly('//'));
        $this->assertSame(['code', '', 'more'], $doc->getLines());
    }

    public function testCoalescingBreaksOnDifferentEditKinds(): void
    {
        $doc = new Document('');
        $doc->insert('ab'); // typing group 1
        $doc->deleteBackward();
        $doc->insert('c'); // typing group 2 (backspace broke the run)

        $doc->undo();
        $this->assertSame('a', $doc->getText());
        $doc->undo();
        $this->assertSame('ab', $doc->getText());
        $doc->undo();
        $this->assertSame('', $doc->getText());
    }

    public function testBackspaceRunsCoalesce(): void
    {
        $doc = new Document('abcd');
        $doc->moveToDocumentEnd();
        $doc->deleteBackward();
        $doc->deleteBackward();

        $doc->undo();
        $this->assertSame('abcd', $doc->getText(), 'both backspaces undone together');
    }

    public function testUndoCapDropsOldestSnapshot(): void
    {
        $doc = new Document('');
        for ($i = 0; $i < 205; ++$i) {
            $doc->insertNewLine(); // non-coalescing edit
        }

        $this->assertGreaterThan(1, $doc->getLineCount(), 'earliest edits fell off the undo stack');
    }

    public function testReplaceBeforeCursorClampsToLineStart(): void
    {
        $doc = new Document('ab');
        $doc->setCursor(0, 2);

        $doc->replaceBeforeCursor(99, 'XY');
        $this->assertSame('XY', $doc->getLine(0));
    }

    public function testGetWordBeforeCursorWithoutWord(): void
    {
        $doc = new Document('foo ');
        $doc->moveToDocumentEnd();
        $this->assertSame('', $doc->getWordBeforeCursor());
    }

    public function testCursorDisplayColWithWideChars(): void
    {
        $doc = new Document('日本x');
        $doc->setCursor(0, 6); // after two 3-byte CJK chars

        $this->assertSame(4, $doc->getCursorDisplayCol(), 'two double-width cells');
    }

    public function testGoalColumnOnUnicodeLines(): void
    {
        $doc = new Document("日本語です\nab\n日本語です");
        $doc->setCursor(0, 9); // after 3 CJK chars = display col 6

        $doc->moveDown();
        $this->assertSame(2, $doc->getCursorCol());
        $doc->moveDown();
        $this->assertSame(9, $doc->getCursorCol(), 'goal display column restored on unicode line');
    }

    public function testMoveLeftWithinLineStepsOverUtf8(): void
    {
        $doc = new Document('héllo');
        $doc->setCursor(0, 3); // after "hé"

        $this->assertTrue($doc->moveLeft());
        $this->assertSame(1, $doc->getCursorCol(), 'steps over the 2-byte é in one move');
        $this->assertTrue($doc->moveLeft());
        $this->assertSame(0, $doc->getCursorCol());
    }

    public function testMoveUpFromSecondLine(): void
    {
        $doc = new Document("first\nsecond");
        $doc->setCursor(1, 3);

        $this->assertTrue($doc->moveUp());
        $this->assertSame([0, 3], [$doc->getCursorLine(), $doc->getCursorCol()]);
    }

    public function testMoveToLineStartNoOpAtColumnZero(): void
    {
        $doc = new Document('abc');
        $this->assertFalse($doc->moveToLineStart());
    }

    public function testMoveToDocumentStart(): void
    {
        $doc = new Document("a\nb\nc");
        $doc->moveToDocumentEnd();
        $doc->moveToDocumentStart();

        $this->assertSame([0, 0], [$doc->getCursorLine(), $doc->getCursorCol()]);
    }

    public function testDeleteForwardMidLine(): void
    {
        $doc = new Document('abc');
        $doc->setCursor(0, 1);

        $this->assertTrue($doc->deleteForward());
        $this->assertSame('ac', $doc->getLine(0));
    }

    public function testSelectedTextAcrossThreeLines(): void
    {
        $doc = new Document("one\ntwo\nthree");
        $doc->setCursor(0, 2);
        $doc->beginSelect();
        $doc->setCursor(2, 2, selection: SelectionMode::Keep);

        $this->assertSame("e\ntwo\nth", $doc->getSelectedText());
    }

    public function testGetLineOutOfRange(): void
    {
        $doc = new Document('a');
        $this->assertSame('', $doc->getLine(99));
    }
}
