<?php

declare(strict_types=1);

namespace Porta\Editor;

use Porta\I18n\Translator;
use Porta\Support\FlashSink;
use Porta\Widget\Render\EditorRenderer;
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
final readonly class EditorActions
{
    public function __construct(
        private Document $document,
        private MultiCaret $multiCaret,
        private EditorCompletion $completion,
        private EditorStructural $structural,
        private FoldModel $foldModel,
        private AutoPairService $autoPair,
        private EditorHost $host,
        private ClipboardRing $clipboard = new ClipboardRing(),
        // Defaulted exactly as {@see \Porta\Widget\CodeEditorWidget}'s is, and
        // for the same reason: the widget-tree tests need not thread one, and
        // the one string this class shows the user is a modal TITLE, which
        // every other overlay in `src/` translates at its call site.
        private Translator $translator = new Translator('en'),
        // The other thing this class occasionally has to say: that a verb had
        // nothing to act on. Optional for the same reason the translator is —
        // the widget-tree tests thread neither — and typed as the Support role
        // rather than the Ui service, so `Editor` keeps holding no Ui.
        private ?FlashSink $flash = null,
    ) {
        $this->column = new ColumnSelection();
    }

    /**
     * The keyboard column-selection anchor. Rebuilt with the rest of the
     * per-document helpers, so switching buffers leaves the mode behind — a
     * rectangle anchored in a file you are no longer looking at is nothing.
     */
    private ColumnSelection $column;

    /** Which way each selection verb moves the rectangle's moving corner. */
    private const array COLUMN_MOVES = [
        'select_up' => [-1, 0],
        'select_down' => [1, 0],
        'select_left' => [0, -1],
        'select_right' => [0, 1],
    ];

    /**
     * The pure run() forwards of the key cascade, grouped by where they sit
     * between the special-cased blocks. Order within each list (and the
     * position of each list in handleKey) IS the historical match order —
     * see the class docblock before reordering anything.
     */
    private const array PRE_CARET_ACTIONS = ['goto_line', 'rename_symbol', 'trigger_completion', 'parameter_info', 'toggle_column_mode', 'add_caret_above', 'add_caret_below', 'add_next_occurrence'];
    private const array HISTORY_STRUCTURE_ACTIONS = ['undo', 'redo', 'expand_selection', 'shrink_selection', 'goto_matching_bracket'];
    private const array CLIPBOARD_LINE_ACTIONS = [
        'copy',
        'cut',
        'paste',
        'paste_history',
        'duplicate_line',
        'move_line_up',
        'move_line_down',
        'insert_line_below',
        'insert_line_above',
        'complete_statement',
        'toggle_comment',
        'toggle_block_comment',
        'toggle_fold',
    ];

    /** Every table-dispatched action id, for the catalog cross-check test. */
    public const array TABLE_ACTIONS = [...self::PRE_CARET_ACTIONS, ...self::HISTORY_STRUCTURE_ACTIONS, ...self::CLIPBOARD_LINE_ACTIONS];

    /**
     * The clipboard, handed to the next instance on a buffer switch.
     *
     * The RING rather than its top entry, and by handle: one history across
     * every buffer in the session is the only version of the feature worth
     * having, since the copy you want back is usually from the file you just
     * left.
     */
    public function clipboardRing(): ClipboardRing
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
            if (!$kb->matches($keys, $action)) {
                continue;
            }

            $this->run($action);

            return true;
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
        $handled =
            $this->tryCaretAndBlockOps($keys, $kb)
            || $this->tryHistoryAndStructure($keys, $kb)
            || $this->tryMovement($keys, $kb)
            || $this->tryClipboardAndLineOps($keys, $kb)
            || $this->tryEditing($keys, $kb);
        if (!$handled) {
            $this->tryTextInput($keys);
        }
    }

    /**
     * Run the action a key cascade picked, if it picked one; whether it did.
     *
     * @param ?\Closure(): void $action
     */
    private static function perform(?\Closure $action): bool
    {
        if (null !== $action) {
            $action();
        }

        return null !== $action;
    }

    /** Prompts/completion/caret management, block copy/cut, and the multi-caret path. */
    private function tryCaretAndBlockOps(string $keys, Keybindings $kb): bool
    {
        // Column mode owns the selection verbs while it is on, and has to be
        // asked BEFORE the multi-caret path below: that path extends each
        // caret's own selection, which is the linear behaviour column mode
        // exists to replace.
        // Then prompts, completion, caret management (the latter work whether
        // or not extra carets already exist).
        if ($this->tryColumnMode($keys, $kb) || $this->runFirstMatch($keys, $kb, self::PRE_CARET_ACTIONS)) {
            return true;
        }

        $handled = self::perform(match (true) {
            $kb->matches($keys, 'select_all_occurrences') => $this->multiCaret->selectAllOccurrences(...),
            // Block copy/cut: intercept before MultiCaret::handleInput (which does
            // not know the copy/cut verbs and would collapse the carets first).
            $this->multiCaret->hasMultiple() && $kb->matches($keys, 'cut') => $this->cutBlock(...),
            $this->multiCaret->hasMultiple() && $kb->matches($keys, 'copy') => $this->copyBlock(...),
            default => null,
        });

        // With multiple carets active, most editing/movement keys apply to
        // every caret; unhandled keys collapse back to a single caret.
        return $handled || ($this->multiCaret->hasMultiple() && $this->multiCaret->handleInput($keys, $kb, $this->clipboard->latest(), fn (string $d): bool => $this->host->isInsertable($d)));
    }

    /** Copy every caret's selection as one block. */
    private function copyBlock(): void
    {
        $this->clipboardBlock();
        $this->host->invalidate();
    }

    /** Copy every caret's selection as one block, then remove the selections. */
    private function cutBlock(): void
    {
        $this->clipboardBlock();
        $this->multiCaret->deleteSelections();
    }

    private function clipboardBlock(): void
    {
        $this->clipboard->push($this->multiCaret->selectedBlockText());
        $this->host->fireCopy($this->clipboard->latest());
    }

    /** Whether keyboard column selection is on — the status bar says so. */
    public function columnMode(): bool
    {
        return $this->column->isActive();
    }

    /**
     * Turn keyboard column selection on or off, anchoring at the caret.
     *
     * A table verb rather than a key match, so the menu and the palette reach
     * it the way they reach everything else. The mode announces itself in the
     * status bar rather than with a flash: a MODE whose state you cannot see is
     * a trap, and a toast that expires says nothing two keystrokes later.
     */
    private function toggleColumnMode(): void
    {
        $doc = $this->document;
        // @mago-expect lint:no-else-clause -- a genuine two-way toggle with a shared tail below; an early return would duplicate it
        if ($this->column->isActive()) {
            $this->column->cancel();
        } else {
            $this->column->begin($doc->cursorLine, $doc->displayColAt($doc->cursorLine, $doc->cursorCol));
        }
        $this->host->invalidate();
    }

    /**
     * While column mode is on, turn the selection verbs into rectangle moves.
     *
     * The toggle itself is a table verb ({@see toggleColumnMode()}); this owns
     * only the keys whose MEANING the mode changes. Escape leaves the mode as
     * well as the selection, so the key that gets you out of everything else
     * gets you out of this too.
     */
    private function tryColumnMode(string $keys, Keybindings $kb): bool
    {
        $doc = $this->document;
        if (!$this->column->isActive() || $kb->matches($keys, 'select_cancel')) {
            if ($this->column->isActive()) {
                $this->column->cancel();
            }

            return false; // let the usual cancel clear the carets too
        }

        $action = \array_find(\array_keys(self::COLUMN_MOVES), static fn (string $action): bool => $kb->matches($keys, $action));
        if (null !== $action) {
            [$rowDelta, $colDelta] = self::COLUMN_MOVES[$action];
            [$anchorRow, $anchorCol, $caretRow, $caretCol] = $this->column->extend($rowDelta, $colDelta, $doc);
            $this->multiCaret->setColumnSelection($anchorRow, $anchorCol, $caretRow, $caretCol);
            $this->host->invalidate();
        }

        return null !== $action;
    }

    /** Undo/redo + structural selection, statement moves, surround, and conflict nav. */
    private function tryHistoryAndStructure(string $keys, Keybindings $kb): bool
    {
        $doc = $this->document;

        // History and structural selection (grow/shrink the syntactic range).
        if ($this->runFirstMatch($keys, $kb, self::HISTORY_STRUCTURE_ACTIONS)) {
            return true;
        }

        return self::perform(match (true) {
            // Move the current statement over blank-line gaps.
            $kb->matches($keys, 'move_statement_up') || $kb->matches($keys, 'move_statement_down') => fn () => $this->notifyIfChanged(
                static fn (): bool => $doc->moveStatement($kb->matches($keys, 'move_statement_up') ? -1 : 1),
            ),
            // Surround the selection with a chosen pair or snippet.
            $kb->matches($keys, 'surround_with') => fn () => $this->run('surround_with'),
            // Merge-conflict navigation and resolution.
            $kb->matches($keys, 'next_conflict') || $kb->matches($keys, 'prev_conflict') => fn () => $this->structural->gotoConflict($kb->matches($keys, 'next_conflict') ? 1 : -1),
            $kb->matches($keys, 'resolve_conflict') => $this->structural->openResolveConflictMenu(...),
            default => null,
        });
    }

    /** Caret movement (with shift-select variants), select-all, document ends and paging. */
    /**
     * One caret move, by VISUAL row when soft wrap is on.
     *
     * Wrapped, `cursor_down` should step to the next segment of the same line
     * before leaving it — otherwise Down jumps over everything the wrap put on
     * screen. Every other movement is unaffected, so they fall straight
     * through to the document.
     */
    private function moveVertically(string $action, string $method): void
    {
        $doc = $this->document;
        $width = $this->host->wrapWidth();
        if (null === $width || ('cursor_up' !== $action && 'cursor_down' !== $action)) {
            // @mago-expect analysis:string-member-selector -- $method comes from
            // MultiCaret::MOVE_METHODS, a const map of real Document methods.
            $doc->{$method}();

            return;
        }

        $line = $doc->cursorLine;
        $column = $doc->getCursorDisplayCol();
        $down = 'cursor_down' === $action;
        // Another segment below (above) on this same line?
        $target = $down ? $column + $width : $column - $width;
        if ($down ? $target <= $doc->lineDisplayWidth($line) : $target >= 0) {
            $doc->setCursor($line, $doc->byteColForDisplayCol($line, $target));

            return;
        }
        if ($down) {
            $doc->moveDown();
            $this->snapIntoFirstSegment($width);
        }
        if (!$down) {
            $doc->moveUp();
            $this->snapIntoLastSegment($width);
        }
    }

    /** Arriving from above: the caret belongs in the new line's FIRST segment. */
    private function snapIntoFirstSegment(int $width): void
    {
        $doc = $this->document;
        $line = $doc->cursorLine;
        $column = $doc->getCursorDisplayCol();
        if ($column >= $width) {
            $doc->setCursor($line, $doc->byteColForDisplayCol($line, $column % $width));
        }
    }

    /** Arriving from below: the caret belongs in the new line's LAST segment. */
    private function snapIntoLastSegment(int $width): void
    {
        $doc = $this->document;
        $line = $doc->cursorLine;
        $column = $doc->getCursorDisplayCol();
        $lastStart = \intdiv(\max(0, $doc->lineDisplayWidth($line) - 1), $width) * $width;
        if ($lastStart > 0) {
            $doc->setCursor($line, $doc->byteColForDisplayCol($line, $lastStart + ($column % $width)));
        }
    }

    private function tryMovement(string $keys, Keybindings $kb): bool
    {
        $doc = $this->document;

        // Movement: each action has a shift+key "select_" variant that
        // anchors/extends the selection instead of clearing it. The same
        // action→method map drives the multi-caret path.
        foreach (MultiCaret::MOVE_METHODS as $action => $method) {
            $plain = $kb->matches($keys, $action);
            if (!$plain && !$kb->matches($keys, \substr_replace($action, replace: 'select', offset: 0, length: 6))) {
                continue;
            }
            if ($plain && ('cursor_up' === $action || 'cursor_down' === $action)) {
                $this->host->setSignatureHint(null);
            }
            match ($plain) {
                true => $doc->clearSelection(),
                false => $doc->beginSelect(),
            };
            $this->moveVertically($action, $method);
            $this->host->invalidate();

            return true;
        }

        return self::perform(match (true) {
            $kb->matches($keys, 'select_all') => fn () => $this->run('select_all'),
            $kb->matches($keys, 'document_start') => fn () => $this->jump($doc->moveToDocumentStart(...)),
            $kb->matches($keys, 'document_end') => fn () => $this->jump($doc->moveToDocumentEnd(...)),
            $kb->matches($keys, 'page_up') || $kb->matches($keys, 'page_down') => function () use ($doc, $keys, $kb): void {
                $delta = $kb->matches($keys, 'page_up') ? -$this->host->contentRows() : $this->host->contentRows();
                $this->jump(static fn () => $doc->setCursor($doc->cursorLine + $delta, $doc->cursorCol));
            },
            default => null,
        });
    }

    /**
     * Move the caret by $move, dropping the selection first.
     *
     * @param \Closure(): void $move
     */
    private function jump(\Closure $move): void
    {
        $this->document->clearSelection();
        $move();
        $this->host->invalidate();
    }

    /** Clipboard/line/fold verbs, newline, and the indent/outdent family. */
    private function tryClipboardAndLineOps(string $keys, Keybindings $kb): bool
    {
        $doc = $this->document;

        // Clipboard, line operations and folds.
        if ($this->runFirstMatch($keys, $kb, self::CLIPBOARD_LINE_ACTIONS)) {
            return true;
        }

        $edit = match (true) {
            // Editing
            $kb->matches($keys, 'new_line') => $doc->insertNewLine(...),
            // Tab/Shift+Tab over a selection indent/outdent the whole block of lines;
            // reached only in the single-caret path (snippet/completion/multi-caret
            // Tab all returned earlier).
            $kb->matches($keys, 'indent') && $doc->hasSelection() => $doc->indentSelection(...),
            $kb->matches($keys, 'prev_tab_stop') => $doc->dedentSelection(...),
            $kb->matches($keys, 'indent') => static fn () => $doc->insert($doc->indentUnit()),
            default => null,
        };

        return self::perform(
            null === $edit
                ? null
                : function () use ($edit): void {
                    $edit();
                    $this->host->notifyChange();
                },
        );
    }

    /** Auto-pair backspace, line delete, the delete-char family, and cancel-selection. */
    private function tryEditing(string $keys, Keybindings $kb): bool
    {
        $doc = $this->document;

        return self::perform(match (true) {
            // Auto-pairs: backspacing between an empty pair removes both chars.
            $this->host->autoPairsEnabled() && $kb->matches($keys, 'delete_char_backward') && !$doc->hasSelection() && $this->autoPair->isBetweenEmptyPair($doc) => function () use ($doc): void {
                $doc->transact(static function () use ($doc): void {
                    $doc->deleteForward();
                    $doc->deleteBackward();
                });
                $this->host->notifyChange();
            },
            $kb->matches($keys, 'delete_line') => fn () => $this->run('delete_line'),
            $kb->matches($keys, 'delete_char_backward') => fn () => $this->notifyIfChanged($doc->deleteBackward(...)),
            $kb->matches($keys, 'delete_char_forward') => fn () => $this->notifyIfChanged($doc->deleteForward(...)),
            $kb->matches($keys, 'delete_word_backward') => fn () => $this->notifyIfChanged($doc->deleteWordBackward(...)),
            $kb->matches($keys, 'delete_to_line_end') => fn () => $this->notifyIfChanged($doc->deleteToLineEnd(...)),
            $kb->matches($keys, 'select_cancel') => function () use ($doc): void {
                if ($doc->hasSelection()) {
                    $doc->clearSelection();
                    $this->host->invalidate();
                }
            },
            default => null,
        });
    }

    /** Plain text insertion (with auto-pairing), the final fall-through. */
    private function tryTextInput(string $keys): bool
    {
        if (!$this->host->isInsertable($keys)) {
            return false;
        }

        $doc = $this->document;
        if (!$this->host->autoPairsEnabled() || !$this->autoPair->handle($doc, $keys)) {
            $doc->insert($keys);
        }
        $this->host->notifyChange();
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
            'undo' => $this->notifyIfChanged($this->document->undo(...)),
            'redo' => $this->notifyIfChanged($this->document->redo(...)),
            'copy' => $this->copySelection(),
            'cut' => $this->cutSelection(),
            'paste' => $this->pasteClipboard(),
            'paste_history' => $this->pasteFromHistory(),
            'duplicate_line' => $this->duplicateCurrentLines(),
            'delete_line' => $this->notifyIfChanged($this->document->deleteLines(...)),
            'move_line_up' => $this->notifyIfChanged(fn (): bool => $this->document->moveLines(-1)),
            'move_line_down' => $this->notifyIfChanged(fn (): bool => $this->document->moveLines(1)),
            'sort_lines' => $this->notifyIfChanged($this->document->sortLines(...)),
            'join_lines' => $this->notifyIfChanged($this->document->joinLines(...)),
            'toggle_case' => $this->notifyIfChanged($this->document->toggleCaseLines(...)),
            'insert_line_below' => $this->openLineBelow(),
            'insert_line_above' => $this->openLineAbove(),
            'complete_statement' => $this->completeCurrentStatement(),
            'toggle_comment' => $this->toggleLineComment(),
            'toggle_block_comment' => $this->toggleBlockComment(),
            'toggle_fold' => $this->toggleFold(),
            'fold_all' => $this->foldAll(),
            'unfold_all' => $this->unfoldAll(),
            'goto_matching_bracket' => $this->jumpToMatchingBracket(),
            'select_all' => $this->selectAllText(),
            'expand_selection' => $this->structural->expandSelection(),
            'shrink_selection' => $this->structural->shrinkSelection(),
            'surround_with' => $this->structural->openSurroundMenu(),
            'add_caret_above' => $this->multiCaret->addVertically(-1),
            'add_caret_below' => $this->multiCaret->addVertically(1),
            'add_next_occurrence' => $this->multiCaret->addNextOccurrence(),
            'trigger_completion' => $this->completion->requestExplicitly(),
            'parameter_info' => $this->host->fireSignatureHelp($this->document->cursorLine, $this->document->getCursorColUtf16()),
            'toggle_column_mode' => $this->toggleColumnMode(),
            'goto_line' => $this->promptGotoLine(),
            'rename_symbol' => $this->promptRename(),
            default => null,
        };
    }

    /**
     * Run a mutation and fire the document-change hook only when it reports a
     * real change.
     *
     * @param \Closure(): bool $mutation returns whether the document changed
     */
    private function notifyIfChanged(\Closure $mutation): void
    {
        if ($mutation()) {
            $this->host->notifyChange();
        }
    }

    private function pasteClipboard(): void
    {
        $this->pasteText($this->clipboard->latest());
    }

    /**
     * A paste the TERMINAL made — a bracketed paste of the system clipboard,
     * which is what Cmd+V is on a Mac and the terminal's own paste key
     * everywhere else. It lands where `Ctrl+V` would, one line per caret
     * included, and joins the clipboard ring, so `Ctrl+V` repeats it and the
     * paste history lists it.
     */
    public function pasteFromTerminal(string $text): void
    {
        $this->clipboard->push($text);
        $this->pasteText($text);
    }

    /**
     * PhpStorm's `Ctrl+Shift+V`: paste something you copied EARLIER.
     *
     * Newlines are shown as `⏎` rather than the entry being cut to its first
     * line, because what tells two copies apart is usually their shape — three
     * short lines against one long one — and a list of first lines throws
     * exactly that away. It is also what the picker's filter matches on, so
     * typing part of any line finds the entry.
     *
     * The pick is PROMOTED to the top, so the plain `Ctrl+V` after it repeats
     * what you just pasted rather than the thing you reached past.
     */
    private function pasteFromHistory(): void
    {
        $entries = $this->clipboard->entries();
        if ([] === $entries) {
            // First run has an empty ring, which is exactly when someone tries
            // the shortcut to see what it does. Silence there reads as a broken
            // binding rather than as an empty list.
            $this->flash?->flash($this->translator->trans('Nothing copied yet'));

            return;
        }

        $items = [];
        foreach ($entries as $index => $text) {
            $items[] = ['label' => \str_replace(search: "\n", replace: '⏎', subject: $text), 'hint' => '', 'value' => (string) $index];
        }

        $this->host->openListOverlay($this->translator->trans('Paste from History'), $items, function (string $index) use ($entries): void {
            // Cast for the same reason array_values() is there in the ring:
            // the overlay's value is a string and the list is keyed by int.
            $text = $entries[(int) $index] ?? '';
            $this->clipboard->push($text);
            $this->pasteText($text);
        });
    }

    /**
     * Insert pasted text where the carets are — all of them.
     *
     * The multi-caret branch is not an optimisation: `Ctrl+V` reaches
     * {@see MultiCaret::handleInput()} before this class's action table and
     * distributes a block one line per caret, while the HISTORY pick arrives
     * later, from an overlay callback, long after that interception could
     * happen. Without this the two keys pasted the same text in different
     * places, which was a real inconsistency and is the reason `pasteBlock()`
     * is public.
     */
    private function pasteText(string $text): void
    {
        if ('' === $text) {
            return;
        }
        if ($this->multiCaret->hasMultiple()) {
            $this->multiCaret->pasteBlock($text);
            $this->host->notifyChange();

            return;
        }
        $this->document->insert($text);
        $this->host->notifyChange();
    }

    private function openLineBelow(): void
    {
        $this->document->openLine(LinePlacement::Below);
        $this->host->notifyChange();
    }

    private function openLineAbove(): void
    {
        $this->document->openLine(LinePlacement::Above);
        $this->host->notifyChange();
    }

    private function duplicateCurrentLines(): void
    {
        $this->document->duplicateLines();
        $this->host->notifyChange();
    }

    private function completeCurrentStatement(): void
    {
        if (null !== ($syntax = $this->host->statementSyntax())) {
            $this->document->completeStatement($syntax);
            $this->host->notifyChange();
        }
    }

    private function toggleLineComment(): void
    {
        if (null !== ($syntax = $this->host->commentSyntax())) {
            $this->document->toggleComment($syntax);
            $this->host->notifyChange();
        }
    }

    private function toggleBlockComment(): void
    {
        if (null !== ($syntax = $this->host->commentSyntax())) {
            $this->document->toggleBlockComment($syntax);
            $this->host->notifyChange();
        }
    }

    private function toggleFold(): void
    {
        $this->foldModel->toggle();
        $this->host->invalidate();
    }

    private function foldAll(): void
    {
        $this->foldModel->foldAll();
        $this->host->invalidate();
    }

    private function unfoldAll(): void
    {
        $this->foldModel->unfoldAll();
        $this->host->invalidate();
    }

    /** Jump the caret to the bracket matching the one at (or just before) it. */
    private function jumpToMatchingBracket(): void
    {
        if (null === ($pair = EditorRenderer::matchingBrackets($this->document))) {
            return;
        }
        [$line, $displayCol] = $pair[1];
        $this->document->setCursor($line, $this->document->byteColForDisplayCol($line, $displayCol));
        $this->host->invalidate();
    }

    private function selectAllText(): void
    {
        $this->document->selectAll();
        $this->host->invalidate();
    }

    private function promptGotoLine(): void
    {
        $this->host->openPrompt('Go to line', '', function (string $value): void {
            // Accept "line" or "line:column" (both 1-based); jump 0-based, column 0 by default.
            $parts = \explode(':', \trim($value));
            $count = \count($parts);
            if ($count > 2 || !\ctype_digit($parts[0]) || (2 === $count && !\ctype_digit($parts[1]))) {
                return;
            }
            $column = 2 === $count ? \max(0, (int) $parts[1] - 1) : 0;
            $this->host->jumpTo((int) $parts[0] - 1, $column);
        });
    }

    private function promptRename(): void
    {
        $doc = $this->document;
        $this->host->requestRename($doc->cursorLine, $doc->getCursorColUtf16(), \ltrim($doc->getWordBeforeCursor(), characters: '$'));
    }

    private function maybeAutoTrigger(string $typed): void
    {
        $line = $this->document->getLine($this->document->cursorLine);
        $prefix = \substr($line, offset: 0, length: $this->document->cursorCol);

        if (\str_ends_with($prefix, '->') || \str_ends_with($prefix, '::')) {
            $this->completion->requestCompletion();
        }

        if ('(' === $typed || ',' === $typed) {
            $this->host->fireSignatureHelp($this->document->cursorLine, $this->document->getCursorColUtf16());

            return;
        }
        if (')' === $typed) {
            $this->host->setSignatureHint(null);
        }
    }

    private function copySelection(): void
    {
        if ($this->captureSelection()) {
            $this->host->invalidate();
        }
    }

    private function cutSelection(): void
    {
        if ($this->captureSelection()) {
            $this->document->deleteSelection();
            $this->host->notifyChange();
        }
    }

    /** Copy the current selection to the clipboard; false when nothing is selected. */
    private function captureSelection(): bool
    {
        $doc = $this->document;
        if (!$doc->hasSelection()) {
            return false;
        }
        $this->clipboard->push($doc->getSelectedText());
        $this->host->fireCopy($this->clipboard->latest());

        return true;
    }
}
