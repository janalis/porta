<?php

declare(strict_types=1);

namespace Porta\Editor;

use Symfony\Component\Tui\Input\Keybindings;

/**
 * Multi-caret editing: the set of secondary carets (the primary caret is the
 * Document's own cursor) and every operation that grows, moves, or edits at all
 * of them at once.
 *
 * It owns the caret set and drives the injected {@see Document}; the two
 * closures are the only seam back to the widget — one to request a redraw, one
 * to announce a buffer change — so the widget stays out of the caret-set model.
 */
final class MultiCaret
{
    /** Cursor action → Document move method, shared with the single-caret path. */
    public const array MOVE_METHODS = [
        'cursor_up' => 'moveUp',
        'cursor_down' => 'moveDown',
        'cursor_left' => 'moveLeft',
        'cursor_right' => 'moveRight',
        'cursor_word_left' => 'moveWordLeft',
        'cursor_word_right' => 'moveWordRight',
        'cursor_line_start' => 'moveToLineStart',
        'cursor_line_end' => 'moveToLineEnd',
    ];

    /** Shift+movement action → Document move method: extends every caret's selection. */
    public const array SELECT_METHODS = [
        'select_up' => 'moveUp',
        'select_down' => 'moveDown',
        'select_left' => 'moveLeft',
        'select_right' => 'moveRight',
        'select_word_left' => 'moveWordLeft',
        'select_word_right' => 'moveWordRight',
        'select_line_start' => 'moveToLineStart',
        'select_line_end' => 'moveToLineEnd',
    ];

    /** Editing verbs applied at every caret, keyed by action → Document method. */
    private const array EDIT_METHODS = [
        'new_line' => 'insertNewLine',
        'delete_char_backward' => 'deleteBackward',
        'delete_char_forward' => 'deleteForward',
        'delete_word_backward' => 'deleteWordBackward',
    ];

    /**
     * Secondary carets (the primary caret is the Document's own cursor). Each
     * caret carries a full selection anchor `(anchorLine, anchorCol)`;
     * `anchorLine === line && anchorCol === col` means a bare point (no selection).
     *
     * @var list<Caret>
     */
    private array $extraCarets = [];

    /** @var list<Caret> the last {@see alive()} result… */
    private array $aliveCache = [];
    /** …the document version it was computed for (-1 = never)… */
    private int $aliveVersion = -1;
    /** @var list<Caret> …and the extras it was computed from. */
    private array $aliveFor = [];

    /**
     * @param \Closure(): void $onInvalidate request a redraw
     * @param \Closure(): void $onChange     announce a buffer edit
     */
    public function __construct(
        private readonly Document $document,
        private readonly \Closure $onInvalidate,
        private readonly \Closure $onChange,
    ) {}

    public function hasMultiple(): bool
    {
        return [] !== $this->alive();
    }

    /** @return list<Caret> secondary carets (for tests/render) */
    public function carets(): array
    {
        return $this->alive();
    }

    /**
     * The extras that still address text that exists.
     *
     * A caret is a position, and a WHOLESALE document swap — the filesystem
     * reconciler reloading a file changed on disk, a project-wide replace, a
     * plugin restoring a snapshot — moves every position out from under them
     * without telling anyone: `Document::replaceAll()` has no way to reach the
     * widget-owned carets. Left alone, the next keystroke writes at a line that
     * no longer exists: typing one character into a one-line document with two
     * stale extras produced "Xtiny\nXX".
     *
     * Filtering on READ rather than clearing on write is what keeps that true
     * for every path, including ones that do not know carets exist. A caret
     * whose line survives but whose column does not is clamped instead of
     * dropped — the user's second cursor is still on that line.
     *
     * @return list<Caret>
     */
    private function alive(): array
    {
        // Memoised per document version AND per extras array: this runs several
        // times a frame, and a column selection can be a thousand carets — the
        // filter is cheap per caret but not free, and a render must not pay for
        // it once per read.
        $version = $this->document->version;
        if ($this->aliveVersion === $version && $this->aliveFor === $this->extraCarets) {
            return $this->aliveCache;
        }

        $lines = $this->document->getLineCount();
        $kept = [];
        foreach ($this->extraCarets as $caret) {
            if ($caret->line >= $lines || $caret->anchorLine >= $lines) {
                continue;
            }
            $col = $this->document->clampCol($caret->line, $caret->col);
            $anchorCol = $this->document->clampCol($caret->anchorLine, $caret->anchorCol);
            $kept[] =
                $col === $caret->col && $anchorCol === $caret->anchorCol
                    ? $caret
                    : new Caret($caret->line, $col, $caret->anchorLine, $anchorCol);
        }

        $this->aliveVersion = $version;
        $this->aliveFor = $this->extraCarets;
        $this->aliveCache = $kept;

        return $kept;
    }

