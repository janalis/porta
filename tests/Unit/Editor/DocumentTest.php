<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\TestCase;
use Porta\Editor\Caret;
use Porta\Editor\CommentSyntax;
use Porta\Editor\Document;
use Porta\Editor\LinePlacement;
use Porta\Editor\SelectionMode;
use Porta\Editor\StatementSyntax;

final class DocumentTest extends TestCase
{
    public function testLoadNormalizesAndCountsLines(): void
    {
        $doc = new Document("<?php\r\n\tfunction hello(): string\r{\n    return 'world';\n}\n");

        $this->assertSame(6, $doc->getLineCount());
        $this->assertSame("\tfunction hello(): string", $doc->getLine(1), 'tabs preserved verbatim; CRLF/CR become LF');
        $this->assertTrue($doc->usesTabs(), 'leading tab detected as tab indentation');
    }

    public function testTabAwareDisplayColumns(): void
    {
        // Tab (4-cell stop) then "ab" -> display columns 0,4,5,6.
        $doc = new Document("\tab");
        $this->assertSame(6, $doc->lineDisplayWidth(0));
        $this->assertSame(0, $doc->displayColAt(0, 0));
        $this->assertSame(4, $doc->displayColAt(0, 1), 'byte 1 (after the tab) is display column 4');
        $this->assertSame(5, $doc->displayColAt(0, 2));

        // Visual line expands the tab to 4 spaces.
        $this->assertSame(['    ab'], $doc->getVisualLines());
        // Cached: same array on the next call at the same version.
        $this->assertSame('    ab', $doc->getVisualLines()[0]);

        // A tab after two cells fills only two cells to reach the next stop.
        $doc2 = new Document("xy\tz");
        $this->assertSame('xy  z', $doc2->getVisualLines()[0]);
        $this->assertSame(4, $doc2->displayColAt(0, 3), 'tab from column 2 lands on column 4');
    }

    public function testTabIndentRoundTripsThroughSave(): void
    {
        $doc = new Document("<?php\n\tfoo();\n");
        $this->assertSame("<?php\n\tfoo();\n", $doc->getText(), 'tabs are not rewritten to spaces');
        $this->assertSame("\t", $doc->indentUnit());
    }

    public function testSpaceIndentedFileUsesSpaceIndentUnit(): void
    {
        $doc = new Document("<?php\n    foo();\n");
        $this->assertFalse($doc->usesTabs());
        $this->assertSame('    ', $doc->indentUnit());
        // No tabs -> visual lines are identical to raw lines.
        $this->assertSame($doc->getLines(), $doc->getVisualLines());
    }

    public function testByteColForDisplayColHandlesTabs(): void
    {
        // "\t" + "ab": display columns 0,4,5,6 map back to byte columns.
        $doc = new Document("\tab");
        $this->assertSame(0, $doc->byteColForDisplayCol(0, 0));
        // A display column inside the tab's cells resolves past the tab.
        $this->assertSame(1, $doc->byteColForDisplayCol(0, 2));
        $this->assertSame(1, $doc->byteColForDisplayCol(0, 4), 'column 4 is the byte after the tab');
        $this->assertSame(2, $doc->byteColForDisplayCol(0, 5));
    }

    public function testVerticalMovementKeepsGoalColumnAcrossTabs(): void
    {
        // Line 0 has a long word; line 1 is tab-indented. Moving down keeps
        // the visual goal column, landing after the tab.
        $doc = new Document("hello\n\tx");
        $doc->setCursor(0, 5); // display column 5 (end of "hello")
        $doc->moveDown();
        $this->assertSame(1, $doc->getCursorLine());
        // Display column 5 on "\tx" (tab=4 cells, then x at 4) -> byte col 2.
        $this->assertSame(2, $doc->getCursorCol());
    }

    public function testAutoIndentUsesTabForTabFile(): void
    {
        $doc = new Document("<?php\n\tif (true) {\n");
        $doc->setCursor(1, \strlen("\tif (true) {"));
        $doc->insertNewLine();
        // New line indents one level deeper with a tab (not spaces).
        $this->assertSame("\t\t", $doc->getLine(2));
    }

    public function testInsertAndDirtyTracking(): void
    {
        $doc = new Document("<?php\n\nfunction hello(): string\n{\n    return 'world';\n}\n");
        $this->assertFalse($doc->isDirty());

        $doc->setCursor(4, 4);
        $doc->insert('$x = 1; ');

        $this->assertStringContainsString('$x = 1; return', $doc->getLine(4));
        $this->assertTrue($doc->isDirty());

        $doc->markSaved();
        $this->assertFalse($doc->isDirty());
    }

    public function testMultiLineInsert(): void
    {
        $doc = new Document('startend');
        $doc->setCursor(0, 5);
        $doc->insert("A\nB\nC");

        $this->assertSame(['startA', 'B', 'Cend'], $doc->getLines());
        $this->assertSame(2, $doc->getCursorLine());
        $this->assertSame(1, $doc->getCursorCol());
    }

    public function testUndoRedo(): void
    {
        $doc = new Document("    return 'world';");
        $doc->setCursor(0, 4);
        $doc->insert('$x = 1; ');

        $this->assertTrue($doc->undo());
        $this->assertSame("    return 'world';", $doc->getLine(0));

        $this->assertTrue($doc->redo());
        $this->assertStringContainsString('$x = 1;', $doc->getLine(0));

        // Exhausted stacks return false
        $doc->undo();
        $this->assertFalse($doc->undo());
        $doc->redo();
        $this->assertFalse($doc->redo());
    }

