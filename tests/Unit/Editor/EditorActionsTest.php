<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\TestCase;
use Porta\Config\ActionCatalog;
use Porta\Editor\AutoPairService;
use Porta\Editor\Caret;
use Porta\Editor\ClipboardRing;
use Porta\Editor\Document;
use Porta\Editor\EditorActions;
use Porta\Editor\EditorCompletion;
use Porta\Editor\EditorStructural;
use Porta\Editor\FoldModel;
use Porta\Editor\MultiCaret;
use Porta\Editor\SelectionMode;
use Porta\Editor\StatementSyntax;
use Porta\I18n\Translator;
use Porta\Input\TextInput;
use Porta\Tests\Support\FakeEditorHost;
use Symfony\Component\Tui\Input\Keybindings;

/**
 * Characterization tests for the editing-verb dispatcher, pinning every
 * run() verb and handleKey()'s load-bearing match order before the dispatch
 * is made table-driven.
 */
final class EditorActionsTest extends TestCase
{
    private Document $doc;
    private MultiCaret $multiCaret;
    private FoldModel $foldModel;

    /** @var array<string, list<mixed>> closure invocations by name */
    private array $calls = [];

    private function record(string $name): \Closure
    {
        return function (...$args) use ($name): void {
            $this->calls[$name][] = $args;
        };
    }

    private function actions(string $text = "alpha beta\ngamma\n", string $clipboard = '', string $locale = 'en'): EditorActions
    {
        $this->calls = [];
        $this->doc = new Document($text);
        $invalidate = $this->record('invalidate');
        $notifyChange = $this->record('notifyChange');
        $this->multiCaret = new MultiCaret($this->doc, $invalidate, $notifyChange);
        $autoPair = new AutoPairService();
        $completion = new EditorCompletion(
            $this->doc,
            $invalidate,
            $notifyChange,
            TextInput::isPrintable(...),
            $this->record('requestCompletion'),
            $this->record('resolveCompletion'),
            $this->record('resolveCompletionDetail'),
        );
        $structural = new EditorStructural($this->doc, $autoPair, $invalidate, $notifyChange, $this->record('openListOverlay'));
        $this->foldModel = new FoldModel($this->doc);

        $host = new FakeEditorHost(function (string $method, array $args): void {
            $this->calls[$method][] = $args;
        }, statement: StatementSyntax::forGrammar('php'));

        $ring = new ClipboardRing();
        $ring->push($clipboard);

        return new EditorActions($this->doc, $this->multiCaret, $completion, $structural, $this->foldModel, $autoPair, $host, $ring, new Translator($locale));
    }

    private function count_(string $name): int
    {
        return \count($this->calls[$name] ?? []);
    }

    // --- run(): every verb ----------------------------------------------------

    /**
     * The two open-line verbs tell the host the buffer changed.
     *
     * Asserted here rather than beside the edit itself, because a test that
     * reads the document back cannot see this at all: without the call the
     * lines are still right, the screen never repaints and the language server
     * is never told — a stale frame and stale diagnostics, from a verb whose
     * own output is perfect. Two mutants lived on exactly that.
     */
    /**
     * What the clipboard-history picker OFFERS — asserted here because the
     * list overlay is composited above the editor widget rather than by it, so
     * a render of the widget alone shows a bare buffer however open the picker
     * is.
     *
     * The multi-line entry keeps its newlines as `⏎` rather than being cut to
     * its first line: what tells two copies apart is usually three short lines
     * against one long one, and a list of first lines throws that away. It is
     * also what the picker's filter matches on.
     */
    public function testThePasteHistoryPickerShowsEveryCopyWithItsShape(): void
    {
        $actions = $this->actions("alpha beta\ngamma\n");
        $ring = $actions->clipboardRing();
        $ring->push('one');
        $ring->push("two\nthree");

        $actions->run('paste_history');

        $this->assertSame(1, $this->count_('openListOverlay'));
        [$title, $items] = $this->calls['openListOverlay'][0];
        $this->assertSame('Paste from History', $title);
        $this->assertSame(
            [
                ['label' => 'two⏎three', 'hint' => '', 'value' => '0'],
                ['label' => 'one', 'hint' => '', 'value' => '1'],
            ],
            $items,
            'newest first, newlines shown, index as the value',
        );
    }

    /**
     * A modal TITLE is the one string this class shows the user, and it was
     * handed to the overlay as a bare English literal. Nothing could catch
     * that, because the catalogs DO carry "Paste from History" — as the
     * ACTION's palette label, translated somewhere else entirely. A gate
     * satisfied by a different consumer of the same string is no gate; every
     * other overlay in `src/` translates its title at the call site.
     */
    public function testThePasteHistoryPickerTitleIsTranslated(): void
    {
        $actions = $this->actions("alpha\n", locale: 'fr');
        $actions->clipboardRing()->push('one');

        $actions->run('paste_history');

        [$title] = $this->calls['openListOverlay'][0];
        $this->assertSame('Coller depuis l’historique', $title);
    }

    /** Nothing copied yet: the verb opens nothing rather than an empty box. */
    public function testThePasteHistoryPickerIsNotOfferedWhenNothingWasCopied(): void
    {
        $this->actions()->run('paste_history');

        $this->assertSame(0, $this->count_('openListOverlay'));
    }