    /**
     * Every caret (primary + extras) with its selection, for rendering all carets
     * the same way when multiple are active.
     *
     * @return list<Caret>
     */
    public function allCarets(): array
    {
        return $this->caretRecords();
    }

    public function clear(): void
    {
        if ([] !== $this->extraCarets) {
            $this->extraCarets = [];
            ($this->onInvalidate)();
        }
    }

    /**
     * Replace the secondary carets wholesale (used to restore them on undo/redo);
     * the primary caret stays the Document's own cursor.
     *
     * @param list<Caret> $extras
     */
    public function setExtras(array $extras): void
    {
        $this->extraCarets = $extras;
        ($this->onInvalidate)();
    }

    /**
     * Add a caret one line above (-1) or below (+1) the primary, at the same
     * display column (column-select building block).
     */
    public function addVertically(int $direction): void
    {
        $doc = $this->document;
        $displayCol = $doc->getCursorDisplayCol();
        $targetLine = $doc->cursorLine + $direction;
        if ($targetLine < 0 || $targetLine >= $doc->getLineCount()) {
            return;
        }
        $col = $doc->byteColForDisplayCol($targetLine, $displayCol);
        // The newly added line becomes the primary; the old primary joins the
        // extras, so growing up/down keeps the "active" caret at the frontier.
        $this->extraCarets[] = Caret::point($doc->cursorLine, $doc->cursorCol);
        $doc->setCursor($targetLine, $col);
        $this->dedupeCarets();
        ($this->onInvalidate)();
    }

    /**
     * Add a caret at the next occurrence of the word under the primary caret.
     */
    public function addNextOccurrence(): void
    {
        $word = CaretWord::at($this->document);
        if ('' === $word) {
            return;
        }
        $doc = $this->document;
        $current = [$doc->cursorLine, $doc->cursorCol];

        // Forward-only scan; the primary always advances, so occurrences never
        // repeat and no explicit dedupe against existing carets is needed.
        foreach ($this->findOccurrences($word) as [$line, $col]) {
            if ([$line, $col] <= $current) {
                continue;
            }
            $this->extraCarets[] = Caret::point($doc->cursorLine, $doc->cursorCol);
            $doc->setCursor($line, $col + \strlen($word));
            $this->dedupeCarets();
            ($this->onInvalidate)();

            return;
        }
    }

    public function selectAllOccurrences(): void
    {
        $word = CaretWord::at($this->document);
        if ('' === $word) {
            return;
        }
        // A word taken from the buffer always has at least one occurrence.
        $occurrences = $this->findOccurrences($word);
        $this->extraCarets = [];
        $doc = $this->document;
        foreach ($occurrences as $i => [$line, $col]) {
            $end = $col + \strlen($word);
            if (0 === $i) {
                $doc->setCursor($line, $end);

                continue;
            }
            $this->extraCarets[] = Caret::point($line, $end);
        }
        $this->dedupeCarets();
        ($this->onInvalidate)();
    }

    /**
     * Replace the caret set with these selections — the find bar's Select All
     * Occurrences. $carets[$primary] goes on the Document so the view stays on
     * the match being looked at; the rest become the extras.
     *
     * @param non-empty-list<Caret> $carets
     */
    public function selectRanges(array $carets, int $primary): void
    {
        $this->restore($carets[$primary]);
        // The primary stays in the list: the dedupe drops it, being where the
        // Document's caret now is.
        $this->extraCarets = $carets;
        $this->dedupeCarets();
        ($this->onInvalidate)();
    }