    public function testTypingCoalescesIntoOneUndoEntry(): void
    {
        $doc = new Document('');
        $doc->insert('a');
        $doc->insert('b');
        $doc->insert('c');

        $doc->undo();
        $this->assertSame('', $doc->getText());
    }

    public function testUndoRestoresTheSelection(): void
    {
        $doc = new Document('hello world');
        $doc->setCursor(0, 0);
        $doc->beginSelect();
        $doc->setCursor(0, 5, selection: SelectionMode::Keep); // select "hello"
        $doc->insert('X'); // replaces the selection

        $this->assertFalse($doc->hasSelection(), 'selection consumed by the edit');
        $this->assertTrue($doc->undo());
        $this->assertSame('hello world', $doc->getText());
        $this->assertTrue($doc->hasSelection(), 'undo restores the selection (previously lost)');
        $range = $doc->getSelectionRange();
        $this->assertSame([0, 0, 0, 5], [$range['startLine'], $range['startCol'], $range['endLine'], $range['endCol']]);
    }

    public function testUndoBackToSavedStateClearsDirty(): void
    {
        $doc = new Document("line\n");
        $this->assertFalse($doc->isDirty(), 'freshly loaded is clean');

        $doc->setCursor(0, 4);
        $doc->insert('!');
        $this->assertTrue($doc->isDirty());

        $doc->undo(); // back to the loaded content
        $this->assertFalse($doc->isDirty(), 'undo to the saved content is clean again');

        // After an explicit save, the save point moves.
        $doc->redo(); // "line!\n"
        $doc->markSaved();
        $this->assertFalse($doc->isDirty());
        $doc->insert('?'); // "line!?\n"
        $this->assertTrue($doc->isDirty());
        $doc->undo(); // back to the just-saved "line!\n"
        $this->assertFalse($doc->isDirty(), 'save is an undo boundary; undo lands on it');
    }

    public function testUndoToSavedIsCleanForACrlfLoadedFile(): void
    {
        // The saved digest must be taken from the NORMALIZED text: undo
        // rebuilds LF-joined lines, and a digest of the raw CRLF bytes would
        // keep this file dirty forever after its first edit.
        $doc = new Document("line\r\nnext\r\n");
        $doc->setCursor(0, 4);
        $doc->insert('!');
        $this->assertTrue($doc->isDirty());

        $doc->undo();
        $this->assertFalse($doc->isDirty(), 'undo to the loaded CRLF content is clean');
    }

    public function testCaretHooksRoundTripThroughASnapshot(): void
    {
        $doc = new Document("l1\nl2\nl3\n");
        $extras = [new Caret(1, 1, 1, 1)];
        $restored = null;
        $doc->setCaretHooks(
            static fn (): array => $extras,
            static function (array $e) use (&$restored): void {
                $restored = $e;
            },
        );

        $doc->setCursor(0, 2);
        $doc->insert('!'); // snapshot captures the current extras
        $doc->undo();

        $this->assertSame($extras, $restored, 'snapshot carried the secondary carets and restore applied them');
    }

    public function testTransactGroupsEditsIntoOneUndoStep(): void
    {
        $doc = new Document('');
        $doc->transact(static function () use ($doc): void {
            $doc->insert('one ');
            $doc->insert('two ');
            $doc->insert('three');
        });
        $this->assertSame('one two three', $doc->getText());

        // A single undo reverts the whole transaction.
        $this->assertTrue($doc->undo());
        $this->assertSame('', $doc->getText());
    }