    public function testTheOpenLineVerbsNotifyTheHost(): void
    {
        $actions = $this->actions("  a\n");

        $actions->run('insert_line_below');
        $this->assertSame(['  a', '  ', ''], $this->doc->lines);
        $this->assertSame(1, $this->count_('notifyChange'));

        $actions->run('insert_line_above');
        $this->assertSame(2, $this->count_('notifyChange'));
    }

    /**
     * Complete-statement too, and for the same reason: the lines come out
     * right whether or not the host is told, so only a counting seam can see
     * the missing repaint and the missing `didChange`.
     */
    public function testCompleteStatementNotifiesTheHost(): void
    {
        $actions = $this->actions("return 1\n");

        $actions->run('complete_statement');

        $this->assertSame('return 1;', $this->doc->getLine(0));
        $this->assertSame(1, $this->count_('notifyChange'));
    }

    public function testRunUndoRedoRoundTrip(): void
    {
        $actions = $this->actions('start');
        $this->doc->insert('x');
        $this->assertSame('xstart', $this->doc->getText());

        $actions->run('undo');
        $this->assertSame('start', $this->doc->getText());
        $this->assertSame(1, $this->count_('notifyChange'));

        $actions->run('redo');
        $this->assertSame('xstart', $this->doc->getText());
        $this->assertSame(2, $this->count_('notifyChange'));

        // Nothing left to undo/redo: no change notification.
        $actions->run('redo');
        $this->assertSame(2, $this->count_('notifyChange'));
    }

    public function testRunCopyKeepsTheSelectionAndFiresTheOsc52Hook(): void
    {
        $actions = $this->actions();
        $this->doc->setSelectionRange(0, 0, 0, 5);
        $actions->run('copy');

        $this->assertSame([['alpha']], $this->calls['fireCopy']);
        $this->assertSame('alpha beta', $this->doc->getLine(0), 'copy leaves the text');
        $this->assertSame(1, $this->count_('invalidate'));
        $this->assertSame(0, $this->count_('notifyChange'));
    }

    public function testRunCutDeletesTheSelection(): void
    {
        $actions = $this->actions();
        $this->doc->setSelectionRange(0, 0, 0, 6);
        $actions->run('cut');

        $this->assertSame([['alpha ']], $this->calls['fireCopy']);
        $this->assertSame('beta', $this->doc->getLine(0));
        $this->assertSame(1, $this->count_('notifyChange'));
    }

    public function testRunCopyWithoutSelectionIsANoOp(): void
    {
        $actions = $this->actions();
        $actions->run('copy');
        $this->assertArrayNotHasKey('fireCopy', $this->calls);
        $this->assertSame(0, $this->count_('invalidate'), 'nothing was captured, so nothing repaints');
    }

    public function testRunPasteInsertsTheClipboard(): void
    {
        $actions = $this->actions(clipboard: 'PASTED');
        $actions->run('paste');
        $this->assertStringStartsWith('PASTED', $this->doc->getText());
        $this->assertSame(1, $this->count_('notifyChange'));
    }

    public function testRunPasteWithEmptyClipboardIsANoOp(): void
    {
        $actions = $this->actions();
        $actions->run('paste');
        $this->assertSame(0, $this->count_('notifyChange'));
    }

    public function testRunDuplicateAndDeleteLine(): void
    {
        $actions = $this->actions("one\ntwo\n");
        $actions->run('duplicate_line');
        $this->assertSame("one\none\ntwo\n", $this->doc->getText());

        $actions->run('delete_line');
        $this->assertSame("one\ntwo\n", $this->doc->getText());
        $this->assertSame(2, $this->count_('notifyChange'));
    }

    public function testRunMoveLineUpAndDown(): void
    {
        $actions = $this->actions("one\ntwo\n");
        $actions->run('move_line_down');
        $this->assertSame("two\none\n", $this->doc->getText());

        $actions->run('move_line_up');
        $this->assertSame("one\ntwo\n", $this->doc->getText());

        // Already at the top: no move, no notification.
        $notifications = $this->count_('notifyChange');
        $actions->run('move_line_up');
        $this->assertSame($notifications, $this->count_('notifyChange'));
    }

    public function testRunSortJoinAndToggleCaseLines(): void
    {
        $actions = $this->actions("banana\napple\ncherry\n");
        $this->doc->setCursor(0, 0);
        $this->doc->beginSelect();
        $this->doc->setCursor(2, 6, selection: SelectionMode::Keep);
        $actions->run('sort_lines');
        $this->assertSame("apple\nbanana\ncherry\n", $this->doc->getText());

        $join = $this->actions("foo\nbar\n");
        $join->run('join_lines');
        $this->assertSame("foo bar\n", $this->doc->getText());

        $case = $this->actions("Hi\n");
        $case->run('toggle_case');
        $this->assertSame("HI\n", $this->doc->getText());

        // A no-op verb fires no change notification.
        $none = $this->actions('solo');
        $before = $this->count_('notifyChange');
        $none->run('sort_lines');
        $this->assertSame($before, $this->count_('notifyChange'), 'a single-line sort changes nothing');
    }

    public function testRunToggleComment(): void
    {
        $actions = $this->actions("code\n");
        $actions->run('toggle_comment');
        $this->assertSame("// code\n", $this->doc->getText());
        $this->assertSame(1, $this->count_('notifyChange'));
        $actions->run('toggle_comment');
        $this->assertSame("code\n", $this->doc->getText());
        $this->assertSame(2, $this->count_('notifyChange'));
    }

