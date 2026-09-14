<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\TestCase;
use Porta\Editor\ChangeMarkKind;
use Porta\Editor\DecorationStore;
use Porta\Editor\DiagnosticSeverity;

final class DecorationStoreTest extends TestCase
{
    public function testDiagnosticCountsFollowEveryDiagnosticsMutator(): void
    {
        // The counts are maintained at push time (the status bar polls them
        // every frame) — BOTH mutators must recount, or a buffer switch would
        // show the previous file's totals.
        $store = new DecorationStore(static function (): void {});
        $this->assertSame(['errors' => 0, 'warnings' => 0], $store->diagnosticCounts);

        $store->setDiagnostics([
            3 => [
                ['severity' => DiagnosticSeverity::Error, 'message' => 'boom', 'col' => 0],
                ['severity' => DiagnosticSeverity::Warning, 'message' => 'meh', 'col' => 4],
            ],
        ]);
        $this->assertSame(['errors' => 1, 'warnings' => 1], $store->diagnosticCounts);

        $store->resetForBuffer([5 => [['severity' => DiagnosticSeverity::Warning, 'message' => 'other file', 'col' => 0]]]);
        $this->assertSame(['errors' => 0, 'warnings' => 1], $store->diagnosticCounts, 'a buffer switch recounts');

        $store->setDiagnostics([]);
        $this->assertSame(['errors' => 0, 'warnings' => 0], $store->diagnosticCounts, 'clearing recounts to zero');
    }

    /**
     * Marks below an insertion move down by as many lines as it added, and the
     * lines it created are marked.
     *
     * The gutter map is computed from the file on DISK and refreshed only on a
     * save, a reload or a buffer switch. Between those it used to sit still
     * while the lines under it moved, so three lines added at the top of a
     * changed file left every mark below pointing three lines wrong — and the
     * marker column and the stripe read the same map, so both lied together.
     */
    public function testMarksBelowAnInsertionMoveDownAndTheNewLinesAreMarked(): void
    {
        $store = new DecorationStore(static function (): void {});
        $store->resetForBuffer([], lineCount: 10);
        $store->setVcsMarks([1 => ChangeMarkKind::Deleted, 8 => ChangeMarkKind::Modified]);

        // Two lines added: the caret ends two below the line the edit began on.
        $store->remapVcsMarksForEdit(cursorLine: 5, lineCount: 12);

        $this->assertSame(
            [1 => ChangeMarkKind::Deleted, 3 => ChangeMarkKind::Modified, 4 => ChangeMarkKind::Added, 5 => ChangeMarkKind::Added, 10 => ChangeMarkKind::Modified],
            $store->vcsMarks,
            'the mark above stays, the two new lines are Added, and the one below moved by two',
        );
    }

    /**
     * A deletion takes the marks of the lines it removed with it.
     *
     * The removed mark is a DIFFERENT kind from the one it would land on. With
     * two alike, a remap that shifted the doomed mark instead of dropping it
     * wrote the same value onto the same line and the case could not tell the
     * two apart — it passed with the guard removed, which is how this fixture
     * was caught.
     */
    public function testMarksInsideADeletionAreDroppedAndTheRestMoveUp(): void
    {
        $store = new DecorationStore(static function (): void {});
        $store->resetForBuffer([], lineCount: 12);
        $store->setVcsMarks([2 => ChangeMarkKind::Added, 4 => ChangeMarkKind::Deleted, 9 => ChangeMarkKind::Modified]);

        // Lines 3 and 4 removed, the caret left on line 2 where the edit began.
        $store->remapVcsMarksForEdit(cursorLine: 2, lineCount: 10);

        $this->assertSame(
            [2 => ChangeMarkKind::Added, 7 => ChangeMarkKind::Modified],
            $store->vcsMarks,
            "line 4's mark went with the line; line 9 moved up two",
        );
    }

