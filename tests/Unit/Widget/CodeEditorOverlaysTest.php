<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget;

use PHPUnit\Framework\TestCase;
use Porta\Editor\CompletionItem;
use Porta\Editor\OccurrenceHighlights;
use Porta\Overlay\State\FindModalCallbacks;
use Porta\Search\Everywhere\SearchCategory;
use Porta\Search\Everywhere\SearchHit;
use Porta\Search\Everywhere\SearchRow;
use Porta\Tests\Support\EditorState;
use Porta\Tests\Support\Probe;
use Porta\Widget\CodeEditorWidget;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;

/**
 * The editor's floating surfaces: the list overlay, the search overlay, the
 * completion popup and the prompt — keyboard, mouse, and the interactions
 * BETWEEN them (opening a picker closes the find modal; showing a document
 * closes whatever was open).
 *
 * Split out of CodeEditorWidgetTest, which had grown to 95 members and was
 * pinning the too-many-methods ratchet above every production class (see
 * mago.toml). The seam was already there: these were the only tests using
 * overlayEditor(), completionEditor() and searchRows().
 */
final class CodeEditorOverlaysTest extends TestCase
{
    private function editor(string $content = "<?php\n\necho 'hi';\n", string $path = 'demo.php', ?OccurrenceHighlights $occurrences = null): CodeEditorWidget
    {
        $editor = new CodeEditorWidget(occurrences: $occurrences ?? new OccurrenceHighlights());
        $editor->openFile($path, $content);
        $editor->setFocused(true);

        return $editor;
    }

    /** @param string[] $lines */
    private function assertExactWidth(array $lines, int $width): void
    {
        foreach ($lines as $i => $line) {
            $this->assertSame($width, AnsiUtils::visibleWidth($line), "row {$i} must be exactly {$width} columns");
        }
    }

    public function testListOverlayNavigationAndPick(): void
    {
        $editor = $this->editor();
        $picked = null;
        $editor->openListOverlay(
            'Recent Files',
            [
                ['label' => 'a.php', 'hint' => 'src/a.php', 'value' => 'A'],
                ['label' => 'b.php', 'hint' => 'src/b.php', 'value' => 'B'],
            ],
            static function (string $value) use (&$picked): void {
                $picked = $value;
            },
        );

        $this->assertTrue(EditorState::pickerOpen($editor));

        $placed = $editor->layoutPickers([0, 0, 20, 80]);
        $this->assertStringContainsString('Recent Files', implode("\n", $placed[0]['rows']), 'the floating box carries the title');
        $this->assertExactWidth($editor->render(new RenderContext(80, 20)), 80);

        $editor->handleInput("\x1b[B"); // down
        $editor->handleInput("\r"); // pick
        $this->assertSame('B', $picked);
        $this->assertFalse(EditorState::pickerOpen($editor));
    }

    public function testListOverlayEscapeCloses(): void
    {
        $editor = $this->editor();
        $editor->openListOverlay('X', [['label' => 'a', 'hint' => '', 'value' => 'a']], static fn () => null);

        $editor->handleInput("\x1b");
        $this->assertFalse(EditorState::pickerOpen($editor));
    }

    /** @return list<SearchRow> */
    private function searchRows(): array
    {
        return [
            SearchRow::header('Files'),
            SearchRow::hit(new SearchHit(SearchCategory::Files, 'alpha.php', 'src', static fn () => null)),
            SearchRow::hit(new SearchHit(SearchCategory::Files, 'beta.php', 'src', static fn () => null)),
        ];
    }

    public function testSearchOverlayKeyboardPassThrough(): void
    {
        $editor = $this->editor();
        $picked = null;
        $editor->openSearch(
            'Search Everywhere',
            static fn () => null,
            static function (SearchHit $h) use (&$picked): void {
                $picked = $h->label;
            },
            static fn () => null,
        );
        $this->assertTrue($editor->isSearchOverlayOpen());
        $editor->setSearchRows($this->searchRows());

        $placed = $editor->layoutPickers([0, 0, 20, 80]);
        $this->assertStringContainsString('Search Everywhere', implode("\n", $placed[0]['rows']), 'the floating box carries the title');
        $this->assertStringContainsString('Files', implode("\n", $placed[0]['rows']), 'the category header row renders its label');
        $this->assertExactWidth($editor->render(new RenderContext(80, 20)), 80);

        $editor->handleSearchOverlayInput("\x1b[B"); // down → beta.php
        $editor->handleSearchOverlayInput("\r"); // pick
        $this->assertSame('beta.php', $picked);

        $editor->closeSearch();
        $this->assertFalse($editor->isSearchOverlayOpen());
    }

