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

    /** The clipboard content, handed to the next instance on a buffer switch. */
    public function clipboardValue(): string
    {
        return $this->clipboard;
    }

    /**
     * Dispatch an editing/movement/clipboard key for the active document.
     * Called after the widget's app-hook tables have had their chance.
     */
    public function handleKey(string $data, Keybindings $kb): void
    {
        $doc = $this->document;

        if ($kb->matches($data, 'goto_line')) {
            $this->run('goto_line');

            return;
        }

        if ($kb->matches($data, 'rename_symbol')) {
            $this->run('rename_symbol');

            return;
        }

        if ($kb->matches($data, 'trigger_completion')) {
            $this->run('trigger_completion');

            return;
        }

        // Caret management (works whether or not extra carets already exist).
        if ($kb->matches($data, 'add_caret_above') || $kb->matches($data, 'add_caret_below')) {
            $this->run($kb->matches($data, 'add_caret_above') ? 'add_caret_above' : 'add_caret_below');

            return;
        }

        if ($kb->matches($data, 'add_next_occurrence')) {
            $this->run('add_next_occurrence');

            return;
        }

        if ($kb->matches($data, 'select_all_occurrences')) {
            $this->multiCaret->selectAllOccurrences();

            return;
        }

        // Block copy/cut: intercept before MultiCaret::handleInput (which does
        // not know the copy/cut verbs and would collapse the carets first).
        if ($this->multiCaret->hasMultiple() && ($kb->matches($data, 'copy') || $kb->matches($data, 'cut'))) {
            $this->clipboard = $this->multiCaret->selectedBlockText();
            ($this->fireCopy)($this->clipboard);
            if ($kb->matches($data, 'cut')) {
                $this->multiCaret->deleteSelections();
            } else {
                ($this->invalidate)();
            }

            return;
        }

        // With multiple carets active, most editing/movement keys apply to
        // every caret; unhandled keys collapse back to a single caret.
        if ($this->multiCaret->hasMultiple() && $this->multiCaret->handleInput($data, $kb, $this->clipboard, fn (string $d) => ($this->isInsertable)($d))) {
            return;
        }

        if ($kb->matches($data, 'undo')) {
            $this->run('undo');

            return;
        }

        if ($kb->matches($data, 'redo')) {
            $this->run('redo');

            return;
        }

        // Structural selection: grow/shrink through the syntactic hierarchy.
        if ($kb->matches($data, 'expand_selection')) {
            $this->run('expand_selection');

            return;
        }
        if ($kb->matches($data, 'shrink_selection')) {
            $this->run('shrink_selection');

            return;
        }

        // Move the current statement over blank-line gaps.
        if ($kb->matches($data, 'move_statement_up') || $kb->matches($data, 'move_statement_down')) {
            if ($doc->moveStatement($kb->matches($data, 'move_statement_up') ? -1 : 1)) {
                ($this->notifyChange)();
            }

            return;
        }

        // Surround the selection with a chosen pair or snippet.
        if ($kb->matches($data, 'surround_with')) {
            $this->run('surround_with');

            return;
        }

        // Merge-conflict navigation and resolution.
        if ($kb->matches($data, 'next_conflict') || $kb->matches($data, 'prev_conflict')) {
            $this->structural->gotoConflict($kb->matches($data, 'next_conflict') ? 1 : -1);

            return;
        }
        if ($kb->matches($data, 'resolve_conflict')) {
            $this->structural->openResolveConflictMenu();

            return;
        }

        // Movement: each action has a shift+key "select_" variant that
        // anchors/extends the selection instead of clearing it. The same
        // action→method map drives the multi-caret path.
        $movements = MultiCaret::MOVE_METHODS;
        foreach ($movements as $action => $method) {
            if ($kb->matches($data, $action)) {
                if ('cursor_up' === $action || 'cursor_down' === $action) {
                    ($this->setSignatureHint)(null);
                }
                $doc->clearSelection();
                $doc->{$method}();
                ($this->invalidate)();

                return;
            }
            if ($kb->matches($data, substr_replace($action, 'select', 0, 6))) {
                $doc->beginSelect();
                $doc->{$method}();
                ($this->invalidate)();

                return;
            }
        }

        if ($kb->matches($data, 'select_all')) {
            $this->run('select_all');

            return;
        }

        if ($kb->matches($data, 'document_start')) {
            $doc->clearSelection();
            $doc->moveToDocumentStart();
            ($this->invalidate)();

            return;
        }

        if ($kb->matches($data, 'document_end')) {
            $doc->clearSelection();
            $doc->moveToDocumentEnd();
            ($this->invalidate)();

            return;
        }

        if ($kb->matches($data, 'page_up') || $kb->matches($data, 'page_down')) {
            $delta = $kb->matches($data, 'page_up') ? -($this->contentRows)() : ($this->contentRows)();
            $doc->clearSelection();
            $doc->setCursor($doc->getCursorLine() + $delta, $doc->getCursorCol());
            ($this->invalidate)();

            return;
        }

        // Clipboard
        if ($kb->matches($data, 'copy')) {
            $this->run('copy');

            return;
        }

        if ($kb->matches($data, 'cut')) {
            $this->run('cut');

            return;
        }

        if ($kb->matches($data, 'paste')) {
            $this->run('paste');

            return;
        }

        // Line operations
        if ($kb->matches($data, 'duplicate_line')) {
            $this->run('duplicate_line');

            return;
        }

        if ($kb->matches($data, 'move_line_up') || $kb->matches($data, 'move_line_down')) {
            $this->run($kb->matches($data, 'move_line_up') ? 'move_line_up' : 'move_line_down');

            return;
        }

        if ($kb->matches($data, 'toggle_comment')) {
            $this->run('toggle_comment');

            return;
        }

        if ($kb->matches($data, 'toggle_fold')) {
            $this->run('toggle_fold');

            return;
        }

        // Editing
        if ($kb->matches($data, 'new_line')) {
            $doc->insertNewLine();
            ($this->notifyChange)();

            return;
        }

        // Tab/Shift+Tab over a selection indent/outdent the whole block of lines;
        // reached only in the single-caret path (snippet/completion/multi-caret
        // Tab all returned earlier).
        if ($kb->matches($data, 'indent') && $doc->hasSelection()) {
            $doc->indentSelection();
            ($this->notifyChange)();

            return;
        }
        if ($kb->matches($data, 'prev_tab_stop')) {
            $doc->dedentSelection();
            ($this->notifyChange)();

            return;
        }

        if ($kb->matches($data, 'indent')) {
            $doc->insert($doc->indentUnit());
            ($this->notifyChange)();

            return;
        }

        // Auto-pairs: backspacing between an empty pair removes both chars.
        if (($this->autoPairsEnabled)() && $kb->matches($data, 'delete_char_backward') && !$doc->hasSelection() && $this->autoPair->isBetweenEmptyPair($doc)) {
            $doc->transact(function () use ($doc): void {
                $doc->deleteForward();
                $doc->deleteBackward();
            });
            ($this->notifyChange)();

            return;
        }

        if ($kb->matches($data, 'delete_line')) {
            $this->run('delete_line');

            return;
        }

        $edited = match (true) {
            $kb->matches($data, 'delete_char_backward') => $doc->deleteBackward(),
            $kb->matches($data, 'delete_char_forward') => $doc->deleteForward(),
            $kb->matches($data, 'delete_word_backward') => $doc->deleteWordBackward(),
            $kb->matches($data, 'delete_to_line_end') => $doc->deleteToLineEnd(),
            default => null,
        };
        if (null !== $edited) {
            if ($edited) {
                ($this->notifyChange)();
            }

            return;
        }

        if ($kb->matches($data, 'select_cancel')) {
            if ($doc->hasSelection()) {
                $doc->clearSelection();
                ($this->invalidate)();
            }

            return;
        }

        // Plain text input
        if (($this->isInsertable)($data)) {
            if (($this->autoPairsEnabled)() && $this->autoPair->handle($doc, $data)) {
                ($this->notifyChange)();
                $this->maybeAutoTrigger($data);

                return;
            }
            $doc->insert($data);
            ($this->notifyChange)();
            $this->maybeAutoTrigger($data);
        }
    }

    /**
     * The pure editor operations (no app callback) that both key dispatch and
     * the menu share. Bodies are the single source of truth for each verb;
     * unknown verbs are a safe no-op.
     */
    public function run(string $action): void
    {
        $doc = $this->document;
        switch ($action) {
            case 'undo':
                if ($doc->undo()) {
                    ($this->notifyChange)();
                }

                return;
            case 'redo':
                if ($doc->redo()) {
                    ($this->notifyChange)();
                }

                return;
            case 'copy':
                $this->copySelection(cut: false);

                return;
            case 'cut':
                $this->copySelection(cut: true);

                return;
            case 'paste':
                if ('' !== $this->clipboard) {
                    $doc->insert($this->clipboard);
                    ($this->notifyChange)();
                }

                return;
            case 'duplicate_line':
                $doc->duplicateLines();
                ($this->notifyChange)();

                return;
            case 'delete_line':
                if ($doc->deleteLines()) {
                    ($this->notifyChange)();
                }

                return;
            case 'move_line_up':
            case 'move_line_down':
                if ($doc->moveLines('move_line_up' === $action ? -1 : 1)) {
                    ($this->notifyChange)();
                }

                return;
            case 'toggle_comment':
                if (null !== ($prefix = ($this->commentPrefix)())) {
                    $doc->toggleComment($prefix);
                    ($this->notifyChange)();
                }

                return;
            case 'toggle_fold':
                $this->foldModel->toggle();
                ($this->invalidate)();

                return;
            case 'select_all':
                $doc->selectAll();
                ($this->invalidate)();

                return;
            case 'expand_selection':
                $this->structural->expandSelection();

                return;
            case 'shrink_selection':
                $this->structural->shrinkSelection();

                return;
            case 'surround_with':
                $this->structural->openSurroundMenu();

                return;
            case 'add_caret_above':
            case 'add_caret_below':
                $this->multiCaret->addVertically('add_caret_above' === $action ? -1 : 1);

                return;
            case 'add_next_occurrence':
                $this->multiCaret->addNextOccurrence();

                return;
            case 'trigger_completion':
                $this->completion->requestCompletion();

                return;
            case 'goto_line':
                ($this->openPrompt)('Go to line', '', function (string $value): void {
                    if (ctype_digit($value)) {
                        ($this->jumpTo)((int) $value - 1, 0);
                    }
                });

                return;
            case 'rename_symbol':
                ($this->requestRename)($doc->getCursorLine(), $doc->getCursorColUtf16(), ltrim($doc->getWordBeforeCursor(), '$'));

                return;
        }
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

    private function copySelection(bool $cut): void
    {
        $doc = $this->document;
        if (!$doc->hasSelection()) {
            return;
        }
        $this->clipboard = $doc->getSelectedText();
        ($this->fireCopy)($this->clipboard);
        if ($cut) {
            $doc->deleteSelection();
            ($this->notifyChange)();
        } else {
            ($this->invalidate)();
        }
    }
}