    public function testRunToggleFoldInvalidates(): void
    {
        $actions = $this->actions("if {\n  a\n}\n");
        $this->doc->setCursor(1, 0); // unambiguously inside the block, not on a boundary line
        $actions->run('toggle_fold');
        $this->assertSame(1, $this->count_('invalidate'));
        $this->assertNotNull($this->foldModel->foldEndAt(0), 'the fold model actually toggled a fold');
    }

    public function testRunSelectAll(): void
    {
        $actions = $this->actions("one\ntwo\n");
        $actions->run('select_all');
        $this->assertSame("one\ntwo\n", $this->doc->getSelectedText());
        $this->assertSame(1, $this->count_('invalidate'));
    }

    public function testRunExpandAndShrinkSelection(): void
    {
        $actions = $this->actions("(alpha beta)\n");
        $this->doc->setCursor(0, 2);
        $actions->run('expand_selection');
        $this->assertTrue($this->doc->hasSelection(), 'expanded to a syntactic range');
        $actions->run('shrink_selection');
        $this->assertFalse($this->doc->hasSelection(), 'shrunk back to the start');
    }

    public function testRunSurroundWithOpensTheListOverlay(): void
    {
        $actions = $this->actions();
        $this->doc->setSelectionRange(0, 0, 0, 5);
        $actions->run('surround_with');
        $this->assertSame(1, $this->count_('openListOverlay'));
    }

    public function testRunAddCaretAboveAndBelow(): void
    {
        $actions = $this->actions("one\ntwo\nthree\n");
        $this->doc->setCursor(1, 0);
        $actions->run('add_caret_below');
        $this->assertTrue($this->multiCaret->hasMultiple());
        $this->assertSame(2, $this->doc->cursorLine, 'the new caret is exactly one line below');

        $actions = $this->actions("one\ntwo\nthree\n");
        $this->doc->setCursor(1, 0);
        $actions->run('add_caret_above');
        $this->assertTrue($this->multiCaret->hasMultiple());
    }

    public function testRunAddNextOccurrence(): void
    {
        $actions = $this->actions("foo bar\nfoo baz\n");
        $actions->run('add_next_occurrence');
        $actions->run('add_next_occurrence');
        $this->assertTrue($this->multiCaret->hasMultiple());
    }

    public function testRunTriggerCompletion(): void
    {
        $actions = $this->actions();
        $actions->run('trigger_completion');
        $this->assertSame(1, $this->count_('requestCompletion'));
    }

    public function testRunParameterInfoAsksForTheSignatureAtTheCaret(): void
    {
        // The invoke verb: signature help was auto-only, so once Escape closed
        // the popup there was no way to bring it back mid-argument-list.
        $actions = $this->actions();
        $this->doc->setCursor(0, 3);

        $actions->run('parameter_info');

        $this->assertSame([[0, 3]], $this->calls['fireSignatureHelp']);
    }

    public function testRunGotoLinePromptJumpsOnDigits(): void
    {
        $actions = $this->actions();
        $actions->run('goto_line');

        $this->assertSame(1, $this->count_('openPrompt'));
        [$label, $initial, $onSubmit] = $this->calls['openPrompt'][0];
        $this->assertSame('Go to line', $label);
        $this->assertSame('', $initial);

        $onSubmit('3');
        $this->assertSame([[2, 0]], $this->calls['jumpTo'], '1-based input, 0-based jump');

        $onSubmit('12:5');
        $this->assertSame([2, 0], $this->calls['jumpTo'][0]);
        $this->assertSame([11, 4], $this->calls['jumpTo'][1], 'line:column is 1-based, jumps 0-based');

        $onSubmit('nope');
        $onSubmit('7:'); // a trailing colon with no column is not a valid pair
        $this->assertCount(2, $this->calls['jumpTo'], 'only well-formed line / line:col inputs jump');
    }

    public function testRunRenameSymbolPassesTheBareWord(): void
    {
        $actions = $this->actions("\$variable\n");
        $this->doc->setCursor(0, 9);
        $actions->run('rename_symbol');

        $this->assertSame([[0, 9, 'variable']], $this->calls['requestRename'], 'leading $ stripped');
    }

    public function testRunUnknownActionIsASafeNoOp(): void
    {
        $actions = $this->actions();
        $actions->run('not_a_verb');
        $this->assertSame([], $this->calls);
    }

    public function testEveryTableDispatchedActionIsInTheCatalog(): void
    {
        foreach (EditorActions::TABLE_ACTIONS as $action) {
            $this->assertArrayHasKey($action, ActionCatalog::ACTIONS, "table action '{$action}' is not a catalogued action");
        }
    }

    // --- handleKey(): load-bearing order --------------------------------------

    /** Primary selection over line 0's 'foo', one extra caret selecting line 1's. */
    private function selectBothFoos(): void
    {
        $this->doc->setSelectionRange(0, 0, 0, 3);
        $this->multiCaret->setExtras([new Caret(1, 3, 1, 0)]);
        $this->assertTrue($this->multiCaret->hasMultiple());
    }