    public function testSearchOverlayMouseClickPicksAndHoverSelects(): void
    {
        $editor = $this->editor();
        $picked = null;
        $editor->openSearch(
            'Search',
            static fn () => null,
            static function (SearchHit $h) use (&$picked): void {
                $picked = $h->label;
            },
            static fn () => null,
        );
        $editor->setSearchRows($this->searchRows());
        $editor->layoutPickers([0, 0, 20, 80]);

        // Hover finds a hit row (the search branch of handlePickerHover);
        // clicking it picks that hit (the search branch of handlePickerClick).
        $hitRow = -1;
        for ($row = 0; $row < 20 && $hitRow < 0; ++$row) {
            if (!$editor->handlePickerHover($row, 40)) {
                continue;
            }

            $hitRow = $row;
        }
        $this->assertGreaterThanOrEqual(0, $hitRow);
        $this->assertTrue($editor->handlePickerClick($hitRow, 40));
        $this->assertNotNull($picked);
    }

    /**
     * The modal floats in screen space now: the editor's popup click/hover
     * paths no longer see it (the router hits it directly), while the drag
     * delegates still relay through the widget.
     */
    public function testFindModalRoutesInScreenSpaceNotThroughThePopupPaths(): void
    {
        $editor = $this->editor();
        $jumped = null;
        $editor
            ->findModal()
            ->setCallbacks(new FindModalCallbacks(
                onJump: static function (string $p, int $l) use (&$jumped): void {
                    $jumped = [$p, $l];
                },
            ));
        $editor->findModal()->open('Find in Files');
        $editor
            ->findModal()
            ->setPreview([
                ['path' => 'src/A.php', 'line' => 4, 'text' => 'foo one', 'ranges' => []],
                ['path' => 'src/A.php', 'line' => 9, 'text' => 'foo two', 'ranges' => []],
            ]);
        $editor->findModal()->layout([0, 0, 40, 100], \Porta\Ui\CaretVisibility::Shown); // records the screen-space box

        $box = $editor->findModal()->lastBox();
        $this->assertNotNull($box);
        $this->assertFalse($editor->handlePopupHover($box->windowTop() + 2, $box->col + 3), 'the popup hover path no longer owns the modal');

        // The modal's own screen-space hit-tests carry the behavior.
        $this->assertTrue($editor->findModal()->hoverAt($box->windowTop() + 2, $box->col + 3));
        $this->assertTrue($editor->findModal()->clickAt($box->windowTop() + 1, $box->col + 3));
        $this->assertSame(['src/A.php', 4], $jumped);

        // The drag delegates still relay (armed by a corner press).
        $this->assertTrue($editor->findModal()->clickAt($box->startRow + $box->rows - 1, $box->col + $box->width - 1));
        $this->assertTrue($editor->continueFindModalDrag($box->startRow + $box->rows, $box->col + $box->width + 2, true));
        $editor->cancelFindModalDrag();
    }

    /** Opening any picker closes the Find-in-Files modal — one writer per frame on the shared span slot. */
    public function testOpeningAPickerClosesTheFindModal(): void
    {
        $editor = $this->editor();
        $editor->findModal()->open('Find in Files');
        $this->assertTrue($editor->isFindModalOpen());

        $editor->openListOverlay('Recent Files', [['label' => 'a', 'hint' => '', 'value' => 'A']], static fn () => null);
        $this->assertFalse($editor->isFindModalOpen(), 'the list overlay displaced the find modal');

        $editor->findModal()->open('Find in Files');
        $editor->openSearch('Search', static fn () => null, static fn () => null, static fn () => null);
        $this->assertFalse($editor->isFindModalOpen(), 'the search modal displaced it too');

        $editor->findModal()->open('Find in Files');
        $editor->openPrompt('Go to line', '', static fn () => null);
        $this->assertFalse($editor->isFindModalOpen(), 'and so did the prompt');
    }

