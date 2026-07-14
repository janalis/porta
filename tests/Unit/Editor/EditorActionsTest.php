<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\TestCase;
use Porta\Config\ActionCatalog;
use Porta\Editor\AutoPairService;
use Porta\Editor\Document;
use Porta\Editor\EditorActions;
use Porta\Editor\EditorCompletion;
use Porta\Editor\EditorStructural;
use Porta\Editor\FoldModel;
use Porta\Editor\MultiCaret;
use Porta\Input\TextInput;
use Porta\Widget\EditorKeymap;
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

    private function actions(string $text = "alpha beta\ngamma\n", string $clipboard = ''): EditorActions
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
            static fn (string $d): bool => TextInput::isPrintable($d),
            $this->record('requestCompletion'),
            $this->record('resolveCompletion'),
        );
        $structural = new EditorStructural($this->doc, $autoPair, $invalidate, $notifyChange, $this->record('openListOverlay'));
        $this->foldModel = new FoldModel($this->doc);

        return new EditorActions(
            $this->doc,
            $this->multiCaret,
            $completion,
            $structural,
            $this->foldModel,
            $autoPair,
            $invalidate,
            $notifyChange,
            $this->record('openPrompt'),
            $this->record('jumpTo'),
            $this->record('setSignatureHint'),
            $this->record('fireCopy'),
            $this->record('fireSignatureHelp'),
            $this->record('requestRename'),
            static fn (): int => 5,
            static fn (): ?string => '//',
            static fn (): bool => true,
            static fn (string $d): bool => TextInput::isPrintable($d),
            $clipboard,
        );
    }

    private function count_(string $name): int
    {
        return \count($this->calls[$name] ?? []);
    }

    // --- run(): every verb ----------------------------------------------------

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

    public function testRunToggleComment(): void
    {
        $actions = $this->actions("code\n");
        $actions->run('toggle_comment');
        $this->assertSame("// code\n", $this->doc->getText());
        $actions->run('toggle_comment');
        $this->assertSame("code\n", $this->doc->getText());
    }

    public function testRunToggleFoldInvalidates(): void
    {
        $actions = $this->actions("if {\n  a\n}\n");
        $actions->run('toggle_fold');
        $this->assertSame(1, $this->count_('invalidate'));
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

        $onSubmit('nope');
        $this->assertCount(1, $this->calls['jumpTo'], 'non-digits are ignored');
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
        $this->multiCaret->setExtras([['line' => 1, 'col' => 3, 'anchorLine' => 1, 'anchorCol' => 0]]);
        $this->assertTrue($this->multiCaret->hasMultiple());
    }

    public function testBlockCopyInterceptsBeforeMultiCaretCollapses(): void
    {
        $actions = $this->actions("foo x\nfoo y\n");
        $this->selectBothFoos();

        $kb = new Keybindings(['copy' => ['ctrl+c']]);
        $actions->handleKey("\x03", $kb);

        $this->assertSame("foo\nfoo", $actions->clipboardValue(), 'one clipboard line per caret');
        $this->assertSame([["foo\nfoo"]], $this->calls['fireCopy']);
        $this->assertTrue($this->multiCaret->hasMultiple(), 'copy keeps the carets');
    }

    public function testBlockCutDeletesEveryCaretSelection(): void
    {
        $actions = $this->actions("foo x\nfoo y\n");
        $this->selectBothFoos();

        $kb = new Keybindings(['cut' => ['ctrl+x']]);
        $actions->handleKey("\x18", $kb);

        $this->assertSame("foo\nfoo", $actions->clipboardValue());
        $this->assertSame(" x\n y\n", $this->doc->getText());
    }

    public function testMovementClearsTheSelectionAndItsSelectVariantExtendsIt(): void
    {
        $kb = new Keybindings(['cursor_right' => ['right'], 'select_right' => ['shift+right']]);

        $actions = $this->actions("ab\n");
        $actions->handleKey("\x1b[C", $kb);
        $this->assertSame(1, $this->doc->getCursorCol());
        $this->assertFalse($this->doc->hasSelection());

        $actions->handleKey("\x1b[1;2C", $kb);
        $this->assertSame('b', $this->doc->getSelectedText());
    }

    public function testCursorVerticalMovementClearsTheSignatureHint(): void
    {
        $kb = new Keybindings(['cursor_down' => ['down']]);
        $actions = $this->actions("a\nb\n");
        $actions->handleKey("\x1b[B", $kb);
        $this->assertSame([[null]], $this->calls['setSignatureHint']);
    }

    public function testPageMovementUsesTheViewportRows(): void
    {
        $kb = new Keybindings(['page_down' => ['page_down']]);
        $actions = $this->actions(str_repeat("line\n", 20));
        $actions->handleKey("\x1b[6~", $kb);
        $this->assertSame(5, $this->doc->getCursorLine(), 'contentRows closure supplies the page size');
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
        $actions->handleKey(')', $kb);
        $this->assertSame([[null]], $this->calls['setSignatureHint']);
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
}
