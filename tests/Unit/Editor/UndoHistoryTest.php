<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\TestCase;
use Porta\Editor\UndoHistory;

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
        $t = 1000.0;
        $h = new UndoHistory(function () use (&$t): float {
            return $t;
        });

        $h->push($this->snap(1), 'insert'); // stored
        $t = 1000.5;
        $h->push($this->snap(2), 'insert'); // coalesced — not stored
        $t = 1002.0;
        $h->push($this->snap(3), 'insert'); // >1s later — stored

        $this->assertSame($this->snap(3), $h->undo($this->snap(9)));
        $this->assertSame($this->snap(1), $h->undo($this->snap(9)));
        $this->assertNull($h->undo($this->snap(9)));
    }

    public function testDifferentKeyDoesNotCoalesce(): void
    {
        $t = 1000.0;
        $h = new UndoHistory(function () use (&$t): float {
            return $t;
        });

        $h->push($this->snap(1), 'a');
        $h->push($this->snap(2), 'b'); // different key → stored even within 1s

        $this->assertSame($this->snap(2), $h->undo($this->snap(9)));
        $this->assertSame($this->snap(1), $h->undo($this->snap(9)));
    }

    public function testNullKeyNeverCoalesces(): void
    {
        $h = new UndoHistory(static fn (): float => 5.0);
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
        $t = 5.0;
        $h = new UndoHistory(function () use (&$t): float {
            return $t;
        });
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

    public function testCapEvictsOldestBeyondLimit(): void
    {
        $t = 0.0;
        $h = new UndoHistory(function () use (&$t): float {
            return $t;
        });

        for ($i = 0; $i < 250; ++$i) {
            $t += 2.0; // spaced out so nothing coalesces
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
