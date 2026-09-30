<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\TestCase;
use Porta\Editor\ChangeMarkKind;
use Porta\Editor\DecorationStore;
use Porta\Editor\DiagnosticSeverity;
use Porta\Editor\Document;
use Porta\Editor\GutterPart;
use Porta\Editor\SelectionMode;

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

    /** The caret readout is the line's WORST diagnostic — the first of equals — never merely the first sent. */
    public function testTheCaretReadoutIsTheWorstDiagnosticOnTheLine(): void
    {
        $store = new DecorationStore(static function (): void {});
        $store->setDiagnostics([
            2 => [
                ['severity' => DiagnosticSeverity::Hint, 'message' => 'hint', 'col' => 0],
                ['severity' => DiagnosticSeverity::Warning, 'message' => 'first warning', 'col' => 1],
                ['severity' => DiagnosticSeverity::Error, 'message' => 'boom', 'col' => 2],
                ['severity' => DiagnosticSeverity::Error, 'message' => 'second boom', 'col' => 3],
            ],
            4 => [
                ['severity' => DiagnosticSeverity::Warning, 'message' => 'first warning', 'col' => 0],
                ['severity' => DiagnosticSeverity::Warning, 'message' => 'second warning', 'col' => 1],
            ],
        ]);

        $this->assertSame(['message' => 'boom', 'severity' => DiagnosticSeverity::Error], $store->diagnosticAt(2));
        $this->assertSame(['message' => 'first warning', 'severity' => DiagnosticSeverity::Warning], $store->diagnosticAt(4), 'the first of equals');
        $this->assertNull($store->diagnosticAt(3));
    }

    /** Pointing at the same mark again changes nothing — the status bar is not re-flashed on every motion event. */
    public function testGutterHoverReportsOnlyAChange(): void
    {
        $frames = 0;
        $store = new DecorationStore(static function () use (&$frames): void {
            ++$frames;
        });

        $this->assertTrue($store->hoverGutter(3, GutterPart::Change));
        $this->assertFalse($store->hoverGutter(3, GutterPart::Change), 'the same mark');
        $this->assertTrue($store->hoverGutter(3), 'the same line, the other column');
        $this->assertTrue($store->hoverGutter(null));
        $this->assertFalse($store->hoverGutter(null));
        $this->assertSame(3, $frames, 'a frame per change, none otherwise');
    }

    public function testStripeHoverReportsOnlyAChange(): void
    {
        $store = new DecorationStore(static function (): void {});

        $this->assertTrue($store->hoverStripe(4));
        $this->assertFalse($store->hoverStripe(4));
        $this->assertTrue($store->hoverStripe(null));
        $this->assertFalse($store->hoverStripe(null));
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
        $store->remapForEdit(cursorLine: 5, lineCount: 12);

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
        $store->remapForEdit(cursorLine: 2, lineCount: 10);

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

        $store->remapForEdit(cursorLine: 2, lineCount: 10);

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

        $store->remapForEdit(cursorLine: 2, lineCount: 10);

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

        $store->remapForEdit(cursorLine: 4, lineCount: 0);

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
        $store->remapForEdit(cursorLine: 1, lineCount: 12);

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
        $store->remapForEdit(cursorLine: 2, lineCount: 30);

        // Nothing here may rebase to zero: the next keystroke would then read
        // the whole 30-line file as lines this edit had just added, and paint a
        // gutter of thirty-one marks on a file whose gutter is off.
        $this->assertSame([], $store->vcsMarks, 'cleared stays cleared');
    }

    /** Each writer replaces only its own notes; two on one line are both shown, first writer first. */
    public function testLineAnnotationsAreKeptPerOwnerAndJoined(): void
    {
        $store = new DecorationStore(static function (): void {});
        $store->setLineAnnotations([0 => 'alice, 3 days ago', 1 => 'bob, today'], 'git');
        $store->setLineAnnotations([1 => '✎ 2 · carol: rename this'], 'review');

        $this->assertSame([0 => 'alice, 3 days ago', 1 => 'bob, today  · ✎ 2 · carol: rename this'], $store->lineAnnotations);

        $store->setLineAnnotations([0 => 'dave, now'], 'git');
        $this->assertSame([0 => 'dave, now', 1 => '✎ 2 · carol: rename this'], $store->lineAnnotations, 'a republish replaces only that owner');

        $store->setLineAnnotations([], 'review');
        $this->assertSame([0 => 'dave, now'], $store->lineAnnotations, 'an empty map clears only that owner');

        $store->setCommentMarks([2 => 'carol: rename this']);
        $store->resetForBuffer([]);
        $this->assertSame([], $store->lineAnnotations, 'a buffer switch drops every owner');
        $store->setLineAnnotations([4 => 'x'], 'review');
        $this->assertSame([4 => 'x'], $store->lineAnnotations, 'and none comes back with the next publish');
        $this->assertSame([], $store->commentMarks, 'comment marks go with the buffer too');
    }

    /** Blocks are kept per writer and merged in order, count as extra rows, and go with the buffer. */
    public function testLineBlocksArePerOwnerAndMakeLinesTaller(): void
    {
        $store = new DecorationStore(static function (): void {});
        $this->assertFalse($store->hasExtraRows());

        $store->setLineBlocks([2 => ['alice: a']], 'review');
        $store->setLineBlocks([2 => ['agent: b'], 4 => ['agent: c']], 'agent');
        $this->assertSame(['alice: a', 'agent: b'], $store->lineBlocksAt(2));
        $this->assertSame(['agent: c'], $store->lineBlocksAt(4));
        $this->assertTrue($store->hasExtraRows());

        $store->setLineBlocks([], 'agent');
        $this->assertSame(['alice: a'], $store->lineBlocksAt(2), 'an owner clears only its own');
        $this->assertSame([], $store->lineBlocksAt(4));

        $store->resetForBuffer([]);
        $this->assertSame([], $store->lineBlocksAt(2));
        $this->assertFalse($store->hasExtraRows(), 'a buffer switch drops them');

        $store->setCodeLenses([0 => [['title' => 't', 'command' => 'c', 'arguments' => [], 'owner' => 'php']]]);
        $this->assertTrue($store->hasExtraRows(), 'a lens is an extra row too');
    }

    public function testAnAgentsCaretMovesWithTheUsersEditsInItsFileOnly(): void
    {
        $store = new DecorationStore(static function (): void {});
        $store->resetForBuffer([], lineCount: 10);
        $caret = static fn (int $line): array => ['line' => $line, 'col' => 3, 'label' => 'x', 'slot' => 0];
        $store->setRemoteCaret('/p/a.php', 'above', $caret(1));
        $store->setRemoteCaret('/p/a.php', 'below', $caret(5));
        $store->setRemoteCaret('/p/a.php', 'further', $caret(8));
        $store->setRemoteCaret('/p/b.php', 'elsewhere', $caret(5));
        $lines = static fn (string $path): array => \array_keys($store->remoteCaretsIn($path));

        $store->remapForEdit(cursorLine: 4, lineCount: 12, path: '/p/a.php'); // two lines typed below line 2
        $this->assertSame([1, 7, 10], $lines('/p/a.php'), 'above the edit stays, below moves down');
        $this->assertSame([5], $lines('/p/b.php'), 'another file\'s carets stay');

        $store->remapForEdit(cursorLine: 5, lineCount: 9, path: '/p/a.php'); // lines 6-8 removed, the caret left on 5
        $this->assertSame([1, 5, 7], $lines('/p/a.php'), 'one in the removed lines lands on the edit, one past them moves up');

        $store->remapForEdit(cursorLine: 3, lineCount: 9, path: '/p/a.php');
        $store->remapForEdit(cursorLine: 3, lineCount: 10);
        $this->assertSame([1, 5, 7], $lines('/p/a.php'), 'no line added, or no file named: nothing moves');

        $store->setRemoteCaret('/p/a.php', 'above', null);
        $this->assertSame([5, 7], $lines('/p/a.php'));
    }

    public function testAnAgentsCaretMovesAlongItsLineWithTheUsersTypingBeforeIt(): void
    {
        $store = new DecorationStore(static function (): void {});
        $doc = new Document('abcdef');
        $store->resetForBuffer([], lineCount: 1);
        $store->setRemoteCaret('/p/a.php', 'agent', ['line' => 0, 'col' => 4, 'label' => 'x', 'slot' => 0]);
        $col = static fn (): int => $store->remoteCaretsIn('/p/a.php')[0][0]['col'];
        $edit = static function (\Closure $change) use ($store, $doc): void {
            $change();
            $store->remapForEdit($doc->cursorLine, $doc->getLineCount(), '/p/a.php', $doc);
        };
        $store->remoteCaretsIn('/p/a.php', $doc); // the frame that shows it measures its line
        $store->remoteCaretsIn('/p/a.php', $doc); // and the next frame leaves it measured

        $edit(static function () use ($doc): void {
            $doc->setCursor(0, 1);
            $doc->insert('XY'); // aXY|bcdef
        });
        $this->assertSame(6, $col(), 'typed before it: moved along');

        $edit(static function () use ($doc): void {
            $doc->setCursor(0, 7);
            $doc->insert('Z'); // after it
        });
        $this->assertSame(6, $col(), 'typed after it: stays');

        $edit(static function () use ($doc): void {
            $doc->setCursor(0, 1);
            $doc->beginSelect();
            $doc->setCursor(0, 3, SelectionMode::Keep);
            $doc->deleteSelection(); // XY gone
        });
        $this->assertSame(4, $col(), 'deleted before it: moved back');

        $edit(static function () use ($doc): void {
            $doc->setCursor(0, 3);
            $doc->beginSelect();
            $doc->setCursor(0, 5, SelectionMode::Keep);
            $doc->deleteSelection(); // the text under it
        });
        $this->assertSame(3, $col(), 'deleted across it: to where the deletion began');

        $edit(static function () use ($doc): void {
            $doc->setCursor(0, 3);
            $doc->insert('Q'); // right at it
        });
        $this->assertSame(4, $col(), 'typed AT it: pushed along, as text typed at a caret is');

        $store->setRemoteCaret('/p/a.php', 'agent', ['line' => 0, 'col' => 4, 'label' => 'x', 'slot' => 0]);
        $edit(static function () use ($doc): void {
            $doc->setCursor(0, 0);
            $doc->insert('!');
        });
        $this->assertSame(4, $col(), 'never measured, never moved — the edit measures it instead');
        $edit(static function () use ($doc): void {
            $doc->setCursor(0, 0);
            $doc->insert('!');
        });
        $this->assertSame(5, $col(), 'and the next one moves it');
    }
}
