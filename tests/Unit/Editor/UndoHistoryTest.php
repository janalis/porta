<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\TestCase;
use Porta\Editor\UndoHistory;
use Symfony\Component\Clock\MockClock;

final class UndoHistoryTest extends TestCase
{
    /** @return array{lines: string[], line: int, col: int} */
    private function snap(int $id): array
    {
        return ['lines' => ["state{$id}"], 'line' => $id, 'col' => 0];
    }

    public function testUndoAndRedoRoundTrip(): void
    {
        $h = new UndoHistory();
        $h->push($this->snap(1), null);

        $this->assertSame($this->snap(1), $h->undo($this->snap(9)));
        $this->assertSame($this->snap(9), $h->redo($this->snap(1)));
        $this->assertNull($h->redo($this->snap(1)), 'nothing left to redo');
    }

    public function testEmptyStacksReturnNull(): void
    {
        $h = new UndoHistory();
        $this->assertNull($h->undo($this->snap(1)));
        $this->assertNull($h->redo($this->snap(1)));
    }

    public function testSameKeyEditsCoalesceWithinOneSecond(): void
    {
        $clock = new MockClock('@1000.000000');
        $h = new UndoHistory($clock);

        $h->push($this->snap(1), 'insert'); // stored
        $clock->sleep(0.5);
        $h->push($this->snap(2), 'insert'); // coalesced — not stored
        $clock->sleep(1.5);
        $h->push($this->snap(3), 'insert'); // >1s later — stored

        $this->assertSame($this->snap(3), $h->undo($this->snap(9)));
        $this->assertSame($this->snap(1), $h->undo($this->snap(9)));
        $this->assertNull($h->undo($this->snap(9)));
    }

    public function testDifferentKeyDoesNotCoalesce(): void
    {
        $h = new UndoHistory(new MockClock('@1000.000000'));

        $h->push($this->snap(1), 'a');
        $h->push($this->snap(2), 'b'); // different key → stored even within 1s

        $this->assertSame($this->snap(2), $h->undo($this->snap(9)));
        $this->assertSame($this->snap(1), $h->undo($this->snap(9)));
    }

    public function testNullKeyNeverCoalesces(): void
    {
        $h = new UndoHistory(new MockClock('@5.000000'));
        $h->push($this->snap(1), null);
        $h->push($this->snap(2), null);

        $this->assertSame($this->snap(2), $h->undo($this->snap(9)));
        $this->assertSame($this->snap(1), $h->undo($this->snap(9)));
    }

    public function testCompoundGroupsIntoOneEntry(): void
    {
        $h = new UndoHistory();
        $h->beginCompound();
        $h->push($this->snap(1), 'k'); // first — stored
        $h->push($this->snap(2), 'k'); // suppressed
        $h->push($this->snap(3), 'k'); // suppressed
        $h->endCompound();

        $this->assertSame($this->snap(1), $h->undo($this->snap(9)));
        $this->assertNull($h->undo($this->snap(9)), 'the whole compound was one step');
    }

    public function testInCompoundReflectsNesting(): void
    {
        $h = new UndoHistory();
        $this->assertFalse($h->inCompound());
        $h->beginCompound();
        $this->assertTrue($h->inCompound());
        $h->beginCompound(); // nested
        $this->assertTrue($h->inCompound());
        $h->endCompound();
        $this->assertTrue($h->inCompound(), 'still inside the outer group');
        $h->endCompound();
        $this->assertFalse($h->inCompound());
    }

    public function testCheckpointBreaksCoalescing(): void
    {
        $h = new UndoHistory(new MockClock('@5.000000'));
        $h->push($this->snap(1), 'type'); // stored
        $h->checkpoint(); // e.g. a save — the next same-key edit must not coalesce
        $h->push($this->snap(2), 'type'); // stored (not merged)

        $this->assertSame($this->snap(2), $h->undo($this->snap(9)));
        $this->assertSame($this->snap(1), $h->undo($this->snap(9)), 'checkpoint kept both edits separate');
    }

    public function testPushClearsRedoStack(): void
    {
        $h = new UndoHistory();
        $h->push($this->snap(1), null);
        $h->undo($this->snap(2)); // redo now holds snap(2)

        $h->push($this->snap(3), null); // a fresh edit must drop the redo branch
        $this->assertNull($h->redo($this->snap(9)));
    }

    public function testSuppressedCompoundPushAlsoClearsRedo(): void
    {
        $h = new UndoHistory();
        $h->push($this->snap(1), null);
        $h->undo($this->snap(2)); // redo holds snap(2)

        $h->beginCompound();
        $h->push($this->snap(3), 'k'); // first of compound — stored, clears redo
        $h->undo($this->snap(4)); // undo it → redo holds snap(4)
        // Re-open a compound and push twice; the suppressed second push must
        // still clear the redo stack.
        $h->beginCompound();
        $h->push($this->snap(5), 'k'); // first — stored
        $h->push($this->snap(6), 'k'); // suppressed → clears redo
        $h->endCompound();
        $h->endCompound();

        $this->assertNull($h->redo($this->snap(9)));
    }

    public function testEachSeparateCompoundTakesItsOwnSnapshot(): void
    {
        // A stale `compoundSnapshotted` flag from an earlier compound must not
        // survive into a later, unrelated one — each begin/end group is its
        // own undo step.
        $h = new UndoHistory();
        $h->beginCompound();
        $h->push($this->snap(1), 'k');
        $h->endCompound();
        $h->beginCompound();
        $h->push($this->snap(2), 'k');
        $h->endCompound();

        $this->assertSame($this->snap(2), $h->undo($this->snap(9)));
        $this->assertSame($this->snap(1), $h->undo($this->snap(9)), 'the second compound stored its own snapshot');
    }

    public function testAnUnmatchedEndCompoundDoesNotUnderflowDepth(): void
    {
        // endCompound() guards against going negative; an unbalanced extra
        // call must not leave depth corrupted for the next begin/end pair.
        $h = new UndoHistory();
        $h->endCompound(); // no matching begin — must be a no-op
        $h->beginCompound();

        $this->assertTrue($h->inCompound());
    }

    public function testEditsExactlyOneSecondApartDoNotCoalesce(): void
    {
        $clock = new MockClock('@1000.000000');
        $h = new UndoHistory($clock);

        $h->push($this->snap(1), 'insert');
        $clock->sleep(1.0); // exactly the coalescing boundary — must NOT merge
        $h->push($this->snap(2), 'insert');

        $this->assertSame($this->snap(2), $h->undo($this->snap(9)));
        $this->assertSame($this->snap(1), $h->undo($this->snap(9)), 'both edits landed as separate steps');
    }

    public function testRedoOnAnEmptyStackDoesNotPushOntoUndo(): void
    {
        $h = new UndoHistory();
        $this->assertNull($h->redo($this->snap(9)));

        $this->assertNull($h->undo($this->snap(1)), 'a no-op redo must not have pushed onto the undo stack');
    }

    public function testCapEvictsOldestBeyondLimit(): void
    {
        $clock = new MockClock('@0.000000');
        $h = new UndoHistory($clock);

        for ($i = 0; $i < 250; ++$i) {
            $clock->sleep(2.0); // spaced out so nothing coalesces
            $h->push($this->snap($i), 'k');
        }

        // At most 200 entries survive.
        $count = 0;
        while (null !== $h->undo($this->snap(999))) {
            ++$count;
        }
        $this->assertSame(200, $count);
    }
}