    /**
     * An edit that changes no line count still lights the line it touched —
     * this is what makes the gutter answer a keystroke rather than a save.
     */
    public function testTypingOnAnUnmarkedLineMarksItModified(): void
    {
        $store = new DecorationStore(static function (): void {});
        $store->resetForBuffer([], lineCount: 10);
        $store->setVcsMarks([6 => ChangeMarkKind::Added]);

        $store->remapVcsMarksForEdit(cursorLine: 2, lineCount: 10);

        $this->assertSame([2 => ChangeMarkKind::Modified, 6 => ChangeMarkKind::Added], $store->vcsMarks);
    }

    /**
     * A line that already carries a mark keeps the one it has. Added must not
     * decay to Modified on the next keystroke — a new line stays new until git
     * says otherwise.
     */
    public function testAnExistingMarkIsNotOverwritten(): void
    {
        $store = new DecorationStore(static function (): void {});
        $store->resetForBuffer([], lineCount: 10);
        $store->setVcsMarks([2 => ChangeMarkKind::Added]);

        $store->remapVcsMarksForEdit(cursorLine: 2, lineCount: 10);

        $this->assertSame([2 => ChangeMarkKind::Added], $store->vcsMarks, 'still Added, not Modified');
    }

    /**
     * The control from the opposite value: with no marks and no line change
     * there is nothing to do, and in particular no mark is invented on a file
     * git has nothing to say about. Without this the "light the edited line"
     * rule would paint a gutter in every unchanged file.
     */
    public function testAnUnchangedFileGainsNoMarksFromAKeystroke(): void
    {
        $repaints = 0;
        $store = new DecorationStore(static function () use (&$repaints): void {
            ++$repaints;
        });

        $store->remapVcsMarksForEdit(cursorLine: 4, lineCount: 0);

        $this->assertSame([], $store->vcsMarks);
        $this->assertSame(0, $repaints, 'and no repaint was asked for');
    }

    /**
     * A mark set arriving from the producer rebases the baseline too.
     *
     * The gap this closes is the external RELOAD: the reconciler replaces a
     * clean buffer's text in place — `Document::replaceAll()`, no
     * `showDocument()` — so nothing rebased through {@see resetForBuffer()},
     * and the producer's fresh marks landed against a line count from before
     * the reload. The next keystroke then read that whole difference as its own
     * edit and threw every mark down the file by it.
     *
     * The marks and the count travel together because they are one fact: this
     * map describes a document of this many lines.
     */
    public function testMarksArrivingWithALineCountRebaseTheRemap(): void
    {
        $store = new DecorationStore(static function (): void {});
        $store->resetForBuffer([], lineCount: 200); // a big file was open

        // Reloaded from disk as a 12-line file, with the producer's answer for it.
        $store->setVcsMarks([5 => ChangeMarkKind::Modified], lineCount: 12);
        $store->remapVcsMarksForEdit(cursorLine: 1, lineCount: 12);

        $this->assertSame(
            [1 => ChangeMarkKind::Modified, 5 => ChangeMarkKind::Modified],
            $store->vcsMarks,
            'one keystroke, no shift: the 188-line difference was the reload, not an edit',
        );
    }

    /**
     * And the control: a caller CLEARING the marks passes no count and must not
     * rebase. The widget does exactly that when the gutter is switched off, and
     * a rebase to zero there would make the next keystroke look like an edit
     * that added the whole file.
     */
    public function testClearingTheMarksLeavesTheBaselineAlone(): void
    {
        $store = new DecorationStore(static function (): void {});
        $store->resetForBuffer([], lineCount: 30);
        $store->setVcsMarks([5 => ChangeMarkKind::Modified], lineCount: 30);

        // The gutter is switched off: the widget clears the marks and says
        // nothing about the document, because it is not answering about one.
        $store->setVcsMarks([]);
        $store->remapVcsMarksForEdit(cursorLine: 2, lineCount: 30);

        // Nothing here may rebase to zero: the next keystroke would then read
        // the whole 30-line file as lines this edit had just added, and paint a
        // gutter of thirty-one marks on a file whose gutter is off.
        $this->assertSame([], $store->vcsMarks, 'cleared stays cleared');
    }
}
