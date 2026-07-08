<?php

declare(strict_types=1);

namespace Porta\Editor;

use Symfony\Component\Tui\Input\Keybindings;

/**
 * The editing-verb dispatcher: every caret/selection/clipboard/line-op/editing
 * key for the active document, plus the pure edit verbs (`run()`) that key
 * dispatch and the application menu share. Extracted from the editor widget so
 * the single- and multi-caret paths live behind one entry point; the widget
 * keeps only its app-hook tables and popup routing in front of this.
 *
 * Match order is load-bearing: block copy/cut must run before
 * {@see MultiCaret::handleInput} (which would collapse the carets first),
 * indent-with-selection before the plain indent insert, and the auto-pair
 * backspace before the generic delete match. Rebuilt per document alongside
 * the other editor helpers; the clipboard is handed over on rebuild.
 */
final class EditorActions
{
    /**
     * @param \Closure(): void                                   $invalidate
     * @param \Closure(): void                                   $notifyChange
     * @param \Closure(string, string, callable(string): void): void $openPrompt
     * @param \Closure(int, int): void                           $jumpTo
     * @param \Closure(?string): void                            $setSignatureHint
     * @param \Closure(string): void                             $fireCopy          copied text → OSC 52 hook
     * @param \Closure(int, int): void                           $fireSignatureHelp
     * @param \Closure(int, int, string): void                   $requestRename     (line, utf16 col, word)
     * @param \Closure(): int                                    $contentRows       viewport rows for page up/down
     * @param \Closure(): ?string                                $commentPrefix
     * @param \Closure(): bool                                   $autoPairsEnabled
     * @param \Closure(string): bool                             $isInsertable
     */
    public function __construct(
        private readonly Document $document,
        private readonly MultiCaret $multiCaret,
        private readonly EditorCompletion $completion,
        private readonly EditorStructural $structural,
        private readonly FoldModel $foldModel,
        private readonly AutoPairService $autoPair,
        private readonly \Closure $invalidate,
        private readonly \Closure $notifyChange,
        private readonly \Closure $openPrompt,
        private readonly \Closure $jumpTo,
        private readonly \Closure $setSignatureHint,
        private readonly \Closure $fireCopy,
        private readonly \Closure $fireSignatureHelp,
        private readonly \Closure $requestRename,
        private readonly \Closure $contentRows,
        private readonly \Closure $commentPrefix,
        private readonly \Closure $autoPairsEnabled,
        private readonly \Closure $isInsertable,
        private string $clipboard = '',
    ) {}

    /**
     * The pure run() forwards of the key cascade, grouped by where they sit
     * between the special-cased blocks. Order within each list (and the
     * position of each list in handleKey) IS the historical match order —
     * see the class docblock before reordering anything.
     */
    private const array PRE_CARET_ACTIONS = ['goto_line', 'rename_symbol', 'trigger_completion', 'add_caret_above', 'add_caret_below', 'add_next_occurrence'];
    private const array HISTORY_STRUCTURE_ACTIONS = ['undo', 'redo', 'expand_selection', 'shrink_selection'];
    private const array CLIPBOARD_LINE_ACTIONS = ['copy', 'cut', 'paste', 'duplicate_line', 'move_line_up', 'move_line_down', 'toggle_comment', 'toggle_fold'];

    /** Every table-dispatched action id, for the catalog cross-check test. */
    public const array TABLE_ACTIONS = [...self::PRE_CARET_ACTIONS, ...self::HISTORY_STRUCTURE_ACTIONS, ...self::CLIPBOARD_LINE_ACTIONS];

    /** The clipboard content, handed to the next instance on a buffer switch. */
    public function clipboardValue(): string
    {
        return $this->clipboard;
    }

    /**
     * Run the first listed action whose keybinding matches $keys.
     *
     * @param list<string> $actions
     */
    private function runFirstMatch(string $keys, Keybindings $kb, array $actions): bool
    {
        foreach ($actions as $action) {
            if ($kb->matches($keys, $action)) {
                $this->run($action);

                return true;
            }
        }

        return false;
    }

