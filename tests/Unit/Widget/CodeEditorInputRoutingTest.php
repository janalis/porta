<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget;

use PHPUnit\Framework\TestCase;
use Porta\Editor\CommentSyntax;
use Porta\Editor\CompletionItem;
use Porta\Editor\SnippetTabStop;
use Porta\Overlay\Region\ModalBox;
use Porta\Tests\Support\FakeEditorCompletion;
use Porta\Tests\Support\Probe;
use Porta\Ui\CaretVisibility;
use Porta\Ui\Density;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\EditorCallbacks;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;

/**
 * handleInput()'s bracketed-paste guard, its signature-hint/action-router
 * dispatch, the small drag/snippet pass-throughs, the commentSyntax() routing
 * join, and composePopups()'s row/box placement — split out of
 * CodeEditorWidgetEdgeTest.php to stay under its pinned method-count budget.
 */
final class CodeEditorInputRoutingTest extends TestCase
{
    private function editor(string $content = "<?php\nline one\nline two\n", string $path = 'edge.php'): CodeEditorWidget
    {
        $editor = new CodeEditorWidget();
        $editor->openFile($path, $content);
        $editor->setFocused(true);

        return $editor;
    }

    // --- handleInput: bracketed-paste guard logic -------------------------------

    public function testHandleInputInsertsAPasteAndReturnsWithoutFallingThroughToKeybindings(): void
    {
        $editor = $this->editor("<?php\n");
        $editor->getDocument()->setCursor(1, 0);

        $editor->handleInput("\x1b[200~pasted\x1b[201~");

        $this->assertSame('pasted', $editor->getDocument()->getLine(1));
    }

    public function testHandleInputNotifiesChangeOnACompletedPaste(): void
    {
        // processBracketedPaste() only returns non-null once the END marker
        // arrives; notifyChange() must fire — checked via the LSP didChange
        // hook, since that's the only externally observable side effect of
        // notifyChange() beyond invalidate() (already covered elsewhere).
        $editor = $this->editor("<?php\n");
        $notified = false;
        $editor->setCallbacks(new EditorCallbacks(completion: new FakeEditorCompletion(
            scheduleDidChange: static function () use (&$notified): void {
                $notified = true;
            },
        )));

        $editor->handleInput("\x1b[200~pasted\x1b[201~");

        $this->assertTrue($notified, 'a completed paste must notify the change pipeline');
    }

    public function testHandleInputSwallowsAnEmptyChunkWhileBufferingAPasteWithoutFallingThroughToKeybindings(): void
    {
        $editor = $this->editor("<?php\n\n");
        $editor->getDocument()->setCursor(1, 0);

        $editor->handleInput("\x1b[200~partial"); // starts buffering, no end marker yet
        $before = $editor->getDocument()->getLine(1);

        // The empty chunk this guard exists for: must be swallowed, not
        // fall through and get treated as an (empty) keybinding/typed char.
        $editor->handleInput('');

        $this->assertSame($before, $editor->getDocument()->getLine(1), 'an empty chunk mid-paste is a no-op, not a keystroke');
    }

    public function testHandleInputDoesNotSwallowAnEmptyChunkWhenNotBufferingAPaste(): void
    {
        // '' with no paste in progress at all must fall through normally
        // (harmlessly — no keybinding matches an empty string — but it must
        // NOT take the early-return "buffering paste" branch).
        $editor = $this->editor("<?php\n");
        $before = $editor->getRenderRevision();

        $editor->handleInput('');

        // No crash, no special-cased return; nothing to assert beyond "didn't
        // throw" since an empty non-paste input is genuinely a no-op either
        // way — the real distinguishing case is covered by the two tests
        // above (with/without an active paste buffer).
        $this->assertSame($before, $editor->getRenderRevision());
    }

    // --- handleInput: signature-hint cancel / action-router dispatch -----------