    public function testBlockCopyInterceptsBeforeMultiCaretCollapses(): void
    {
        $actions = $this->actions("foo x\nfoo y\n");
        $this->selectBothFoos();

        $kb = new Keybindings(['copy' => ['ctrl+c']]);
        $actions->handleKey("\x03", $kb);

        $this->assertSame("foo\nfoo", $actions->clipboardRing()->latest(), 'one clipboard line per caret');
        $this->assertSame([["foo\nfoo"]], $this->calls['fireCopy']);
        $this->assertTrue($this->multiCaret->hasMultiple(), 'copy keeps the carets');
        // selectBothFoos()'s setExtras() already fired one invalidate(); the
        // copy branch must fire exactly one more.
        $this->assertSame(2, $this->count_('invalidate'));
    }

    public function testBlockCutDeletesEveryCaretSelection(): void
    {
        $actions = $this->actions("foo x\nfoo y\n");
        $this->selectBothFoos();

        $kb = new Keybindings(['cut' => ['ctrl+x']]);
        $actions->handleKey("\x18", $kb);

        $this->assertSame("foo\nfoo", $actions->clipboardRing()->latest());
        $this->assertSame(" x\n y\n", $this->doc->getText());
    }

    public function testMovementClearsTheSelectionAndItsSelectVariantExtendsIt(): void
    {
        $kb = new Keybindings(['cursor_right' => ['right'], 'select_right' => ['shift+right']]);

        $actions = $this->actions("ab\n");
        $actions->handleKey("\x1b[C", $kb);
        $this->assertSame(1, $this->doc->cursorCol);
        $this->assertFalse($this->doc->hasSelection());

        $actions->handleKey("\x1b[1;2C", $kb);
        $this->assertSame('b', $this->doc->getSelectedText());
    }

    public function testCursorVerticalMovementClearsTheSignatureHint(): void
    {
        $kb = new Keybindings(['cursor_down' => ['down']]);
        $actions = $this->actions("a\nb\n");
        $actions->handleKey("\x1b[B", $kb);
        $this->assertSame([[null, null]], $this->calls['setSignatureHint']);
    }

    public function testPageMovementUsesTheViewportRows(): void
    {
        $kb = new Keybindings(['page_down' => ['page_down']]);
        $actions = $this->actions(\str_repeat("line\n", times: 20));
        $actions->handleKey("\x1b[6~", $kb);
        $this->assertSame(5, $this->doc->cursorLine, 'contentRows closure supplies the page size');
    }

    public function testIndentWithSelectionIndentsTheBlockOtherwiseInsertsTheUnit(): void
    {
        $kb = new Keybindings(['indent' => ['tab']]);

        $actions = $this->actions("one\ntwo\n");
        $this->doc->setSelectionRange(0, 0, 1, 3);
        $actions->handleKey("\t", $kb);
        $this->assertSame("    one\n    two\n", $this->doc->getText());

        $actions = $this->actions("one\n");
        $actions->handleKey("\t", $kb);
        $this->assertSame("    one\n", $this->doc->getText());
    }

    public function testAutoPairBackspaceRemovesBothCharsOfAnEmptyPair(): void
    {
        $kb = new Keybindings(['delete_char_backward' => ['backspace']]);
        $actions = $this->actions("()\n");
        $this->doc->setCursor(0, 1);
        $actions->handleKey("\x7f", $kb);
        $this->assertSame("\n", $this->doc->getText());
        $this->assertSame(1, $this->count_('notifyChange'));
    }

    public function testSelectCancelOnlyClearsAnActiveSelection(): void
    {
        $kb = new Keybindings(['select_cancel' => ['escape']]);

        $actions = $this->actions("ab\n");
        $this->doc->setSelectionRange(0, 0, 0, 2);
        $actions->handleKey("\x1b", $kb);
        $this->assertFalse($this->doc->hasSelection());
        $this->assertSame(1, $this->count_('invalidate'));

        $actions->handleKey("\x1b", $kb);
        $this->assertSame(1, $this->count_('invalidate'), 'no repaint without a selection');
    }

    public function testTypedTextInsertsAndAutoTriggersCompletionAfterArrow(): void
    {
        $kb = new Keybindings([]);
        $actions = $this->actions("\n");

        $actions->handleKey('a', $kb);
        $this->assertStringStartsWith('a', $this->doc->getText());
        $this->assertArrayNotHasKey('requestCompletion', $this->calls);
        $this->assertSame(1, $this->count_('notifyChange'));

        $actions->handleKey('-', $kb);
        $actions->handleKey('>', $kb);
        $this->assertSame(1, $this->count_('requestCompletion'), '-> auto-triggers completion');
    }

    public function testTypedParensDriveTheSignatureHelpLifecycle(): void
    {
        $kb = new Keybindings([]);
        $actions = $this->actions("\n");

        $actions->handleKey('(', $kb);
        $this->assertSame(1, $this->count_('fireSignatureHelp'));
        $this->assertSame("()\n", $this->doc->getText(), 'the auto-pair branch ran, not a plain insert');
        $this->assertSame(1, $this->count_('notifyChange'));
        $actions->handleKey(')', $kb);
        $this->assertSame([[null, null]], $this->calls['setSignatureHint']);
    }

    public function testNonInsertableUnboundInputFallsThrough(): void
    {
        $kb = new Keybindings([]);
        $actions = $this->actions("keep\n");
        $actions->handleKey("\x1b[Z", $kb); // unbound escape sequence
        $this->assertSame("keep\n", $this->doc->getText());
        $this->assertSame([], $this->calls);
    }