    /**
     * Route one keystroke while extra carets exist: cancel, move-all, edit-all,
     * or plain text at every caret. Returns false (leaving the carets intact) for
     * any input multi-caret mode does not itself handle.
     *
     * @param \Closure(string): bool $isInsertable printable-char guard from the widget
     */
    public function handleInput(string $keys, Keybindings $kb, string $clipboard, \Closure $isInsertable): bool
    {
        $act = match (true) {
            $kb->matches($keys, 'select_cancel') => function (): void {
                $this->clear();
                $this->document->clearSelection();
            },
            // Movement: move every caret, then dedupe.
            null !== ($move = self::methodFor(self::MOVE_METHODS, $keys, $kb)) => fn () => $this->moveAll($move),
            // Shift+movement: extend every caret's selection.
            null !== ($extend = self::methodFor(self::SELECT_METHODS, $keys, $kb)) => fn () => $this->extendAll($extend),
            // Editing operations applied at every caret: the bare Document verbs are
            // table-driven; indent and paste carry an argument, so they stay explicit.
            null !== ($edit = self::methodFor(self::EDIT_METHODS, $keys, $kb)) => fn () => $this->applyEdit(static fn (Document $d) => $d->{$edit}()),
            $kb->matches($keys, 'indent') => fn () => $this->applyEdit(static fn (Document $d) => $d->insert($d->indentUnit())),
            $kb->matches($keys, 'paste') && '' !== $clipboard => fn () => $this->pasteBlock($clipboard),
            // Plain text input at every caret.
            $isInsertable($keys) => fn () => $this->applyEdit(static fn (Document $d) => $d->insert($keys)),
            default => null,
        };
        if (null !== $act) {
            $act();
        }

        // Any other input (stray terminal sequences, unowned keys) leaves the
        // carets intact and falls through to normal handling — the carets are
        // "sticky" so injected escape sequences can never silently wipe them.
        // Deliberate exits: Escape (above) and a plain mouse click (via the Ide).
        return null !== $act;
    }

    /**
     * The Document method of the first action in $table that $keys matches.
     *
     * @template T of string
     *
     * @param array<string, T> $table action => Document method
     *
     * @return T|null
     */
    private static function methodFor(array $table, string $keys, Keybindings $kb): ?string
    {
        $action = \array_find(\array_keys($table), static fn (string $action): bool => $kb->matches($keys, $action));

        return null === $action ? null : $table[$action];
    }

    /**
     * Paste at every caret, distributing the clipboard's LINES one per caret
     * when the counts match.
     *
     * That is the case a block copy produces: {@see selectedBlockText()} joins
     * each caret's selection with newlines, so copying a three-row rectangle
     * yields three lines. Inserting all three at each of three carets — which is
     * what this used to do — turns nine lines out of three, and block copy and
     * paste did not round-trip.
     *
     * Any other count keeps the old behaviour deliberately: two lines across
     * five carets has no correct distribution, and guessing one is worse than
     * repeating the whole clipboard, which is at least predictable.
     */
    /**
     * Public because `Ctrl+V` is not the only way to paste.
     *
     * Clipboard HISTORY picks an older entry and then inserts it, and it must
     * land in the same places `Ctrl+V` would — the two keys differ in WHICH
     * text, never in where. Reached through {@see handleInput()} for the plain
     * key and called directly by the history verb, which the multi-caret path
     * cannot intercept because the pick happens later, in an overlay callback.
     */
    public function pasteBlock(string $clipboard): void
    {
        $lines = \explode("\n", $clipboard);
        // No ">1 line" clause: with one line the two branches insert the same
        // text, so it would be a guard nothing could ever distinguish.
        $perCaret = \count($lines) === \count($this->caretRecords());

        $this->applyEdit(static fn (Document $d, int $index) => $d->insert($perCaret ? $lines[$index] : $clipboard));
    }

    /**
     * Apply an editing operation at every caret, bottom-to-top, as one atomic
     * undo step.
     *
     * The op is told the caret's index in TOP-TO-BOTTOM order, which is the
     * order a block of text reads in. The walk itself runs bottom-to-top, so
     * the two orders are deliberately different: an op that cares (block paste)
     * needs the reading order, and the walk needs the safe one.
     *
     * @param callable(Document, int): void $op
     */
    private function applyEdit(callable $op): void
    {
        $doc = $this->document;
        $records = $this->caretRecords();
        // Numbered top-to-bottom first...
        \usort($records, static fn (Caret $a, Caret $b): int => [$a->line, $a->col] <=> [$b->line, $b->col]);
        // ...then walked bottom-to-top, keeping those numbers, so earlier
        // (higher) edits never invalidate the positions of carets still to be
        // processed.
        $walk = \array_reverse($records, preserve_keys: true);

        // Each caret is kept by what follows it — lines to the last one, bytes
        // to the end of its own — since every later edit lands above it or to
        // its left on its line, which moves neither, where a (line, col) taken
        // at the time drifts: `xy = xy` typed `abc` at two carets read
        // `xyabc = xycba`. Constant work per caret: no walk of the text.
        $fromEnd = [];
        $doc->transact(function () use ($doc, $walk, $op, &$fromEnd): void {
            foreach ($walk as $index => $record) {
                // Restore each caret's selection so insert/delete replace it; a
                // point caret just moves the cursor.
                $this->restore($record);
                $op($doc, $index);
                $fromEnd[] = [$doc->getLineCount() - $doc->cursorLine, \strlen($doc->getLine($doc->cursorLine)) - $doc->cursorCol];
            }
        });
        $results = \array_map(static function (array $after) use ($doc): array {
            $line = $doc->getLineCount() - $after[0];

            return [$line, \strlen($doc->getLine($line)) - $after[1]];
        }, $fromEnd);

        $this->rebuildFromPositions($results);
        ($this->onChange)();
    }