    /**
     * Dispatch an editing/movement/clipboard key for the active document.
     * Called after the widget's app-hook tables have had their chance.
     */
    public function handleKey(string $keys, Keybindings $kb): void
    {
        // Ordered dispatch: each group handles the keys it owns and reports
        // whether it consumed the event. The order is load-bearing (block
        // copy/cut before MultiCaret, movement before editing, text last).
        if ($this->tryCaretAndBlockOps($keys, $kb)) {
            return;
        }
        if ($this->tryHistoryAndStructure($keys, $kb)) {
            return;
        }
        if ($this->tryMovement($keys, $kb)) {
            return;
        }
        if ($this->tryClipboardAndLineOps($keys, $kb)) {
            return;
        }
        if ($this->tryEditing($keys, $kb)) {
            return;
        }
        $this->tryTextInput($keys, $kb);
    }

    /** Prompts/completion/caret management, block copy/cut, and the multi-caret path. */
    private function tryCaretAndBlockOps(string $keys, Keybindings $kb): bool
    {
        // Prompts, completion, caret management (the latter work whether or
        // not extra carets already exist).
        if ($this->runFirstMatch($keys, $kb, self::PRE_CARET_ACTIONS)) {
            return true;
        }

        if ($kb->matches($keys, 'select_all_occurrences')) {
            $this->multiCaret->selectAllOccurrences();

            return true;
        }

        // Block copy/cut: intercept before MultiCaret::handleInput (which does
        // not know the copy/cut verbs and would collapse the carets first).
        if ($this->multiCaret->hasMultiple() && ($kb->matches($keys, 'copy') || $kb->matches($keys, 'cut'))) {
            $this->clipboard = $this->multiCaret->selectedBlockText();
            ($this->fireCopy)($this->clipboard);
            if ($kb->matches($keys, 'cut')) {
                $this->multiCaret->deleteSelections();
            } else {
                ($this->invalidate)();
            }

            return true;
        }

        // With multiple carets active, most editing/movement keys apply to
        // every caret; unhandled keys collapse back to a single caret.
        return $this->multiCaret->hasMultiple() && $this->multiCaret->handleInput($keys, $kb, $this->clipboard, fn (string $d) => ($this->isInsertable)($d));
    }

    /** Undo/redo + structural selection, statement moves, surround, and conflict nav. */
    private function tryHistoryAndStructure(string $keys, Keybindings $kb): bool
    {
        $doc = $this->document;

        // History and structural selection (grow/shrink the syntactic range).
        if ($this->runFirstMatch($keys, $kb, self::HISTORY_STRUCTURE_ACTIONS)) {
            return true;
        }

        // Move the current statement over blank-line gaps.
        if ($kb->matches($keys, 'move_statement_up') || $kb->matches($keys, 'move_statement_down')) {
            if ($doc->moveStatement($kb->matches($keys, 'move_statement_up') ? -1 : 1)) {
                ($this->notifyChange)();
            }

            return true;
        }

        // Surround the selection with a chosen pair or snippet.
        if ($kb->matches($keys, 'surround_with')) {
            $this->run('surround_with');

            return true;
        }

        // Merge-conflict navigation and resolution.
        if ($kb->matches($keys, 'next_conflict') || $kb->matches($keys, 'prev_conflict')) {
            $this->structural->gotoConflict($kb->matches($keys, 'next_conflict') ? 1 : -1);

            return true;
        }
        if ($kb->matches($keys, 'resolve_conflict')) {
            $this->structural->openResolveConflictMenu();

            return true;
        }

        return false;
    }