    public function testMoveStatementNotifiesOnlyWhenSomethingMoved(): void
    {
        $kb = new Keybindings(['move_statement_down' => ['ctrl+shift+down']]);
        $actions = $this->actions("one\n\ntwo\n");
        $actions->handleKey("\x1b[1;6B", $kb);
        $this->assertSame("\none\ntwo\n", $this->doc->getText());
        $this->assertSame(1, $this->count_('notifyChange'));
    }

    public function testRunFoldAllAndUnfoldAll(): void
    {
        $actions = $this->actions("class A {\n    fn() {\n        x;\n    }\n}\n");
        $this->doc->setCursor(2, 0); // inside the inner block

        $actions->run('fold_all');
        $this->assertSame(4, $this->foldModel->foldEndAt(0), 'the outer block folded');
        $this->assertSame(3, $this->foldModel->foldEndAt(1), 'the inner block folded');
        $this->assertSame(0, $this->doc->cursorLine, 'the caret snapped to a visible fold start');
        $this->assertSame(1, $this->count_('invalidate'));

        $actions->run('unfold_all');
        $this->assertNull($this->foldModel->foldEndAt(0));
        $this->assertNull($this->foldModel->foldEndAt(1));
        $this->assertSame(2, $this->count_('invalidate'));
    }

    public function testExternalFoldRegionsWinUntilTheDocumentChanges(): void
    {
        $actions = $this->actions("a\nb\nc\nd {\n}\n");
        $this->foldModel->setRegions([[0, 2]], $this->doc->version);

        $actions->run('fold_all');
        $this->assertSame(2, $this->foldModel->foldEndAt(0), 'the LSP region folded');
        $this->assertNull($this->foldModel->foldEndAt(3), 'the brace region was superseded');

        $actions->run('unfold_all');
        $this->doc->insert('x'); // version moves on → regions stale
        $actions->run('fold_all');
        $this->assertNull($this->foldModel->foldEndAt(0), 'stale LSP regions were dropped');
    }

    public function testRunGotoMatchingBracket(): void
    {
        $actions = $this->actions("fn(a, [b])\nend\n");
        $this->doc->setCursor(0, 2); // on the opening paren

        $actions->run('goto_matching_bracket');
        $this->assertSame([0, 9], [$this->doc->cursorLine, $this->doc->cursorCol], 'jumped to the closing paren');
        $this->assertSame(1, $this->count_('invalidate'));

        $actions->run('goto_matching_bracket');
        $this->assertSame([0, 2], [$this->doc->cursorLine, $this->doc->cursorCol], 'jumped back to the opener');

        $this->doc->setCursor(1, 0); // no bracket anywhere near
        $actions->run('goto_matching_bracket');
        $this->assertSame([1, 0], [$this->doc->cursorLine, $this->doc->cursorCol], 'no bracket → no move');
        $this->assertSame(2, $this->count_('invalidate'), 'no invalidate on the no-op');
    }

    // --- Mutation-gap coverage --------------------------------------------

    public function testRunFirstMatchChecksEveryActionInTheList(): void
    {
        // 'toggle_fold' is the LAST action in CLIPBOARD_LINE_ACTIONS: a loop
        // that quits on the first non-match (instead of continuing) would
        // never reach it.
        $kb = new Keybindings(['toggle_fold' => ['q']]);
        $actions = $this->actions("if {\n  a\n}\n");
        $this->doc->setCursor(1, 0); // unambiguously inside the block, not on a boundary line
        $actions->handleKey('q', $kb);

        $this->assertNotNull($this->foldModel->foldEndAt(0), 'toggle_fold matched despite being last in the list');
        $this->assertSame("if {\n  a\n}\n", $this->doc->getText(), 'no fallthrough insert of the bound letter');
    }

    public function testBlockCopyCutRequiresAMatchingVerbEvenWithMultipleCarets(): void
    {
        $actions = $this->actions("foo x\nfoo y\n");
        $this->selectBothFoos();

        $kb = new Keybindings([]); // neither copy nor cut bound
        $actions->handleKey('q', $kb);

        $this->assertArrayNotHasKey('fireCopy', $this->calls, 'multiple carets alone must not trigger the block copy/cut path');
    }

    public function testCopyIsANoOpWithoutASelectionOrMultipleCarets(): void
    {
        $kb = new Keybindings(['copy' => ['q']]);
        $actions = $this->actions("alpha\n");
        $actions->handleKey('q', $kb);

        $this->assertArrayNotHasKey('fireCopy', $this->calls, 'a matching verb alone must not trigger the block copy/cut path');
    }

    public function testBlockCutReturnsImmediatelyWithoutFallingThrough(): void
    {
        $actions = $this->actions("foo x\nfoo y\n");
        $this->selectBothFoos();

        // 'undo' shares the key with 'cut': if the block-cut branch fell
        // through (or reported "not consumed") the cascade would reach undo
        // next and revert the cut.
        $kb = new Keybindings(['cut' => ['ctrl+x'], 'undo' => ['ctrl+x']]);
        // selectBothFoos()'s setExtras() already fired one invalidate().
        $before = $this->count_('invalidate');
        $actions->handleKey("\x18", $kb);

        $this->assertSame(" x\n y\n", $this->doc->getText(), 'cut applied; a broken return would fall through to undo it');
        $this->assertSame($before, $this->count_('invalidate'), 'cut returns before the invalidate() call');
    }