    /** @param 'moveUp'|'moveDown'|'moveLeft'|'moveRight'|'moveWordLeft'|'moveWordRight'|'moveToLineStart'|'moveToLineEnd' $method */
    private function moveAll(string $method): void
    {
        $doc = $this->document;
        $doc->clearSelection();
        $positions = [];
        foreach ($this->caretPositions() as [$line, $col]) {
            $doc->setCursor($line, $col);
            $doc->{$method}();
            $positions[] = [$doc->cursorLine, $doc->cursorCol];
        }
        $this->rebuildFromPositions($positions);
        ($this->onInvalidate)();
    }

    /**
     * Reduce a set of [line, col] positions to the primary caret (lowest) plus
     * sorted, deduped extras.
     *
     * @param list<array{0: int, 1: int}> $positions
     */
    private function rebuildFromPositions(array $positions): void
    {
        \sort($positions);
        $positions = \array_values(\array_unique($positions, \SORT_REGULAR));
        [$pl, $pc] = $positions[0];
        $this->document->setCursor($pl, $pc);
        $this->extraCarets = [];
        foreach (\array_slice($positions, offset: 1) as [$line, $col]) {
            $this->extraCarets[] = Caret::point($line, $col);
        }
    }

    /** @return list<array{0: int, 1: int}> all caret [line, col] positions */
    private function caretPositions(): array
    {
        $positions = [[$this->document->cursorLine, $this->document->cursorCol]];
        foreach ($this->extraCarets as $caret) {
            $positions[] = [$caret->line, $caret->col];
        }

        return $positions;
    }

    /**
     * The primary caret (the Document's own cursor + selection) as a record; the
     * anchor is whichever end of the selection is not the cursor (else the cursor).
     */
    private function primaryRecord(): Caret
    {
        $doc = $this->document;
        $line = $doc->cursorLine;
        $col = $doc->cursorCol;
        $range = $doc->getSelectionRange();
        if (null === $range) {
            return Caret::point($line, $col);
        }
        $atStart = $range['startLine'] === $line && $range['startCol'] === $col;
        $anchorLine = $atStart ? $range['endLine'] : $range['startLine'];
        $anchorCol = $atStart ? $range['endCol'] : $range['startCol'];

        return new Caret($line, $col, $anchorLine, $anchorCol);
    }

    /** @return list<Caret> primary + extras, with selections */
    private function caretRecords(): array
    {
        return [$this->primaryRecord(), ...$this->alive()];
    }

    /** Restore a caret's selection (or bare cursor) onto the Document. */
    private function restore(Caret $record): void
    {
        if (!$record->hasSelection()) {
            $this->document->setCursor($record->line, $record->col);

            return;
        }
        $this->document->setSelectionRange($record->anchorLine, $record->anchorCol, $record->line, $record->col);
    }

    /**
     * Extend every caret's selection by $method (shift+movement), then rebuild keeping anchors.
     *
     * @param 'moveUp'|'moveDown'|'moveLeft'|'moveRight'|'moveWordLeft'|'moveWordRight'|'moveToLineStart'|'moveToLineEnd' $method
     */
    private function extendAll(string $method): void
    {
        $doc = $this->document;
        $records = [];
        foreach ($this->caretRecords() as $record) {
            $this->restore($record);
            $doc->beginSelect(); // anchor at the cursor when the caret had none
            $doc->{$method}();
            $records[] = $this->primaryRecord();
        }
        $this->rebuildFromRecords($records);
        ($this->onInvalidate)();
    }

    /**
     * Reduce a set of caret records to the primary (topmost) plus sorted, deduped
     * extras, preserving each caret's selection anchor.
     *
     * @param list<Caret> $records
     */
    private function rebuildFromRecords(array $records): void
    {
        \usort($records, static fn (Caret $a, Caret $b): int => [$a->line, $a->col] <=> [$b->line, $b->col]);
        $seen = [];
        $unique = [];
        foreach ($records as $record) {
            $key = $record->line.':'.$record->col;
            if (!\array_key_exists($key, $seen)) {
                $seen[$key] = true;
                $unique[] = $record;
            }
        }
        /** @var non-empty-list<Caret> $unique */
        $this->restore($unique[0]);
        $this->extraCarets = \array_slice($unique, offset: 1);
    }

