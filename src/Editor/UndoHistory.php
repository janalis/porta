<?php

declare(strict_types=1);

namespace Porta\Editor;

/**
 * Undo/redo history for a {@see Document}: the undo and redo snapshot stacks,
 * time-based coalescing of consecutive single-character edits, and compound
 * (atomic) edit grouping. A snapshot is whatever opaque state the document hands
 * over ({lines, line, col}); this class never inspects it, it only stores and
 * returns it. The clock is injectable so coalescing is deterministically testable.
 *
 * @phpstan-type Caret array{line: int, col: int, anchorLine: int, anchorCol: int}
 * @phpstan-type Snapshot array{lines: string[], line: int, col: int, anchor: array{line: int, col: int}|null, extras: list<Caret>}
 */
final class UndoHistory
{
    private const MAX_UNDO = 200;

    /** @var list<Snapshot> */
    private array $undoStack = [];
    /** @var list<Snapshot> */
    private array $redoStack = [];

    /** Coalescing state: consecutive edits with the same key within 1s merge. */
    private float $lastEditAt = 0.0;
    private string $lastEditKind = '';

    /**
     * Compound-edit depth. While > 0, only the first push in the group takes a
     * snapshot; the rest are suppressed so a batch of edits is one undo step.
     */
    private int $compoundDepth = 0;
    private bool $compoundSnapshotted = false;

    /** @var \Closure(): float */
    private \Closure $clock;

    /** @param (\Closure(): float)|null $clock defaults to microtime(true) */
    public function __construct(?\Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): float => microtime(true);
    }

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
            if ($this->compoundSnapshotted) {
                $this->redoStack = [];

                return;
            }
            $this->compoundSnapshotted = true;
            $this->lastEditKind = '';
            $this->store($snapshot);

            return;
        }

        $now = ($this->clock)();
        if (null !== $coalesceKey && $coalesceKey === $this->lastEditKind && ($now - $this->lastEditAt) < 1.0 && [] !== $this->undoStack) {
            $this->lastEditAt = $now;
            $this->redoStack = [];

            return;
        }
        $this->lastEditKind = $coalesceKey ?? '';
        $this->lastEditAt = $now;
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
        if (!($state = array_pop($this->undoStack))) {
            return null;
        }
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
        if (!($state = array_pop($this->redoStack))) {
            return null;
        }
        $this->undoStack[] = $current;
        $this->lastEditKind = '';

        return $state;
    }

    /** @param Snapshot $snapshot */
    private function store(array $snapshot): void
    {
        $this->undoStack[] = $snapshot;
        $this->redoStack = [];
        if (\count($this->undoStack) > self::MAX_UNDO) {
            array_shift($this->undoStack);
        }
    }
}