    private function overlayEditor(\ArrayObject $sink): CodeEditorWidget
    {
        $editor = $this->editor();
        $editor->openListOverlay(
            'Recent Files',
            [
                ['label' => 'a.php', 'hint' => 'src/a.php', 'value' => 'A'],
                ['label' => 'b.php', 'hint' => 'src/b.php', 'value' => 'B'],
            ],
            static function (string $value) use ($sink): void {
                $sink['picked'] = $value;
            },
        );

        return $editor;
    }

    public function testListOverlayClickPicksItem(): void
    {
        $sink = new \ArrayObject(['picked' => null]);
        $editor = $this->overlayEditor($sink);
        // width 20 (title 12+4 vs item 5+9+6=20) centred → col 30; popup height 3 →
        // startRow intdiv(20-3,3)=5; title row 5, items at rows 6 and 7.
        $editor->layoutPickers([0, 0, 20, 80]);

        $this->assertTrue($editor->handlePickerClick(7, 35), 'a click on an item is consumed');
        $this->assertSame('B', $sink['picked']);
        $this->assertFalse(EditorState::pickerOpen($editor));
    }

    public function testListOverlayClickOutsideDismisses(): void
    {
        $sink = new \ArrayObject(['picked' => null]);
        $editor = $this->overlayEditor($sink);
        $editor->layoutPickers([0, 0, 20, 80]);

        $this->assertTrue($editor->handlePickerClick(0, 0), 'the modal overlay consumes any click');
        $this->assertNull($sink['picked']);
        $this->assertFalse(EditorState::pickerOpen($editor));
    }

    public function testListOverlayClickBeforeRenderDismisses(): void
    {
        $sink = new \ArrayObject(['picked' => null]);
        $editor = $this->overlayEditor($sink);
        // No layout yet → no captured box → the click cannot hit an item, so it dismisses.
        $this->assertTrue($editor->handlePickerClick(7, 35));
        $this->assertNull($sink['picked']);
        $this->assertFalse(EditorState::pickerOpen($editor));
    }

    public function testPromptClickOnItsBoxIsSwallowedAndElsewhereDismisses(): void
    {
        $editor = $this->editor();
        $editor->openPrompt('Go to line', '', static fn () => null);
        $editor->layoutPickers([0, 0, 20, 80]); // records the centered prompt box (row 6, cols 25..55)

        $this->assertTrue(EditorState::promptOpen($editor));
        $this->assertTrue($editor->handlePickerClick(6, 40), 'a click on the centered prompt box is consumed');
        $this->assertTrue(EditorState::promptOpen($editor), 'and does not dismiss it — the caret stays');
        $this->assertTrue($editor->handlePickerClick(0, 3), 'a click off the prompt is consumed');
        $this->assertFalse(EditorState::promptOpen($editor), 'and dismisses it, like every sibling overlay');
    }

    public function testListOverlayHoverTintsWithoutMovingTheSelection(): void
    {
        $editor = $this->overlayEditor(new \ArrayObject(['picked' => null]));
        $editor->layoutPickers([0, 0, 20, 80]);

        $this->assertTrue($editor->handlePickerHover(7, 35), 'hover item 1 tints it');
        $this->assertFalse($editor->handlePickerHover(7, 35), 'same row → no change');
        $this->assertTrue($editor->handlePickerHover(5, 35), 'the title row is no item: the tint clears');
        $this->assertFalse($editor->handlePickerHover(5, 35), 'still no item → no change');

        // Hover item 1; item 0 stays selected.
        $editor->handlePickerHover(7, 35);
        $body = implode("\n", $editor->layoutPickers([0, 0, 20, 80])[0]['rows']);
        $this->assertStringContainsString("\x1b[48;5;31m", $body, 'the selected item keeps its background');
        $this->assertStringContainsString("\x1b[48;5;236m", $body, 'the hovered item takes the hover tint');
    }

    private function completionEditor(): CodeEditorWidget
    {
        $editor = $this->editor();
        $editor->getDocument()->setCursor(2, 5); // after "echo " → empty word-before-cursor
        $editor->showCompletions([
            new CompletionItem(label: 'echoFirst', insertText: 'echoFirst', kind: 'fn', detail: ''),
            new CompletionItem(label: 'echoSecond', insertText: 'echoSecond', kind: 'fn', detail: ''),
        ]);

        return $editor;
    }