    public function testMultiCaretConsumedMovementStopsTheCascade(): void
    {
        $actions = $this->actions("xy\nxy\n");
        $this->multiCaret->setExtras([Caret::point(1, 0)]);

        $kb = new Keybindings(['cursor_right' => ['q']]);
        $actions->handleKey('q', $kb);

        $this->assertSame(1, $this->doc->cursorCol, 'the multi-caret handler alone must move the caret exactly once');
    }

    public function testMoveStatementUpMovesTheStatementUpward(): void
    {
        $kb = new Keybindings(['move_statement_up' => ['ctrl+shift+up']]);
        $actions = $this->actions("one\n\ntwo\n");
        $this->doc->setCursor(2, 0);
        $actions->handleKey("\x1b[1;6A", $kb);

        $this->assertSame("one\ntwo\n\n", $this->doc->getText(), 'the statement moved up over the blank-line gap');
        $this->assertSame(1, $this->count_('notifyChange'));
    }

    public function testMoveStatementConsumedKeyStopsTheCascade(): void
    {
        $kb = new Keybindings(['move_statement_down' => ['q']]);
        $actions = $this->actions("one\n\ntwo\n");
        $actions->handleKey('q', $kb);

        $this->assertSame("\none\ntwo\n", $this->doc->getText(), 'statement moved once, no fallthrough insert of the bound letter');
        $this->assertSame(1, $this->count_('notifyChange'));
    }

    public function testHistoryAndStructureConsumedKeysDoNotFallThroughToInsertion(): void
    {
        $kb = new Keybindings(['expand_selection' => ['q']]);
        $actions = $this->actions("(alpha)\n");
        $this->doc->setCursor(0, 2);
        $actions->handleKey('q', $kb);

        $this->assertTrue($this->doc->hasSelection(), 'expand_selection ran');
        $this->assertSame("(alpha)\n", $this->doc->getText(), 'no fallthrough insert of the bound letter');
    }

    public function testConflictNavigationRespondsToEitherDirectionAlone(): void
    {
        $text = "a\n<<<<<<< ours\nx\n=======\ny\n>>>>>>> theirs\nb\n";
        // Only 'next_conflict' is bound: an '&&' in place of the real '||'
        // would require 'prev_conflict' to match too, and never fire.
        $kb = new Keybindings(['next_conflict' => ['q']]);
        $actions = $this->actions($text);
        $actions->handleKey('q', $kb);

        $this->assertSame(1, $this->doc->cursorLine, 'jumped to the conflict start');
        $this->assertSame($text, $this->doc->getText(), 'no fallthrough insert of the bound letter');
    }

    public function testCursorHorizontalMovementDoesNotClearTheSignatureHint(): void
    {
        $kb = new Keybindings(['cursor_right' => ['right']]);
        $actions = $this->actions("ab\n");
        $actions->handleKey("\x1b[C", $kb);

        $this->assertArrayNotHasKey('setSignatureHint', $this->calls, 'only vertical movement clears the signature hint');
    }

    public function testMovementClearsSelectionInvalidatesAndStopsTheCascade(): void
    {
        $kb = new Keybindings(['cursor_right' => ['q']]);
        $actions = $this->actions("abcdef\n");
        $this->doc->setSelectionRange(0, 0, 0, 3);
        $actions->handleKey('q', $kb);

        $this->assertSame(4, $this->doc->cursorCol, 'moved right exactly once from the selection end');
        $this->assertFalse($this->doc->hasSelection(), 'the pre-existing selection was cleared');
        $this->assertSame(1, $this->count_('invalidate'));
        $this->assertSame("abcdef\n", $this->doc->getText(), 'no fallthrough insert of the bound letter');
    }

    public function testSelectVariantInvalidatesAndStopsTheCascade(): void
    {
        $kb = new Keybindings(['select_right' => ['q']]);
        $actions = $this->actions("abcdef\n");
        $actions->handleKey('q', $kb);

        $this->assertSame('a', $this->doc->getSelectedText());
        $this->assertSame(1, $this->count_('invalidate'));
        $this->assertSame("abcdef\n", $this->doc->getText(), 'no fallthrough insert of the bound letter');
    }

    public function testPageMovementClearsSelectionInvalidatesAndStopsTheCascade(): void
    {
        $kb = new Keybindings(['page_down' => ['q']]);
        $actions = $this->actions(\str_repeat("line\n", times: 20));
        $this->doc->setSelectionRange(0, 0, 0, 2);
        $actions->handleKey('q', $kb);

        $this->assertSame(5, $this->doc->cursorLine);
        $this->assertFalse($this->doc->hasSelection());
        $this->assertSame(1, $this->count_('invalidate'));
        $this->assertSame('line', $this->doc->getLine($this->doc->cursorLine), 'no fallthrough insert of the bound letter');
    }

    public function testIndentWithSelectionNotifiesAndStopsTheCascade(): void
    {
        $kb = new Keybindings(['indent' => ['q']]);
        $actions = $this->actions("one\ntwo\n");
        $this->doc->setSelectionRange(0, 0, 1, 3);
        $actions->handleKey('q', $kb);

        $this->assertSame("    one\n    two\n", $this->doc->getText(), 'indented once, no fallthrough insert of the bound letter');
        $this->assertSame(1, $this->count_('notifyChange'));
    }

    public function testPlainIndentNotifiesAndStopsTheCascade(): void
    {
        $kb = new Keybindings(['indent' => ['q']]);
        $actions = $this->actions("one\n");
        $actions->handleKey('q', $kb);

        $this->assertSame("    one\n", $this->doc->getText(), 'plain indent, no fallthrough insert of the bound letter');
        $this->assertSame(1, $this->count_('notifyChange'));
    }