    public function testHandleInputCancelsAnOpenSignatureHintOnEscapeAndReturnsEarly(): void
    {
        $editor = $this->editor();
        $editor->setSignatureHint('f(int $x)');

        $editor->handleInput("\x1b"); // select_cancel's default binding

        $this->assertNull(Probe::get($editor, 'caretPopups')->signature, 'Escape must clear the open signature hint');
    }

    public function testHandleInputDispatchedActionRouterDoesNotAlsoRunTheEditingVerb(): void
    {
        // A keystroke claimed by an earlier guard (select_cancel via the open
        // signature hint, tested above) must never also reach
        // actions->handleKey(), which would otherwise insert the ESC byte as
        // text, corrupting the document.
        $editor = $this->editor("<?php\n");
        $editor->setSignatureHint('f(int $x)');

        $editor->handleInput("\x1b");

        $this->assertSame("<?php\n", $editor->getDocument()->getText(), 'the claimed keystroke must not also reach the editing verbs');
    }

    // --- cancelWindowDrags / clearSnippetSession pass-throughs ---------------

    public function testCancelWindowDragsDisarmsAnArmedDrag(): void
    {
        $editor = $this->editor();
        $editor->findModal->open('demo');
        $editor->findModal->layout([0, 0, 24, 100], CaretVisibility::Shown, Density::Roomy);
        $box = $editor->findModal->lastBox();
        $this->assertInstanceOf(ModalBox::class, $box);
        $editor->findModal->clickAt($box->startRow, $box->col + 2); // arm a title-bar move

        $editor->cancelWindowDrags();

        $this->assertFalse($editor->findModal->continueDragTo(10, 10, release: false), 'cancelWindowDrags must reach findModal->cancelDrag() and disarm the move');
    }

    public function testClearSnippetSessionActuallyClearsIt(): void
    {
        $editor = $this->editor("<?php\n");
        $snippet = Probe::object($editor, 'completion', 'snippet');
        $snippet->begin(0, 0, 'x', [new SnippetTabStop(1, 0, 1)]);
        $this->assertTrue($snippet->isActive(), 'sanity: snippet session active');

        $editor->clearSnippetSession();

        $this->assertFalse($snippet->isActive(), 'clearSnippetSession must reach completion->clearSnippet()');
    }

    // --- commentSyntax(): the widget names the language, the table answers ---

    /**
     * The widget's job shrank to one line when the fourteen-arm match became
     * {@see CommentSyntax}'s table, so this is no longer a per-language
     * enumeration — CommentSyntaxTest owns that, over EVERY grammar rather
     * than the fifteen someone remembered. What is left here is the join: the
     * PATH resolves to a grammar and the grammar reaches the table.
     *
     * The three cases are the three answer shapes, which is what a routing
     * test can go wrong about: a line form, a block-only form, and nothing.
     */
    public function testCommentSyntaxRoutesThePathsGrammarToTheTable(): void
    {
        $editor = new CodeEditorWidget();

        $editor->openFile('demo.php', 'x');
        $this->assertSame('//', $editor->commentSyntax()?->line);
        $this->assertSame(['/*', '*/'], $editor->commentSyntax()?->block);

        // The file the round was about: block-only, and null before it.
        $editor->openFile('page.twig', 'x');
        $this->assertNull($editor->commentSyntax()?->line);
        $this->assertSame(['{#', '#}'], $editor->commentSyntax()?->block);

        $editor->openFile('demo.unknownext', 'x');
        $this->assertNull($editor->commentSyntax());
    }

    // --- composePopups: cursorScreenRow / setBox(null) / anchorCol -------------