    /** Caret movement (with shift-select variants), select-all, document ends and paging. */
    private function tryMovement(string $keys, Keybindings $kb): bool
    {
        $doc = $this->document;

        // Movement: each action has a shift+key "select_" variant that
        // anchors/extends the selection instead of clearing it. The same
        // action→method map drives the multi-caret path.
        foreach (MultiCaret::MOVE_METHODS as $action => $method) {
            if ($kb->matches($keys, $action)) {
                if ('cursor_up' === $action || 'cursor_down' === $action) {
                    ($this->setSignatureHint)(null);
                }
                $doc->clearSelection();
                $doc->{$method}();
                ($this->invalidate)();

                return true;
            }
            if ($kb->matches($keys, substr_replace($action, 'select', 0, 6))) {
                $doc->beginSelect();
                $doc->{$method}();
                ($this->invalidate)();

                return true;
            }
        }

        if ($kb->matches($keys, 'select_all')) {
            $this->run('select_all');

            return true;
        }

        if ($kb->matches($keys, 'document_start')) {
            $doc->clearSelection();
            $doc->moveToDocumentStart();
            ($this->invalidate)();

            return true;
        }

        if ($kb->matches($keys, 'document_end')) {
            $doc->clearSelection();
            $doc->moveToDocumentEnd();
            ($this->invalidate)();

            return true;
        }

        if ($kb->matches($keys, 'page_up') || $kb->matches($keys, 'page_down')) {
            $delta = $kb->matches($keys, 'page_up') ? -($this->contentRows)() : ($this->contentRows)();
            $doc->clearSelection();
            $doc->setCursor($doc->getCursorLine() + $delta, $doc->getCursorCol());
            ($this->invalidate)();

            return true;
        }

        return false;
    }

    /** Clipboard/line/fold verbs, newline, and the indent/outdent family. */
    private function tryClipboardAndLineOps(string $keys, Keybindings $kb): bool
    {
        $doc = $this->document;

        // Clipboard, line operations and folds.
        if ($this->runFirstMatch($keys, $kb, self::CLIPBOARD_LINE_ACTIONS)) {
            return true;
        }

        // Editing
        if ($kb->matches($keys, 'new_line')) {
            $doc->insertNewLine();
            ($this->notifyChange)();

            return true;
        }

        // Tab/Shift+Tab over a selection indent/outdent the whole block of lines;
        // reached only in the single-caret path (snippet/completion/multi-caret
        // Tab all returned earlier).
        if ($kb->matches($keys, 'indent') && $doc->hasSelection()) {
            $doc->indentSelection();
            ($this->notifyChange)();

            return true;
        }
        if ($kb->matches($keys, 'prev_tab_stop')) {
            $doc->dedentSelection();
            ($this->notifyChange)();

            return true;
        }

        if ($kb->matches($keys, 'indent')) {
            $doc->insert($doc->indentUnit());
            ($this->notifyChange)();

            return true;
        }

        return false;
    }

    /** Auto-pair backspace, line delete, the delete-char family, and cancel-selection. */
    private function tryEditing(string $keys, Keybindings $kb): bool
    {
        $doc = $this->document;

        // Auto-pairs: backspacing between an empty pair removes both chars.
        if (($this->autoPairsEnabled)() && $kb->matches($keys, 'delete_char_backward') && !$doc->hasSelection() && $this->autoPair->isBetweenEmptyPair($doc)) {
            $doc->transact(function () use ($doc): void {
                $doc->deleteForward();
                $doc->deleteBackward();
            });
            ($this->notifyChange)();

            return true;
        }

        if ($kb->matches($keys, 'delete_line')) {
            $this->run('delete_line');

            return true;
        }

        $edited = match (true) {
            $kb->matches($keys, 'delete_char_backward') => $doc->deleteBackward(),
            $kb->matches($keys, 'delete_char_forward') => $doc->deleteForward(),
            $kb->matches($keys, 'delete_word_backward') => $doc->deleteWordBackward(),
            $kb->matches($keys, 'delete_to_line_end') => $doc->deleteToLineEnd(),
            default => null,
        };
        if (null !== $edited) {
            if ($edited) {
                ($this->notifyChange)();
            }

            return true;
        }

        if ($kb->matches($keys, 'select_cancel')) {
            if ($doc->hasSelection()) {
                $doc->clearSelection();
                ($this->invalidate)();
            }

            return true;
        }

        return false;
    }

    /** Plain text insertion (with auto-pairing), the final fall-through. */
    private function tryTextInput(string $keys, Keybindings $kb): bool
    {
        if (!($this->isInsertable)($keys)) {
            return false;
        }

        $doc = $this->document;
        if (($this->autoPairsEnabled)() && $this->autoPair->handle($doc, $keys)) {
            ($this->notifyChange)();
            $this->maybeAutoTrigger($keys);

            return true;
        }
        $doc->insert($keys);
        ($this->notifyChange)();
        $this->maybeAutoTrigger($keys);

        return true;
    }