    public function testCompletionClickAcceptsSuggestion(): void
    {
        $editor = $this->completionEditor();
        // anchorCol = gutter 4 + cursorCol 5 = 9; cursorScreenRow 2 → the
        // frame's top border at row 3; items at rows 4 and 5, inner box cols [9, 26).
        $editor->render(new RenderContext(80, 20));

        $this->assertTrue($editor->handlePopupClick(5, 12), 'a click on a suggestion is consumed');
        $this->assertStringContainsString('echoSecond', $editor->getDocument()->getLine(2));
        $this->assertFalse($editor->isCompletionOpen());
    }

    public function testCompletionClickOutsideNotConsumed(): void
    {
        $editor = $this->completionEditor();
        $editor->render(new RenderContext(80, 20));

        $this->assertFalse($editor->handlePopupClick(0, 0), 'a click off the popup falls through to caret placement');
        $this->assertTrue($editor->isCompletionOpen());
    }

    public function testCompletionClickBeforeRenderNotConsumed(): void
    {
        $editor = $this->completionEditor();
        // No render → no captured box → not consumed.
        $this->assertFalse($editor->handlePopupClick(4, 12));
        $this->assertTrue($editor->isCompletionOpen());
    }

    public function testCompletionHoverTintsWithoutMovingTheSelection(): void
    {
        $editor = $this->completionEditor();
        $editor->render(new RenderContext(80, 20));

        $this->assertTrue($editor->handlePopupHover(5, 12), 'hover the second suggestion tints it');
        $this->assertFalse($editor->handlePopupHover(5, 12), 'same row → no change');
        $this->assertTrue($editor->handlePopupHover(2, 12), 'a row above the popup clears the tint');
        $this->assertFalse($editor->handlePopupHover(2, 12), 'still off the popup → no change');

        // Hover the second item; the first stays selected.
        $editor->handlePopupHover(5, 12);
        $rows = $editor->render(new RenderContext(80, 20));
        $this->assertStringContainsString("\x1b[48;5;31m", $rows[4], 'the first suggestion keeps the selection background');
        $this->assertStringContainsString("\x1b[48;5;236m", $rows[5], 'the hovered second suggestion takes the hover tint, not the selection');

        // A click still accepts the row it lands on, regardless of hover.
        $editor->handlePopupClick(5, 12);
        $this->assertStringContainsString('echoSecond', $editor->getDocument()->getLine(2));
    }

    public function testPopupClickAndHoverNoopWhenNothingOpen(): void
    {
        $editor = $this->editor();
        $this->assertFalse($editor->handlePopupClick(3, 3));
        $this->assertFalse($editor->handlePopupHover(3, 3));
    }

    /** Hover routes to the open prompt (its × brightens under the pointer). */
    public function testPromptHoverRoutesToTheOverlayHost(): void
    {
        $editor = $this->editor();
        $editor->openPrompt('Go to line', '', static fn () => null);
        $editor->layoutPickers([0, 0, 20, 80]); // records the prompt box

        $host = Probe::get($editor, 'overlayHost');
        $box = Probe::get($host, 'promptBox');
        \assert($box instanceof \Porta\Overlay\Region\ModalBox, description: 'the overlay laid out a modal box');

        $this->assertTrue($editor->handlePickerHover($box->startRow, $box->col + $box->width - 2), 'onto the ×');
        $this->assertFalse($editor->handlePickerHover($box->startRow, $box->col + $box->width - 2), 'unchanged on the same cell');
    }

    public function testPromptSubmitAndCancel(): void
    {
        $editor = $this->editor();

        $submitted = null;
        $editor->openPrompt('Go to line', '', static function (string $v) use (&$submitted): void {
            $submitted = $v;
        });
        $placed = $editor->layoutPickers([0, 0, 20, 80]);
        $this->assertStringContainsString('Go to line', AnsiUtils::stripAnsiCodes(implode("\n", $placed[0]['rows'])), 'the floating prompt renders');
        $this->assertExactWidth($editor->render(new RenderContext(80, 20)), 80);

        $editor->handleInput('4');
        $editor->handleInput('2');
        $editor->handleInput("\x7f"); // backspace -> "4"
        $editor->handleInput("\r");
        $this->assertSame('4', $submitted);

        $editor->openPrompt('Rename to', 'old', static function (string $v) use (&$submitted): void {
            $submitted = $v;
        });
        $editor->handleInput("\x1b"); // cancel
        $editor->handleInput('Z'); // goes to the buffer, not a prompt
        $this->assertSame('4', $submitted, 'cancelled prompt does not submit');
    }