    public function testTransactClosesCompoundEvenWhenCallbackThrows(): void
    {
        $doc = new Document('');
        // The throw is expected; we only care that transact()'s finally closed the
        // compound group, which the assertions below verify.
        // @mago-expect lint:no-empty-catch-clause
        try {
            $doc->transact(static function () use ($doc): void {
                $doc->insert('x');
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
        }

        // The compound was closed by the finally, so a subsequent edit is its
        // own undo step rather than being swallowed into an open group.
        $doc->insert('y');
        $this->assertSame('xy', $doc->getText());
        $doc->undo();
        $this->assertSame('x', $doc->getText());
    }

    public function testWordMovement(): void
    {
        $doc = new Document('function hello(): string');
        $doc->setCursor(0, 8); // inside "function"

        $doc->moveWordRight();
        $this->assertGreaterThan(8, $doc->getCursorCol());

        $doc->moveWordLeft();
        $this->assertSame(9, $doc->getCursorCol(), 'lands at start of "hello"');
    }

    public function testUtf16ColumnForAsciiEqualsByteLength(): void
    {
        $doc = new Document('plain ascii line');
        $doc->setCursor(0, \strlen('plain ascii line'));

        $this->assertSame(\strlen('plain ascii line'), $doc->getCursorColUtf16());
    }

    public function testUtf8CursorMovesByCharacter(): void
    {
        $doc = new Document('héllo wörld');
        $doc->setCursor(0, 0);
        $doc->moveRight();
        $doc->moveRight();

        $this->assertSame(3, $doc->getCursorCol(), 'h (1 byte) + é (2 bytes)');
    }

    public function testUtf16ColToByteCol(): void
    {
        $doc = new Document('héllo');
        $this->assertSame(0, $doc->utf16ColToByteCol(0, 0));
        $this->assertSame(1, $doc->utf16ColToByteCol(0, 1));
        $this->assertSame(3, $doc->utf16ColToByteCol(0, 2), 'é is 2 bytes but 1 UTF-16 unit');
        $this->assertSame(6, $doc->utf16ColToByteCol(0, 99), 'clamps to line length');

        $ascii = new Document('abc');
        $this->assertSame(2, $ascii->utf16ColToByteCol(0, 2));
    }

    public function testSelectionSpansLines(): void
    {
        $doc = new Document("alpha\nbravo\ncharlie\n");
        $doc->setCursor(0, 2);
        $doc->beginSelect();
        $doc->setCursor(1, 3, selection: SelectionMode::Keep);

        $this->assertTrue($doc->hasSelection());
        $this->assertSame("pha\nbra", $doc->getSelectedText());

        $doc->deleteSelection();
        $this->assertSame('alvo', $doc->getLine(0));
        $this->assertSame('charlie', $doc->getLine(1));

        $doc->undo();
        $this->assertSame('alpha', $doc->getLine(0));
    }

    public function testSetCursorCollapsesSelectionByDefault(): void
    {
        $doc = new Document('abcdef');
        $doc->beginSelect();
        $doc->setCursor(0, 3, selection: SelectionMode::Keep);
        $this->assertTrue($doc->hasSelection());

        $doc->setCursor(0, 1);
        $this->assertFalse($doc->hasSelection());
    }

    public function testSelectAll(): void
    {
        $doc = new Document("first\nsecond");
        $doc->selectAll();

        $this->assertSame("first\nsecond", $doc->getSelectedText());
    }

    public function testDuplicateAndDeleteAndMoveLines(): void
    {
        $doc = new Document("one\ntwo\nthree");
        $doc->setCursor(1, 0);

        $doc->duplicateLines();
        $this->assertSame(['one', 'two', 'two', 'three'], $doc->getLines());
        $this->assertSame(2, $doc->getCursorLine(), 'cursor moves onto the duplicate');

        $this->assertTrue($doc->deleteLines());
        $this->assertSame(['one', 'two', 'three'], $doc->getLines());

        $doc->setCursor(2, 0);
        $this->assertTrue($doc->moveLines(-1));
        $this->assertSame('three', $doc->getLine(1));

        $this->assertFalse($doc->moveLines(2), 'cannot move past the end');
    }

    public function testDeleteLinesOnEmptyDocument(): void
    {
        $doc = new Document('');
        $this->assertFalse($doc->deleteLines());
    }

    public function testSortLinesSortsTheSelectedBlockOnly(): void
    {
        $doc = new Document("banana\napple\ncherry\nzzz");
        $doc->setCursor(0, 0);
        $doc->beginSelect();
        $doc->setCursor(2, 6, selection: SelectionMode::Keep); // lines 0-2

        $this->assertTrue($doc->sortLines());
        $this->assertSame(['apple', 'banana', 'cherry', 'zzz'], $doc->getLines(), 'the trailing line is untouched');
    }

    public function testSortLinesNoOps(): void
    {
        $single = new Document('solo');
        $this->assertFalse($single->sortLines(), 'a single line cannot be sorted');

        $ordered = new Document("a\nb\nc");
        $ordered->setCursor(0, 0);
        $ordered->beginSelect();
        $ordered->setCursor(2, 1, selection: SelectionMode::Keep);
        $this->assertFalse($ordered->sortLines(), 'an already-sorted block is a no-op');
    }

    public function testJoinLines(): void
    {
        $doc = new Document("foo\n    bar\nbaz");
        $doc->setCursor(0, 0); // no selection → join with the line below, trimming
        $this->assertTrue($doc->joinLines());
        $this->assertSame(['foo bar', 'baz'], $doc->getLines());

        $doc->setCursor(1, 0);
        $this->assertFalse($doc->joinLines(), 'the last line has nothing below to join');

        $block = new Document("a\nb\nc\nd");
        $block->setCursor(0, 0);
        $block->beginSelect();
        $block->setCursor(2, 1, selection: SelectionMode::Keep); // lines 0-2
        $this->assertTrue($block->joinLines());
        $this->assertSame(['a b c', 'd'], $block->getLines());
    }

    public function testToggleCaseLines(): void
    {
        $doc = new Document("Hello\nWorld");
        $doc->setCursor(0, 0);
        $doc->beginSelect();
        $doc->setCursor(1, 5, selection: SelectionMode::Keep); // lines 0-1

        $this->assertTrue($doc->toggleCaseLines(), 'mixed case → upper');
        $this->assertSame(['HELLO', 'WORLD'], $doc->getLines());

        // The selection cleared; the caret line alone flips back down.
        $this->assertTrue($doc->toggleCaseLines(), 'all upper → lower');
        $this->assertSame('world', $doc->getLine(1));

        $nums = new Document('12345');
        $this->assertFalse($nums->toggleCaseLines(), 'no cased letters → no-op');
    }

    public function testToggleComment(): void
    {
        $doc = new Document("    return 1;\n    echo 2;");
        $doc->setCursor(0, 0);
        $doc->beginSelect();
        $doc->setCursor(1, 4, selection: SelectionMode::Keep);

        $doc->toggleComment(CommentSyntax::lineOnly('//'));
        $this->assertStringContainsString('// return', $doc->getLine(0));
        $this->assertStringContainsString('// echo', $doc->getLine(1));

        $doc->toggleComment(CommentSyntax::lineOnly('//'));
        $this->assertSame('    return 1;', $doc->getLine(0));
    }

    public function testIndentSelectionIndentsEachLineAndPreservesSelection(): void
    {
        $doc = new Document("a\nb\n\nc\n"); // space-indented (no leading tab)
        $doc->setCursor(0, 1);
        $doc->beginSelect();
        $doc->setCursor(3, 1, selection: SelectionMode::Keep); // selection spans lines 0..3

        $doc->indentSelection();
        $this->assertSame(['    a', '    b', '', '    c', ''], $doc->getLines(), 'each non-blank line gains one level; blank line untouched');

        // The selection still spans the same lines, columns shifted by the indent width.
        $range = $doc->getSelectionRange();
        $this->assertSame([0, 5, 3, 5], [$range['startLine'], $range['startCol'], $range['endLine'], $range['endCol']]);

        // One undo reverts the whole indent.
        $doc->undo();
        $this->assertSame("a\nb\n\nc\n", $doc->getText());
    }

    public function testDedentSelectionRemovesOneLevelAndStopsAtMargin(): void
    {
        $doc = new Document("        deep\n  shallow\nnone\n"); // 8 / 2 / 0 leading spaces
        $doc->setCursor(0, 0);
        $doc->beginSelect();
        $doc->setCursor(2, 4, selection: SelectionMode::Keep);

        $doc->dedentSelection();
        $this->assertSame(['    deep', 'shallow', 'none', ''], $doc->getLines(), 'removes up to one level; a shallow line goes to the margin, none stays');

        $doc->dedentSelection();
        $this->assertSame(['deep', 'shallow', 'none', ''], $doc->getLines());
        $doc->dedentSelection(); // already flush left
        $this->assertSame(['deep', 'shallow', 'none', ''], $doc->getLines(), 'outdent stops at column 0');
    }

    public function testIndentDedentUseTabsForTabFile(): void
    {
        $doc = new Document("\tx\n\ty\n"); // tab-indented
        $this->assertTrue($doc->usesTabs());
        $doc->setCursor(0, 0);
        $doc->beginSelect();
        $doc->setCursor(1, 2, selection: SelectionMode::Keep);

        $doc->indentSelection();
        $this->assertSame(["\t\tx", "\t\ty", ''], $doc->getLines());
        $doc->dedentSelection();
        $this->assertSame(["\tx", "\ty", ''], $doc->getLines());
    }

    public function testIndentSingleLineSelection(): void
    {
        $doc = new Document("foo\nbar\n");
        $doc->setCursor(0, 0);
        $doc->beginSelect();
        $doc->setCursor(0, 3, selection: SelectionMode::Keep); // one-line selection

        $doc->indentSelection();
        $this->assertSame('    foo', $doc->getLine(0));
        $this->assertSame('bar', $doc->getLine(1), 'only the selected line is indented');
    }

    public function testDeleteBackwardMergesLines(): void
    {
        $doc = new Document("ab\ncd");
        $doc->setCursor(1, 0);

        $this->assertTrue($doc->deleteBackward());
        $this->assertSame('abcd', $doc->getLine(0));

        $doc->setCursor(0, 0);
        $this->assertFalse($doc->deleteBackward(), 'nothing before document start');
    }

    public function testDeleteForwardMergesLines(): void
    {
        $doc = new Document("ab\ncd");
        $doc->setCursor(0, 2);

        $this->assertTrue($doc->deleteForward());
        $this->assertSame('abcd', $doc->getLine(0));

        $doc->setCursor(0, 4);
        $this->assertFalse($doc->deleteForward(), 'nothing after document end');
    }

    public function testDeleteWordBackward(): void
    {
        $doc = new Document('hello world');
        $doc->moveToDocumentEnd();

        $this->assertTrue($doc->deleteWordBackward());
        $this->assertSame('hello ', $doc->getLine(0));
    }

    public function testDeleteToLineEnd(): void
    {
        $doc = new Document('hello world');
        $doc->setCursor(0, 5);

        $this->assertTrue($doc->deleteToLineEnd());
        $this->assertSame('hello', $doc->getLine(0));
    }

    public function testInsertNewLineAutoIndents(): void
    {
        $doc = new Document('    if ($x) {');
        $doc->moveToLineEnd();
        $doc->insertNewLine();

        $this->assertSame('        ', $doc->getLine(1), 'indent + extra level after {');
    }

    public function testSmartHome(): void
    {
        $doc = new Document('    code');
        $doc->moveToLineEnd();

        $doc->moveToLineStart();
        $this->assertSame(4, $doc->getCursorCol(), 'first stop: first non-blank');

        $doc->moveToLineStart();
        $this->assertSame(0, $doc->getCursorCol(), 'second stop: column 0');
    }

    public function testVerticalMovementKeepsGoalColumn(): void
    {
        $doc = new Document("longer line here\nab\nanother long line");
        $doc->setCursor(0, 10);

        $doc->moveDown();
        $this->assertSame(2, $doc->getCursorCol(), 'clamped to short line');

        $doc->moveDown();
        $this->assertSame(10, $doc->getCursorCol(), 'goal column restored');
    }

    public function testReplaceBeforeCursor(): void
    {
        $doc = new Document('$this->makeGr');
        $doc->moveToDocumentEnd();

        $doc->replaceBeforeCursor(\strlen('makeGr'), 'makeGreeting()');
        $this->assertSame('$this->makeGreeting()', $doc->getLine(0));
    }

    public function testWordBeforeCursor(): void
    {
        $doc = new Document('echo $subj');
        $doc->moveToDocumentEnd();

        $this->assertSame('$subj', $doc->getWordBeforeCursor());
    }

    public function testCompoundUndoCollapsesManyEditsIntoOneStep(): void
    {
        $doc = new Document('abc');

        $doc->beginCompound();
        $doc->setCursor(0, 3);
        $doc->insert('X');
        $doc->setCursor(0, 0);
        $doc->insert('Y');
        $doc->endCompound();

        $this->assertSame('YabcX', $doc->getText());
        $this->assertTrue($doc->undo());
        $this->assertSame('abc', $doc->getText(), 'single undo reverts the whole compound');
    }

    public function testCompoundUndoIsNestable(): void
    {
        $doc = new Document('');
        $doc->beginCompound();
        $doc->beginCompound();
        $doc->insert('a');
        $doc->insert('b');
        $doc->endCompound();
        $doc->insert('c');
        $doc->endCompound();

        $this->assertSame('abc', $doc->getText());
        $doc->undo();
        $this->assertSame('', $doc->getText(), 'nested compound is still one undo step');
    }

    public function testCompoundEditRespectsUndoCap(): void
    {
        $doc = new Document('');
        // Fill the undo stack past its cap with non-coalescing edits.
        for ($i = 0; $i < 205; ++$i) {
            $doc->insertNewLine();
        }
        // A compound edit still pushes exactly one snapshot, evicting the oldest.
        $doc->beginCompound();
        $doc->insert('a');
        $doc->insert('b');
        $doc->endCompound();

        $this->assertTrue($doc->undo(), 'compound is undoable even at the cap');
    }

    public function testEndCompoundWithoutBeginIsSafe(): void
    {
        $doc = new Document('x');
        $doc->endCompound(); // no-op, depth stays at 0
        $doc->insert('y');
        $doc->undo();
        $this->assertSame('x', $doc->getText());
    }

    public function testSetSelectionRangeSelectsAndClamps(): void
    {
        $doc = new Document("hello\nworld");
        $doc->setSelectionRange(0, 1, 1, 3);
        $this->assertSame('ello'."\n".'wor', $doc->getSelectedText());
        $this->assertSame(1, $doc->getCursorLine());
        $this->assertSame(3, $doc->getCursorCol());

        // Out-of-range caret coordinates clamp to valid positions.
        $doc->setSelectionRange(0, 0, 9, 99);
        $range = $doc->getSelectionRange();
        $this->assertSame(1, $range['endLine']);
        $this->assertSame(5, $range['endCol']);
    }

    public function testSmartEnterBetweenPairExpandsToThreeLines(): void
    {
        $doc = new Document('    foo() {}');
        $doc->setCursor(0, 11); // between '{' and '}'
        $doc->insertNewLine();

        $this->assertSame('    foo() {', $doc->getLine(0));
        $this->assertSame('        ', $doc->getLine(1), 'blank caret line indented one level');
        $this->assertSame('    }', $doc->getLine(2), 'closer dropped to base indent');
        $this->assertSame(1, $doc->getCursorLine());
        $this->assertSame(8, $doc->getCursorCol());
    }

    public function testSmartEnterOnlyWhenCloserImmediatelyFollows(): void
    {
        // '{' with nothing after keeps the existing single-line indent bump.
        $doc = new Document('if () {');
        $doc->moveToLineEnd();
        $doc->insertNewLine();
        $this->assertSame('    ', $doc->getLine(1));
        $this->assertSame(2, $doc->getLineCount());
    }

    public function testMoveStatementSwapsAdjacentLine(): void
    {
        $doc = new Document("a\nb\nc");
        $doc->setCursor(1, 0);
        $this->assertTrue($doc->moveStatement(-1));
        $this->assertSame("b\na\nc", $doc->getText());
        $this->assertSame(0, $doc->getCursorLine());
    }

    public function testMoveStatementHopsBlankGapDown(): void
    {
        $doc = new Document("stmt\n\n\ntarget");
        $doc->setCursor(0, 0);
        $this->assertTrue($doc->moveStatement(1));
        // The statement jumps the two blank lines to sit above "target".
        $this->assertSame("\n\nstmt\ntarget", $doc->getText());
        $this->assertSame(2, $doc->getCursorLine());
    }

    public function testMoveStatementHopsBlankGapUp(): void
    {
        $doc = new Document("target\n\n\nstmt");
        $doc->setCursor(3, 0);
        $this->assertTrue($doc->moveStatement(-1));
        // The statement jumps the blank gap to sit directly under "target".
        $this->assertSame("target\nstmt\n\n", $doc->getText());
        $this->assertSame(1, $doc->getCursorLine());
    }

    public function testMoveStatementMovesSelectedBlockAndAnchor(): void
    {
        $doc = new Document("x\naa\nbb\ny");
        $doc->setCursor(1, 0);
        $doc->beginSelect();
        $doc->setCursor(2, 2, selection: SelectionMode::Keep); // select lines 1-2
        $this->assertTrue($doc->moveStatement(1));
        $this->assertSame("x\ny\naa\nbb", $doc->getText());
        $range = $doc->getSelectionRange();
        $this->assertSame(2, $range['startLine']);
        $this->assertSame(3, $range['endLine']);
    }

    public function testMoveStatementRefusesAtEdges(): void
    {
        $doc = new Document("a\nb");
        $doc->setCursor(0, 0);
        $this->assertFalse($doc->moveStatement(-1), 'top line cannot move up');
        $doc->setCursor(1, 0);
        $this->assertFalse($doc->moveStatement(1), 'bottom line cannot move down');
    }

    public function testMoveStatementDownToLastAllBlank(): void
    {
        $doc = new Document("stmt\n\n");
        $doc->setCursor(0, 0);
        // Only blank lines below and no non-blank target: clamps to the end.
        $this->assertTrue($doc->moveStatement(1));
        $this->assertSame("\n\nstmt", $doc->getText());
    }

    public function testReplaceAllSwapsContentAsOneUndo(): void
    {
        $doc = new Document("<?php\n\$a = 1;\n");
        $doc->setCursor(1, 5);
        $doc->replaceAll("<?php\n\$b = 2;\n");

        $this->assertSame("<?php\n\$b = 2;\n", $doc->getText());
        $this->assertTrue($doc->undo());
        $this->assertSame("<?php\n\$a = 1;\n", $doc->getText(), 'replaceAll is a single undo step');
    }

    public function testApplyEditsKeepingCursorInsertsAboveAndShiftsCaret(): void
    {
        // <?php / (blank) / $x = foo(); with the caret on the call line.
        $doc = new Document("<?php\n\nfoo();\n");
        $doc->setCursor(2, 3); // inside "foo"

        // Auto-import inserted at line 1 (above the caret).
        $doc->applyEditsKeepingCursor([[
            'range' => ['start' => ['line' => 1, 'character' => 0], 'end' => ['line' => 1, 'character' => 0]],
            'newText' => "use function foo;\n",
        ]]);

        $this->assertSame("<?php\nuse function foo;\n\nfoo();\n", $doc->getText());
        // Caret followed its line down by the one inserted line, column unchanged.
        $this->assertSame(3, $doc->getCursorLine());
        $this->assertSame(3, $doc->getCursorCol());
    }

    public function testApplyEditsKeepingCursorIsOneUndoStep(): void
    {
        $doc = new Document("<?php\n\nfoo();\n");
        $doc->setCursor(2, 0);
        $doc->applyEditsKeepingCursor([
            [
                'range' => ['start' => ['line' => 1, 'character' => 0], 'end' => ['line' => 1, 'character' => 0]],
                'newText' => "use A;\n",
            ],
            [
                'range' => ['start' => ['line' => 1, 'character' => 0], 'end' => ['line' => 1, 'character' => 0]],
                'newText' => "use B;\n",
            ],
        ]);
        $this->assertStringContainsString('use A;', $doc->getText());
        $this->assertStringContainsString('use B;', $doc->getText());

        $this->assertTrue($doc->undo());
        $this->assertSame("<?php\n\nfoo();\n", $doc->getText(), 'both edits collapse into one undo step');
    }

    public function testApplyEditsKeepingCursorIgnoresEmptyList(): void
    {
        $doc = new Document("a\nb");
        $doc->setCursor(1, 1);
        $doc->applyEditsKeepingCursor([]);
        $this->assertSame("a\nb", $doc->getText());
        $this->assertSame(1, $doc->getCursorLine());
        $this->assertSame(1, $doc->getCursorCol());
        $this->assertFalse($doc->undo(), 'no-op leaves the undo stack untouched');
    }

    public function testApplyEditsKeepingCursorReplacesRangeBelowCaret(): void
    {
        // Edit below the caret line must not shift the caret.
        $doc = new Document("top\nmid\nbot\n");
        $doc->setCursor(0, 1);
        $doc->applyEditsKeepingCursor([[
            'range' => ['start' => ['line' => 2, 'character' => 0], 'end' => ['line' => 2, 'character' => 3]],
            'newText' => 'BOTTOM',
        ]]);
        $this->assertSame("top\nmid\nBOTTOM\n", $doc->getText());
        $this->assertSame(0, $doc->getCursorLine());
        $this->assertSame(1, $doc->getCursorCol());
    }

    public function testClampLineAndClampCol(): void
    {
        $doc = new Document("ab\ncdef\n");

        $this->assertSame(0, $doc->clampLine(-5));
        $this->assertSame(1, $doc->clampLine(1));
        $this->assertSame(2, $doc->clampLine(99), 'clamps to the trailing empty line');

        $this->assertSame(0, $doc->clampCol(1, -3));
        $this->assertSame(4, $doc->clampCol(1, 99), 'clamps to the line length');
        $this->assertSame(0, $doc->clampCol(99, 99), 'an out-of-range line is clamped first');
    }

    public function testGetTextInRange(): void
    {
        $doc = new Document("one\ntwo\nthree\n");

        $this->assertSame('n', $doc->getTextInRange(0, 1, 0, 2), 'single-line slice');
        $this->assertSame("ne\ntwo\nth", $doc->getTextInRange(0, 1, 2, 2), 'multi-line slice spans whole middle lines');
        $this->assertSame('', $doc->getTextInRange(1, 2, 1, 2), 'a point range is empty');
    }

    public function testDeleteSelectionWithZeroWidthSelectionClearsTheDanglingAnchor(): void
    {
        $doc = new Document('hello');
        $doc->beginSelect(); // anchor == cursor: zero-width, nothing to delete
        $this->assertFalse($doc->deleteSelection());

        $doc->moveRight(); // does not itself touch the selection anchor
        $this->assertFalse($doc->hasSelection(), 'the anchor was cleared, not left dangling at its old position');
    }

    public function testMutatingOperationsBumpTheVersion(): void
    {
        $doc = new Document("one\ntwo\nthree\nfour\n");
        $bump = function (callable $op) use ($doc): void {
            $before = $doc->getVersion();
            $op();
            $this->assertGreaterThan($before, $doc->getVersion());
        };

        $doc->setCursor(1, 0);
        $doc->beginSelect();
        $doc->setCursor(2, 0, selection: SelectionMode::Keep);
        $bump($doc->deleteLines(...));

        $bump($doc->duplicateLines(...));
        $bump(static fn () => $doc->moveLines(1));
        $bump($doc->insertNewLine(...));
        $bump(static fn () => $doc->insert('x'));
        $bump($doc->deleteBackward(...));
        $bump($doc->deleteForward(...));
        $bump($doc->deleteWordBackward(...));
        $bump($doc->deleteToLineEnd(...));
        $bump(static fn () => $doc->replaceBeforeCursor(0, 'z'));
        $bump($doc->indentSelection(...));
        $bump($doc->dedentSelection(...));
        $bump(static fn () => $doc->toggleComment(CommentSyntax::lineOnly('//')));
        $bump(static fn () => $doc->openLine(LinePlacement::Below));
        $bump(static fn () => $doc->completeStatement(StatementSyntax::forGrammar('php') ?? throw new \LogicException('php is in the table')));
        $bump(static fn () => $doc->replaceAll('reset'));
        $bump($doc->undo(...));
        $bump($doc->redo(...));
    }

    public function testDuplicateLinesIsItsOwnUndoStep(): void
    {
        $doc = new Document("a\nb\n");
        $doc->duplicateLines();
        $afterFirst = $doc->getLines();

        $doc->duplicateLines();
        $this->assertTrue($doc->undo());
        $this->assertSame($afterFirst, $doc->getLines(), 'one undo reverts only the second duplication');
    }

    public function testDuplicateLinesShiftsTheSelectionAnchorByTheDuplicatedSpan(): void
    {
        $doc = new Document("x\naa\nbb\ny\n");
        $doc->setCursor(1, 0);
        $doc->beginSelect();
        $doc->setCursor(2, 2, selection: SelectionMode::Keep); // select lines 1-2

        $doc->duplicateLines();

        // Exactly the 2-line block is duplicated (not the tail beyond it too).
        $this->assertSame(['x', 'aa', 'bb', 'aa', 'bb', 'y', ''], $doc->getLines());

        // The duplicate is inserted at lines 3-4; the selection anchor (line 1)
        // must shift down by the 2-line span, not stay behind.
        $range = $doc->getSelectionRange();
        $this->assertSame(3, $range['startLine']);
        $this->assertSame(4, $range['endLine']);
    }

    public function testMoveLinesIsItsOwnUndoStepAndShiftsTheSelection(): void
    {
        $doc = new Document("a\nbb\ncc\nd\n");
        $doc->setCursor(0, 0);
        $doc->insert('Z'); // unrelated prior undo step, with no selection involved

        $doc->setCursor(1, 0);
        $doc->beginSelect();
        $doc->setCursor(2, 2, selection: SelectionMode::Keep); // select lines 1-2

        $this->assertTrue($doc->moveLines(1));
        $range = $doc->getSelectionRange();
        $this->assertSame(2, $range['startLine']);
        $this->assertSame(3, $range['endLine']);

        $this->assertTrue($doc->undo());
        $this->assertSame('Za', $doc->getLine(0), 'the prior insert is untouched by this undo');
        $this->assertStringContainsString('bb', $doc->getLine(1), 'undo reverts only the move');
    }

    public function testGoalColumnIsMemoizedNotRecomputedOnEachVerticalMove(): void
    {
        // Line 0's caret is at column 10; line 1 is short (clamped to 2) but
        // line 2 is long again. If moveDown() recomputed the goal column from
        // the (now clamped) column-2 position instead of reusing the stored
        // goal, the second move would land at column 2, not back at 10.
        $doc = new Document("0123456789\nab\n0123456789");
        $doc->setCursor(0, 10);
        $doc->moveDown();
        $this->assertSame(2, $doc->getCursorCol());
        $doc->moveDown();
        $this->assertSame(10, $doc->getCursorCol(), 'goal column of 10 was preserved across the short line');
    }

    public function testWordMovementHandlesMultibyteCharacters(): void
    {
        // The word-boundary regexes drop their /u flag under mutation; without
        // it, a multibyte character like "é" is scanned byte-by-byte and the
        // boundary lands mid-character instead of after the whole word.
        $doc = new Document('bar café');
        $doc->moveToDocumentEnd();
        $doc->moveWordLeft();
        $this->assertSame(\strlen('bar '), $doc->getCursorCol(), 'lands at the start of "café", not mid-character');

        $doc = new Document('café bar');
        $doc->setCursor(0, 0);
        $doc->moveWordRight();
        $this->assertSame(\strlen('café'), $doc->getCursorCol(), 'lands at the end of "café", not mid-character');
    }

    public function testGetWordBeforeCursorHandlesMultibyteCharacters(): void
    {
        $doc = new Document('café');
        $doc->moveToDocumentEnd();
        $this->assertSame('café', $doc->getWordBeforeCursor());
    }

    public function testDeleteWordBackwardHandlesMultibyteCharacters(): void
    {
        $doc = new Document('café');
        $doc->moveToDocumentEnd();
        $doc->deleteWordBackward();
        $this->assertSame('', $doc->getLine(0), 'the whole word is deleted, not just its final byte pair');
    }

    public function testVerticalMovementReturnValues(): void
    {
        $doc = new Document("a\n"); // lines: ['a', '']
        $this->assertTrue($doc->moveDown());
        $this->assertFalse($doc->moveDown(), 'already on the last (empty) line');
        $this->assertTrue($doc->moveUp());
        $this->assertFalse($doc->moveUp(), 'already on the first line at column 0');
    }

    public function testHorizontalAndLineEdgeMovementReturnValues(): void
    {
        $doc = new Document('  abc');
        $this->assertTrue($doc->moveToLineEnd());
        $this->assertFalse($doc->moveToLineEnd(), 'already at the line end');
        $this->assertTrue($doc->moveToLineStart(), 'first stop: the first non-blank column');
        $this->assertTrue($doc->moveToLineStart(), 'second stop: column 0');
        $this->assertTrue($doc->moveToLineStart(), 'smart-home toggles back to the first non-blank column');
    }

    public function testSortJoinAndToggleCaseUseTheFullSelectedBlockNotJustFromLineZero(): void
    {
        // A block NOT starting at line 0 distinguishes `last-first` from a
        // mutated `last+first`, which would coincide when first==0. "aaa" (not
        // "tail") is the untouched trailing line: it would sort to the FRONT
        // if wrongly pulled into the block, so a coincidental match is ruled out.
        $doc = new Document("z\nbanana\napple\ncherry\naaa");
        $doc->setCursor(1, 0);
        $doc->beginSelect();
        $doc->setCursor(3, 6, selection: SelectionMode::Keep); // lines 1-3

        $this->assertTrue($doc->sortLines());
        $this->assertSame(['z', 'apple', 'banana', 'cherry', 'aaa'], $doc->getLines(), 'only lines 1-3 sorted; line 0 and 4 untouched');
    }

    public function testJoinLinesUsesTheFullSelectedBlockNotJustFromLineZero(): void
    {
        $doc = new Document("z\na\nb\nc\ntail");
        $doc->setCursor(1, 0);
        $doc->beginSelect();
        $doc->setCursor(3, 1, selection: SelectionMode::Keep); // lines 1-3

        $this->assertTrue($doc->joinLines());
        $this->assertSame(['z', 'a b c', 'tail'], $doc->getLines());
    }

    public function testToggleCommentUsesTheFullSelectedBlockNotJustFromLineZero(): void
    {
        $doc = new Document("z\none\ntwo\nthree\ntail");
        $doc->setCursor(1, 0);
        $doc->beginSelect();
        $doc->setCursor(3, 5, selection: SelectionMode::Keep); // lines 1-3

        $doc->toggleComment(CommentSyntax::lineOnly('//'));
        $this->assertSame(['z', '// one', '// two', '// three', 'tail'], $doc->getLines());
    }

    public function testMoveLinesUsesTheFullSelectedBlockNotJustFromLineZero(): void
    {
        $doc = new Document("z\na\nb\ntail");
        $doc->setCursor(1, 0);
        $doc->beginSelect();
        $doc->setCursor(2, 1, selection: SelectionMode::Keep); // lines 1-2

        $this->assertTrue($doc->moveLines(1));
        $this->assertSame(['z', 'tail', 'a', 'b'], $doc->getLines());
    }

    public function testApplyEditsKeepingCursorAppliesBottomUpAcrossSameLineEdits(): void
    {
        // Two edits on the SAME line, at different characters, relying on the
        // comparator to sort by [line, character] descending so the rightmost
        // edit lands first without invalidating the other's offsets.
        $doc = new Document('abcdef');
        $doc->setCursor(0, 6);
        $doc->applyEditsKeepingCursor([
            ['range' => ['start' => ['line' => 0, 'character' => 0], 'end' => ['line' => 0, 'character' => 1]], 'newText' => 'A'],
            ['range' => ['start' => ['line' => 0, 'character' => 4], 'end' => ['line' => 0, 'character' => 5]], 'newText' => 'E'],
        ]);
        $this->assertSame('AbcdEf', $doc->getLine(0));
    }

    public function testApplyEditsKeepingCursorTreatsMissingEndLineAsZero(): void
    {
        // An edit whose range omits 'line' defaults to 0; on a caret sitting
        // below line 0, that edit must count toward the caret's line shift.
        $doc = new Document("old\nfoo();\n");
        $doc->setCursor(1, 3); // inside "foo"

        $doc->applyEditsKeepingCursor([[
            'range' => ['start' => ['character' => 0], 'end' => ['character' => 0]],
            'newText' => "use foo;\n",
        ]]);

        $this->assertSame("use foo;\nold\nfoo();\n", $doc->getText());
        $this->assertSame(2, $doc->getCursorLine(), 'the caret line shifted down by the inserted line');
    }
}