    public function testComposePopupsCursorScreenRowFloorsAtZeroAndAddsStickyCount(): void
    {
        // A caret scrolled just above the viewport (screenRowFor returns -1)
        // combined with a pinned sticky header, so both the floor and the
        // sign of the sticky-count term are independently observable via
        // where the signature hint frame actually lands.
        $lines = "<?php\nclass Demo\n{\n";
        for ($i = 0; $i < 40; ++$i) {
            $lines .= "    // padding {$i}\n";
        }
        $lines .= "    public function tail(): void {}\n}\n";
        $editor = $this->editor($lines);
        $editor->getDocument()->setCursor(38, 0);
        $editor->render(new RenderContext(80, 10));
        $scroll = Probe::get($editor, 'scroll');
        $foldModel = Probe::get($editor, 'foldModel');
        $stickyCount = \count(Probe::get($editor, 'stickyLines'));
        $this->assertGreaterThan(0, $stickyCount, 'sanity: sticky header pinned');
        $expectedRow = \max(0, $foldModel->screenRowFor($scroll->vScroll, 38)) + $stickyCount;

        $editor->setSignatureHint('f(): void');
        $rendered = $editor->render(new RenderContext(80, 10));

        // signatureHint() frames 3 rows above cursorScreenRow when
        // cursorScreenRow>=3, else 1 row below — search for the frame's
        // top border and back out which row it anchored from.
        $frameTopRow = null;
        foreach ($rendered as $i => $row) {
            if (!\str_contains(AnsiUtils::stripAnsiCodes($row), '╭')) {
                continue;
            }
            $frameTopRow = $i;

            break;
        }
        $this->assertNotNull($frameTopRow, 'sanity: hint frame rendered');
        $actualCursorScreenRow = $expectedRow >= 3 ? $frameTopRow + 3 : $frameTopRow - 1;
        $this->assertSame($expectedRow, $actualCursorScreenRow);
    }

    public function testComposePopupsClearsAStaleCompletionBoxWhenThePopupCloses(): void
    {
        $editor = $this->editor("<?php\nfoo\n");
        $editor->getDocument()->setCursor(1, 3);
        Probe::get($editor, 'completion')->show([new CompletionItem(label: 'foo', insertText: 'foo', kind: 'fn', detail: '')]);
        $editor->render(new RenderContext(80, 10));
        $this->assertNotNull(Probe::get(Probe::get($editor, 'completion'), 'box'), 'sanity: a box was set from the open popup');

        Probe::get($editor, 'completion')->show([]); // no matches: popupState() becomes null
        $editor->render(new RenderContext(80, 10));

        $this->assertNull(Probe::get(Probe::get($editor, 'completion'), 'box'), 'a closed popup must not leave a stale box for hit-testing');
    }

    public function testComposePopupsAnchorColAccountsForHorizontalScrollAndWordWidth(): void
    {
        // A long line, scrolled right, with a multi-char word (separated by a
        // space so it's genuinely its own word) before the cursor: both terms
        // (hScroll, word width) must SUBTRACT, not add — with hScroll=0 (the
        // common case) a +/- flip on it is unobservable, so this forces a
        // real scroll.
        $prefix = \str_repeat('x', times: 60).' ';
        $editor = $this->editor("<?php\n{$prefix}fooBarBaz\n");
        $editor->getDocument()->setCursor(1, \strlen($prefix) + 9); // end of "fooBarBaz"
        $editor->render(new RenderContext(30, 10)); // narrow enough to force hScroll > 0
        $scroll = Probe::get($editor, 'scroll');
        $this->assertGreaterThan(0, $scroll->hScroll, 'sanity: horizontally scrolled');
        $this->assertSame('fooBarBaz', $editor->getDocument()->getWordBeforeCursor(), 'sanity: word boundary lands where expected');

        Probe::get($editor, 'completion')->show([new CompletionItem(label: 'fooBarBazQux', insertText: 'fooBarBazQux', kind: 'fn', detail: '')]);
        $editor->render(new RenderContext(30, 10));

        $box = Probe::get(Probe::get($editor, 'completion'), 'box');
        $this->assertNotNull($box);
        // Exact position pinned against this fixed, deterministic scenario
        // (60 x's + space + cursor at end of "fooBarBaz", 30-column pane):
        // a +/- flip on either the hScroll or word-width term inside
        // anchorCol's computation shifts this by a large, easily-detected
        // amount once passed through completionPopup()'s own pane-fit clamp.
        $this->assertSame(10, $box->col);
    }
}