    public function testMovePopupSelectionDispatchesToTheOpenPopup(): void
    {
        $editor = $this->editor();
        $this->assertFalse($editor->movePopupSelection(3), 'no popup: falls through to the buffer scroll');

        // Completion popup: the wheel wraps its selection like the arrows.
        $editor->getDocument()->setCursor(2, 5);
        $editor->showCompletions([
            new CompletionItem(label: 'echoFirst', insertText: 'echoFirst', kind: 'fn', detail: ''),
            new CompletionItem(label: 'echoSecond', insertText: 'echoSecond', kind: 'fn', detail: ''),
        ]);
        $this->assertTrue($editor->movePopupSelection(3), 'the completion consumed the wheel');
        $editor->handleInput("\r");
        $this->assertStringContainsString('echoSecond', $editor->getDocument()->getLine(2), '3 % 2 steps land on the second candidate');

        // List overlay: same dispatch, wrapping selection.
        $picked = null;
        $editor->openListOverlay(
            'Recent Files',
            [
                ['label' => 'a.php', 'hint' => '', 'value' => 'A'],
                ['label' => 'b.php', 'hint' => '', 'value' => 'B'],
                ['label' => 'c.php', 'hint' => '', 'value' => 'C'],
            ],
            static function (string $value) use (&$picked): void {
                $picked = $value;
            },
        );
        $this->assertTrue($editor->movePopupSelection(-1), 'the list overlay consumed the wheel');
        $editor->handleInput("\r");
        $this->assertSame('C', $picked, 'a wheel-up from the first row wraps to the last');

        // Search Everywhere modal: the wheel reaches its selection too.
        $editor->openSearch('Search', static fn () => null, static fn () => null, static fn () => null);
        $this->assertTrue($editor->movePopupSelection(1), 'the search modal consumed the wheel');
        $editor->handleSearchOverlayInput("\x1b");
    }

    public function testShowDocumentClosesTheCompletionPopup(): void
    {
        $editor = $this->editor("<?php\n\$abc\n");
        $editor->getDocument()->setCursor(1, 4); // right after "abc"
        Probe::get($editor, 'completion')->show([new CompletionItem('abcdef', 'abcdef', 'var', '')]);
        $this->assertTrue($editor->isCompletionOpen(), 'sanity: the popup is actually open before the swap');

        $editor->showDocument('other.php', new \Porta\Editor\Document("<?php\n"));

        // Note: this passes even without the explicit closeCompletion() call
        // at the end of showDocument(), because the completion collaborator
        // is unconditionally replaced with a fresh (closed) instance earlier
        // in the same method — that later call is redundant given the current
        // ordering, not a gap this test can expose.
        $this->assertFalse($editor->isCompletionOpen(), 'no completion popup survives a buffer swap');
    }

    public function testShowDocumentClosesAnOpenListOverlay(): void
    {
        $editor = $this->editor();
        $editor->openListOverlay('Recent', [['label' => 'a', 'hint' => '', 'value' => 'a']], static function (string $v): void {});
        $this->assertTrue(Probe::get($editor, 'overlayHost')->anyOpen(), 'sanity: the list overlay is actually open before the swap');

        $editor->showDocument('other.php', new \Porta\Editor\Document("<?php\n"));

        $this->assertFalse(Probe::get($editor, 'overlayHost')->anyOpen(), 'showDocument must close a list overlay left open from the old buffer');
    }

    public function testMoveSelectionWithoutCandidatesIsInert(): void
    {
        // Directly on the collaborator: an empty candidate list must not
        // divide by zero when a stray wheel reaches it.
        $completion = new \Porta\Editor\EditorCompletion(
            new \Porta\Editor\Document(''),
            static function (): void {},
            static function (): void {},
            static fn (): bool => true,
            static function (): void {},
            static function (): void {},
        );
        $completion->moveSelection(1);
        $this->addToAssertionCount(1); // no crash, no invalidation needed
    }
}