    public function testAutoPairBackspaceCollapsesOnlyTheEmptyPair(): void
    {
        $kb = new Keybindings(['delete_char_backward' => ['q']]);
        $actions = $this->actions("y()\n");
        $this->doc->setCursor(0, 2); // between the empty pair
        $actions->handleKey('q', $kb);

        $this->assertSame("y\n", $this->doc->getText(), 'only the empty pair collapses, no extra backward delete');
        $this->assertSame(1, $this->count_('notifyChange'));
    }

    public function testDeleteCharBackwardRemovesThePrecedingChar(): void
    {
        $kb = new Keybindings(['delete_char_backward' => ['ctrl+b']]);
        $actions = $this->actions("ab\n");
        $this->doc->setCursor(0, 2);
        $actions->handleKey("\x02", $kb);

        $this->assertSame("a\n", $this->doc->getText());
        $this->assertSame(1, $this->count_('notifyChange'), 'a real edit notifies');
    }

    public function testDeleteCharForwardRemovesTheFollowingChar(): void
    {
        $kb = new Keybindings(['delete_char_forward' => ['ctrl+d']]);
        $actions = $this->actions("ab\n");
        $this->doc->setCursor(0, 0);
        $actions->handleKey("\x04", $kb);

        $this->assertSame("b\n", $this->doc->getText());
    }

    public function testDeleteWordBackwardRemovesThePrecedingWord(): void
    {
        $kb = new Keybindings(['delete_word_backward' => ['ctrl+w']]);
        $actions = $this->actions("foo bar\n");
        $this->doc->setCursor(0, 7);
        $actions->handleKey("\x17", $kb);

        $this->assertSame("foo \n", $this->doc->getText());
    }

    public function testDeleteToLineEndRemovesTheRestOfTheLine(): void
    {
        $kb = new Keybindings(['delete_to_line_end' => ['ctrl+k']]);
        $actions = $this->actions("foo bar\n");
        $this->doc->setCursor(0, 3);
        $actions->handleKey("\x0b", $kb);

        $this->assertSame("foo\n", $this->doc->getText());
    }

    public function testEditingConsumedKeysDoNotFallThroughToInsertion(): void
    {
        $kb = new Keybindings(['select_cancel' => ['q']]);
        $actions = $this->actions("ab\n");
        $this->doc->setSelectionRange(0, 0, 0, 2);
        $actions->handleKey('q', $kb);

        $this->assertFalse($this->doc->hasSelection());
        $this->assertSame("ab\n", $this->doc->getText(), 'no fallthrough insert of the bound letter');
    }

    public function testRunGotoLinePromptTrimsSurroundingWhitespace(): void
    {
        $actions = $this->actions();
        $actions->run('goto_line');
        [, , $onSubmit] = $this->calls['openPrompt'][0];

        $onSubmit(' 5 ');
        $this->assertSame([[4, 0]], $this->calls['jumpTo'], 'surrounding whitespace is trimmed before parsing');
    }

    public function testRunGotoLinePromptClampsNegativeColumnToZero(): void
    {
        $actions = $this->actions();
        $actions->run('goto_line');
        [, , $onSubmit] = $this->calls['openPrompt'][0];

        $onSubmit('5:0'); // column 0 (1-based) - 1 would be negative
        $this->assertSame([[4, 0]], $this->calls['jumpTo'], 'the column clamps to 0, never negative');
    }

    public function testRunGotoLinePromptCastsBeforeArithmeticOnOverflow(): void
    {
        $actions = $this->actions();
        $actions->run('goto_line');
        [, , $onSubmit] = $this->calls['openPrompt'][0];

        // Bigger than PHP_INT_MAX: without the (int) cast, arithmetic on the
        // numeric string promotes to float, and jumpTo()'s strict int params
        // would throw a TypeError.
        $onSubmit('99999999999999999999:99999999999999999999');
        $this->assertSame([[9_223_372_036_854_775_806, 9_223_372_036_854_775_806]], $this->calls['jumpTo']);
    }

    public function testAutoTriggerIgnoresTextAfterTheCursor(): void
    {
        $kb = new Keybindings([]);
        $actions = $this->actions("XYZ\n");
        $actions->handleKey('-', $kb);
        $actions->handleKey('>', $kb);

        $this->assertSame(1, $this->count_('requestCompletion'), 'the prefix must stop at the cursor, not include the rest of the line');
    }

    public function testPreCaretActionConsumedKeyStopsTheCascade(): void
    {
        $kb = new Keybindings(['trigger_completion' => ['q']]);
        $actions = $this->actions();
        $actions->handleKey('q', $kb);

        $this->assertSame(1, $this->count_('requestCompletion'), 'the PRE_CARET_ACTIONS entry ran');
        $this->assertSame("alpha beta\ngamma\n", $this->doc->getText(), 'no fallthrough insert of the bound letter');
    }

    public function testSelectAllOccurrencesConsumedKeyStopsTheCascade(): void
    {
        $kb = new Keybindings(['select_all_occurrences' => ['q']]);
        $actions = $this->actions("foo bar\nfoo baz\n");
        $actions->handleKey('q', $kb);

        $this->assertTrue($this->multiCaret->hasMultiple(), 'both "foo" occurrences got carets');
        $this->assertSame("foo bar\nfoo baz\n", $this->doc->getText(), 'no fallthrough insert of the bound letter');
    }

