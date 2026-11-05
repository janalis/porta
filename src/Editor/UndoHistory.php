<?php

declare(strict_types=1);

namespace Porta\Editor;

use Porta\Support\Clocks;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;

/**
 * Undo/redo history for a {@see Document}: the undo and redo snapshot stacks,
 * time-based coalescing of consecutive single-character edits, and compound
 * (atomic) edit grouping. A snapshot is whatever opaque state the document hands
 * over ({lines, line, col}); this class never inspects it, it only stores and
 * returns it. The clock is injectable so coalescing is deterministically testable.
 *
 * @phpstan-type Snapshot array{lines: string[], line: int, col: int, anchor: array{line: int, col: int}|null, extras: list<Caret>}
 */
final class UndoHistory
{
    private const int MAX_UNDO = 200;

    /**
     * Cumulative undo-stack budget, counted in retained lines: each snapshot
     * keeps the whole lines array, and while the strings share storage
     * (copy-on-write), the array slots do not — a saturated stack on a
     * 50k-line file held ~360MB of structure under the step cap alone.
     * Two million line-slots is ~72MB worst case; a small file never reaches
     * it (the step cap governs first), a huge one keeps the newest steps.
     */
    private const int MAX_RETAINED_LINES = 2_000_000;

    /** @var list<Snapshot> */
    private array $undoStack = [];
    /** @var list<Snapshot> */
    private array $redoStack = [];

    /** Retained lines across the undo stack, for the budget. The redo stack
     * needs no tally: mass undo moves snapshots between the stacks one-for-one,
     * so the total never grows there, and the next edit clears redo anyway. */
    private int $undoLines = 0;

    /** Coalescing state: consecutive edits with the same key within 1s merge. */
    private float $lastEditAt = 0.0;
    private string $lastEditKind = '';

    /**
     * Compound-edit depth. While > 0, only the first push in the group takes a
     * snapshot; the rest are suppressed so a batch of edits is one undo step.
     */
    private int $compoundDepth = 0;
    private bool $compoundSnapshotted = false;

    public function __construct(
        private readonly ClockInterface $clock = new NativeClock(),
        private readonly int $maxRetainedLines = self::MAX_RETAINED_LINES,
    ) {}

    /**
     * Begin an atomic edit group: every push until the matching endCompound()
     * collapses into a single undo step. Nestable.
     */
    public function beginCompound(): void
    {
        if (0 === $this->compoundDepth) {
            $this->compoundSnapshotted = false;
        }
        ++$this->compoundDepth;
    }

    /**
     * Begin a group that JOINS the last undo step instead of taking one of its
     * own — for an edit that finishes the one before it, arriving later (a
     * completion's import, resolved after the accept). Undone, the two go
     * together. Closed by endCompound(); a fresh group when there is nothing
     * to join.
     */
    public function beginAmend(): void
    {
        $this->beginCompound();
        $this->compoundSnapshotted = $this->compoundSnapshotted || [] !== $this->undoStack;
    }

    public function endCompound(): void
    {
        if ($this->compoundDepth > 0) {
            --$this->compoundDepth;
        }
    }

    /** True while inside a compound (atomic) edit group. */
    public function inCompound(): bool
    {
        return $this->compoundDepth > 0;
    }

    /**
     * Break coalescing so the next edit starts a fresh undo step. Called on save
     * so an undo lands exactly on the saved content rather than merging past it.
     */
    public function checkpoint(): void
    {
        $this->lastEditKind = '';
    }

    /**
     * Record $snapshot as the pre-edit state, honoring compound grouping and
     * (outside a compound) time-based coalescing of same-key edits.
     *
     * @param Snapshot    $snapshot
     * @param string|null $coalesceKey consecutive edits with the same key within
     *                                 1s share one undo snapshot
     */
    public function push(array $snapshot, ?string $coalesceKey = null): void
    {
        if ($this->compoundDepth > 0) {
            // One snapshot for the whole compound; suppress the rest.
            $first = !$this->compoundSnapshotted;
            if ($first) {
                $this->compoundSnapshotted = true;
                $this->lastEditKind = '';
                $this->store($snapshot);
            }
            if (!$first) {
                $this->redoStack = [];
            }

            return;
        }

        $now = Clocks::seconds($this->clock);
        $coalesces = null !== $coalesceKey && $coalesceKey === $this->lastEditKind && ($now - $this->lastEditAt) < 1.0 && [] !== $this->undoStack;
        $this->lastEditAt = $now;
        if ($coalesces) {
            $this->redoStack = [];

            return;
        }
        $this->lastEditKind = $coalesceKey ?? '';
        $this->store($snapshot);
    }

    /**
     * Pop the last undo state, recording $current on the redo stack. Returns the
     * state the document should restore, or null when there is nothing to undo.
     *
     * @param Snapshot      $current
     * @return Snapshot|null
     */
    public function undo(array $current): ?array
    {
        if (!($state = \array_pop($this->undoStack))) {
            return null;
        }
        $this->undoLines -= \count($state['lines']);
        $this->redoStack[] = $current;
        $this->lastEditKind = '';

        return $state;
    }

    /**
     * Pop the last redo state, recording $current on the undo stack.
     *
     * @param Snapshot      $current
     * @return Snapshot|null
     */
    public function redo(array $current): ?array
    {
        if (!($state = \array_pop($this->redoStack))) {
            return null;
        }
        $this->undoStack[] = $current;
        $this->undoLines += \count($current['lines']);
        $this->lastEditKind = '';

        return $state;
    }

    /** Forget every step, both ways. */
    public function clear(): void
    {
        $this->undoStack = [];
        $this->redoStack = [];
        $this->undoLines = 0;
        $this->lastEditKind = '';
    }

    /** @param Snapshot $snapshot */
    private function store(array $snapshot): void
    {
        $this->undoStack[] = $snapshot;
        $this->undoLines += \count($snapshot['lines']);
        $this->redoStack = [];
        // The step cap AND the line budget, oldest-first — but never the last
        // snapshot: one giant undo step must stay undoable regardless.
        while (\count($this->undoStack) > 1 && (\count($this->undoStack) > self::MAX_UNDO || $this->undoLines > $this->maxRetainedLines)) {
            $dropped = \array_shift($this->undoStack);
            $this->undoLines -= \count($dropped['lines']);
        }
    }
}