    /**
     * The pure editor operations (no app callback) that both key dispatch and
     * the menu share. Bodies are the single source of truth for each verb;
     * unknown verbs are a safe no-op.
     */
    public function run(string $action): void
    {
        // A verb → handler map: each arm is the single source of truth for one
        // editor operation. The conditional/multi-statement verbs live in named
        // helpers below; unknown verbs fall through to a safe no-op.
        match ($action) {
            'undo' => $this->notifyIfChanged($this->document->undo()),
            'redo' => $this->notifyIfChanged($this->document->redo()),
            'copy' => $this->copySelection(),
            'cut' => $this->cutSelection(),
            'paste' => $this->pasteClipboard(),
            'duplicate_line' => $this->duplicateCurrentLines(),
            'delete_line' => $this->notifyIfChanged($this->document->deleteLines()),
            'move_line_up' => $this->notifyIfChanged($this->document->moveLines(-1)),
            'move_line_down' => $this->notifyIfChanged($this->document->moveLines(1)),
            'toggle_comment' => $this->toggleLineComment(),
            'toggle_fold' => $this->toggleFold(),
            'select_all' => $this->selectAllText(),
            'expand_selection' => $this->structural->expandSelection(),
            'shrink_selection' => $this->structural->shrinkSelection(),
            'surround_with' => $this->structural->openSurroundMenu(),
            'add_caret_above' => $this->multiCaret->addVertically(-1),
            'add_caret_below' => $this->multiCaret->addVertically(1),
            'add_next_occurrence' => $this->multiCaret->addNextOccurrence(),
            'trigger_completion' => $this->completion->requestCompletion(),
            'goto_line' => $this->promptGotoLine(),
            'rename_symbol' => $this->promptRename(),
            default => null,
        };
    }

    /** Fire the document-change hook only when a mutation actually happened. */
    private function notifyIfChanged(bool $changed): void
    {
        if ($changed) {
            ($this->notifyChange)();
        }
    }

    private function pasteClipboard(): void
    {
        if ('' !== $this->clipboard) {
            $this->document->insert($this->clipboard);
            ($this->notifyChange)();
        }
    }

    private function duplicateCurrentLines(): void
    {
        $this->document->duplicateLines();
        ($this->notifyChange)();
    }

    private function toggleLineComment(): void
    {
        if (null !== ($prefix = ($this->commentPrefix)())) {
            $this->document->toggleComment($prefix);
            ($this->notifyChange)();
        }
    }

    private function toggleFold(): void
    {
        $this->foldModel->toggle();
        ($this->invalidate)();
    }

    private function selectAllText(): void
    {
        $this->document->selectAll();
        ($this->invalidate)();
    }

    private function promptGotoLine(): void
    {
        ($this->openPrompt)('Go to line', '', function (string $value): void {
            if (ctype_digit($value)) {
                ($this->jumpTo)((int) $value - 1, 0);
            }
        });
    }

    private function promptRename(): void
    {
        $doc = $this->document;
        ($this->requestRename)($doc->getCursorLine(), $doc->getCursorColUtf16(), ltrim($doc->getWordBeforeCursor(), '$'));
    }

    private function maybeAutoTrigger(string $typed): void
    {
        $line = $this->document->getLine($this->document->getCursorLine());
        $prefix = substr($line, 0, $this->document->getCursorCol());

        if (str_ends_with($prefix, '->') || str_ends_with($prefix, '::')) {
            $this->completion->requestCompletion();
        }

        if ('(' === $typed || ',' === $typed) {
            ($this->fireSignatureHelp)($this->document->getCursorLine(), $this->document->getCursorColUtf16());
        } elseif (')' === $typed) {
            ($this->setSignatureHint)(null);
        }
    }

    private function copySelection(): void
    {
        if ($this->captureSelection()) {
            ($this->invalidate)();
        }
    }

    private function cutSelection(): void
    {
        if ($this->captureSelection()) {
            $this->document->deleteSelection();
            ($this->notifyChange)();
        }
    }

    /** Copy the current selection to the clipboard; false when nothing is selected. */
    private function captureSelection(): bool
    {
        $doc = $this->document;
        if (!$doc->hasSelection()) {
            return false;
        }
        $this->clipboard = $doc->getSelectedText();
        ($this->fireCopy)($this->clipboard);

        return true;
    }
}