    public function testSurroundWithConsumedKeyStopsTheCascade(): void
    {
        $kb = new Keybindings(['surround_with' => ['q']]);
        $actions = $this->actions();
        $this->doc->setSelectionRange(0, 0, 0, 5);
        $actions->handleKey('q', $kb);

        $this->assertSame(1, $this->count_('openListOverlay'), 'surround_with ran');
        $this->assertSame('alpha', $this->doc->getSelectedText(), 'no fallthrough insert of the bound letter');
    }

    public function testResolveConflictOpensTheMenuAndStopsTheCascade(): void
    {
        $text = "<<<<<<< ours\nx\n=======\ny\n>>>>>>> theirs\n";
        $kb = new Keybindings(['resolve_conflict' => ['q']]);
        $actions = $this->actions($text);
        $actions->handleKey('q', $kb);

        $this->assertSame(1, $this->count_('openListOverlay'), 'resolve_conflict opened the menu');
        $this->assertSame($text, $this->doc->getText(), 'no fallthrough insert of the bound letter');
    }

    public function testSelectAllConsumedKeyStopsTheCascade(): void
    {
        $kb = new Keybindings(['select_all' => ['q']]);
        $actions = $this->actions("one\ntwo\n");
        $actions->handleKey('q', $kb);

        $this->assertSame("one\ntwo\n", $this->doc->getSelectedText());
        $this->assertSame("one\ntwo\n", $this->doc->getText(), 'no fallthrough insert of the bound letter');
    }

    public function testDocumentStartClearsSelectionInvalidatesAndStopsTheCascade(): void
    {
        $kb = new Keybindings(['document_start' => ['q']]);
        $actions = $this->actions("alpha beta\ngamma\n");
        $this->doc->setCursor(1, 2);
        $this->doc->setSelectionRange(1, 0, 1, 5);
        $actions->handleKey('q', $kb);

        $this->assertFalse($this->doc->hasSelection(), 'the selection was cleared');
        $this->assertSame(0, $this->doc->cursorLine, 'jumped to the document start');
        $this->assertSame(0, $this->doc->cursorCol);
        $this->assertSame(1, $this->count_('invalidate'));
        $this->assertSame("alpha beta\ngamma\n", $this->doc->getText(), 'no fallthrough insert of the bound letter');
    }

    public function testDocumentEndClearsSelectionInvalidatesAndStopsTheCascade(): void
    {
        $kb = new Keybindings(['document_end' => ['q']]);
        $actions = $this->actions("alpha beta\ngamma\n");
        $this->doc->setSelectionRange(0, 0, 0, 5);
        $actions->handleKey('q', $kb);

        $this->assertFalse($this->doc->hasSelection(), 'the selection was cleared');
        $this->assertSame(2, $this->doc->cursorLine, 'jumped to the document end');
        $this->assertSame(1, $this->count_('invalidate'));
        $this->assertSame("alpha beta\ngamma\n", $this->doc->getText(), 'no fallthrough insert of the bound letter');
    }

    public function testPageMovementClearsAnExistingSelection(): void
    {
        $kb = new Keybindings(['page_down' => ['q']]);
        $actions = $this->actions(\str_repeat("line\n", times: 20));
        $this->doc->setSelectionRange(0, 0, 0, 2);
        $actions->handleKey('q', $kb);

        $this->assertFalse($this->doc->hasSelection(), 'the selection was cleared before repositioning');
    }

    public function testNewLineNotifiesAndStopsTheCascade(): void
    {
        $kb = new Keybindings(['new_line' => ['q']]);
        $actions = $this->actions("one\n");
        $this->doc->setCursor(0, 3);
        $actions->handleKey('q', $kb);

        $this->assertSame("one\n\n", $this->doc->getText(), 'a newline was inserted, no fallthrough insert of the bound letter');
        $this->assertSame(1, $this->count_('notifyChange'));
    }

    public function testPrevTabStopNotifiesAndStopsTheCascade(): void
    {
        $kb = new Keybindings(['prev_tab_stop' => ['q']]);
        $actions = $this->actions("    one\n");
        $this->doc->setCursor(0, 4);
        $actions->handleKey('q', $kb);

        $this->assertSame("one\n", $this->doc->getText(), 'dedented once, no fallthrough insert of the bound letter');
        $this->assertSame(1, $this->count_('notifyChange'));
    }

    public function testDeleteLineConsumedKeyStopsTheCascade(): void
    {
        $kb = new Keybindings(['delete_line' => ['q']]);
        $actions = $this->actions("one\ntwo\n");
        $actions->handleKey('q', $kb);

        $this->assertSame("two\n", $this->doc->getText(), 'the line was deleted, no fallthrough insert of the bound letter');
    }

    public function testDeleteWithNothingToDeleteDoesNotNotifyButStillStopsTheCascade(): void
    {
        $kb = new Keybindings(['delete_char_backward' => ['q']]);
        $actions = $this->actions("ab\n");
        $this->doc->setCursor(0, 0); // nothing before the cursor
        $actions->handleKey('q', $kb);

        $this->assertSame(0, $this->count_('notifyChange'), 'nothing changed, so no notification');
        $this->assertSame("ab\n", $this->doc->getText(), 'no fallthrough insert of the bound letter either way');
    }
}