    /**
     * Replace the caret set with a column (rectangular) selection: one caret per
     * row in the anchor..caret row span, each selecting the same display-column
     * range (clamped per line). The caret row stays the primary so the terminal
     * cursor follows the pointer.
     */
    public function setColumnSelection(int $anchorRow, int $anchorDisplayCol, int $caretRow, int $caretDisplayCol): void
    {
        $doc = $this->document;
        $top = $doc->clampLine(\min($anchorRow, $caretRow));
        $bottom = $doc->clampLine(\max($anchorRow, $caretRow));

        $this->extraCarets = [];
        for ($row = $top; $row <= $bottom; ++$row) {
            $aByte = $doc->byteColForDisplayCol($row, $anchorDisplayCol);
            $cByte = $doc->byteColForDisplayCol($row, $caretDisplayCol);
            if ($row !== $caretRow) {
                $this->extraCarets[] = new Caret($row, $cByte, $row, $aByte);

                continue;
            }
            if ($aByte !== $cByte) {
                $doc->setSelectionRange($row, $aByte, $row, $cByte);

                continue;
            }
            $doc->setCursor($row, $cByte);
        }
        $this->dedupeCarets();
        ($this->onInvalidate)();
    }

    /**
     * Add a point caret at ($line, $col), or remove the caret already there.
     * The clicked spot becomes the primary; the last caret is never removed.
     */
    public function toggleCaretAt(int $line, int $col): void
    {
        $doc = $this->document;

        $onPrimary = $line === $doc->cursorLine && $col === $doc->cursorCol;
        if ($onPrimary) {
            if ([] === $this->extraCarets) {
                return; // never remove the only caret
            }
            $promoted = \array_shift($this->extraCarets);
            $doc->setCursor($promoted->line, $promoted->col);
        }

        $existing = $onPrimary ? null : \array_find_key($this->extraCarets, static fn (Caret $caret): bool => $caret->line === $line && $caret->col === $col);
        if (null !== $existing) {
            \array_splice($this->extraCarets, $existing, length: 1);
        }
        if (!$onPrimary && null === $existing) {
            // New caret: make the click the primary, push the old primary to extras.
            $this->extraCarets[] = Caret::point($doc->cursorLine, $doc->cursorCol);
            $doc->setCursor($line, $col);
            $this->dedupeCarets();
        }
        ($this->onInvalidate)();
    }

    /** The selected text of every caret (top-to-bottom), joined by newlines; points contribute ''. */
    public function selectedBlockText(): string
    {
        $records = $this->caretRecords();
        \usort($records, static fn (Caret $a, Caret $b): int => [$a->line, $a->col] <=> [$b->line, $b->col]);

        return \implode("\n", \array_map($this->rangeText(...), $records));
    }

    /** The text of a caret's selection (possibly spanning lines); '' for a point. */
    private function rangeText(Caret $record): string
    {
        [$sl, $sc, $el, $ec] = $record->orderedRange();

        return $this->document->getTextInRange($sl, $sc, $el, $ec);
    }

    /** Delete every caret's selection (block cut) as one atomic undo step. */
    public function deleteSelections(): void
    {
        $this->applyEdit(static fn (Document $d): bool => $d->deleteBackward());
    }

    private function dedupeCarets(): void
    {
        $primary = [$this->document->cursorLine, $this->document->cursorCol];
        $seen = [$primary[0].':'.$primary[1] => true];
        $unique = [];
        foreach ($this->extraCarets as $caret) {
            $key = $caret->line.':'.$caret->col;
            if (!\array_key_exists($key, $seen)) {
                $seen[$key] = true;
                $unique[] = $caret;
            }
        }
        $this->extraCarets = $unique;
    }

    /**
     * All start positions of $word in the document, in reading order.
     *
     * @return list<array{0: int, 1: int}> [line, startCol]
     */
    private function findOccurrences(string $word): array
    {
        $result = [];
        foreach ($this->document->lines as $lineIndex => $line) {
            $offset = 0;
            while (false !== ($pos = \strpos($line, $word, $offset))) {
                $result[] = [(int) $lineIndex, $pos];
                $offset = $pos + \max(1, \strlen($word));
            }
        }

        return $result;
    }
}
