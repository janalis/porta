<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget;

use PHPUnit\Framework\TestCase;
use Porta\Editor\CompletionItem;
use Porta\Editor\Document;
use Porta\Editor\EditorCompletion;
use Porta\Editor\OccurrenceHighlights;
use Porta\Overlay\Region\ModalBox;
use Porta\Overlay\State\FindModalCallbacks;
use Porta\Search\Everywhere\SearchCategory;
use Porta\Search\Everywhere\SearchHit;
use Porta\Search\Everywhere\SearchRow;
use Porta\Tests\Support\EditorState;
use Porta\Tests\Support\Probe;
use Porta\Ui\CaretVisibility;
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
        $this->assertStringContainsString('Recent Files', \implode("\n", $placed[0]['rows']), 'the floating box carries the title');
        $this->assertExactWidth($editor->render(new RenderContext(80, 20)), 80);

        $editor->handleInput("\x1b[B"); // down
        $editor->handleInput("\r"); // pick
        $this->assertSame('B', $picked);
        $this->assertFalse(EditorState::pickerOpen($editor));
    }

    public function testListOverlayEscapeCloses(): void
    {
        $editor = $this->editor();
        $editor->openListOverlay('X', [['label' => 'a', 'hint' => '', 'value' => 'a']], static fn (): null => null);

        $editor->handleInput("\x1b");
        $this->assertFalse(EditorState::pickerOpen($editor));
    }

    /** @return list<SearchRow> */
    private function searchRows(): array
    {
        return [
            SearchRow::header('Files'),
            SearchRow::hit(new SearchHit(SearchCategory::Files, 'alpha.php', 'src', static fn (): null => null)),
            SearchRow::hit(new SearchHit(SearchCategory::Files, 'beta.php', 'src', static fn (): null => null)),
        ];
    }

    public function testSearchOverlayKeyboardPassThrough(): void
    {
        $editor = $this->editor();
        $picked = null;
        $editor->openSearch(
            'Search Everywhere',
            static fn (): null => null,
            static function (SearchHit $h) use (&$picked): void {
                $picked = $h->label;
            },
            static fn (): null => null,
        );
        $this->assertTrue($editor->isSearchOverlayOpen());
        $editor->setSearchRows($this->searchRows());

        $placed = $editor->layoutPickers([0, 0, 20, 80]);
        $this->assertStringContainsString('Search Everywhere', \implode("\n", $placed[0]['rows']), 'the floating box carries the title');
        $this->assertStringContainsString('Files', \implode("\n", $placed[0]['rows']), 'the category header row renders its label');
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
            static fn (): null => null,
            static function (SearchHit $h) use (&$picked): void {
                $picked = $h->label;
            },
            static fn (): null => null,
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
        $editor->findModal
            ->setCallbacks(new FindModalCallbacks(
                onJump: static function (string $p, int $l) use (&$jumped): void {
                    $jumped = [$p, $l];
                },
            ));
        $editor->findModal->open('Find in Files');
        $editor->findModal
            ->setPreview([
                ['path' => 'src/A.php', 'line' => 4, 'text' => 'foo one', 'ranges' => []],
                ['path' => 'src/A.php', 'line' => 9, 'text' => 'foo two', 'ranges' => []],
            ]);
        $editor->findModal->layout([0, 0, 40, 100], CaretVisibility::Shown); // records the screen-space box

        $box = $editor->findModal->lastBox();
        $this->assertInstanceOf(ModalBox::class, $box);
        $this->assertFalse($editor->handlePopupHover($box->windowTop() + 2, $box->col + 3), 'the popup hover path no longer owns the modal');

        // The modal's own screen-space hit-tests carry the behavior.
        $this->assertTrue($editor->findModal->hoverAt($box->windowTop() + 2, $box->col + 3));
        $this->assertTrue($editor->findModal->clickAt($box->windowTop() + 1, $box->col + 3));
        $this->assertSame(['src/A.php', 4], $jumped);

        // The drag delegates still relay (armed by a corner press).
        $this->assertTrue($editor->findModal->clickAt($box->startRow + $box->rows - 1, $box->col + $box->width - 1));
        $this->assertTrue($editor->continueFindModalDrag($box->startRow + $box->rows, $box->col + $box->width + 2, true));
        $editor->cancelFindModalDrag();
    }

    /** Opening any picker closes the Find-in-Files modal — one writer per frame on the shared span slot. */
    public function testOpeningAPickerClosesTheFindModal(): void
    {
        $editor = $this->editor();
        $editor->findModal->open('Find in Files');
        $this->assertTrue($editor->isFindModalOpen());

        $editor->openListOverlay('Recent Files', [['label' => 'a', 'hint' => '', 'value' => 'A']], static fn (): null => null);
        $this->assertFalse($editor->isFindModalOpen(), 'the list overlay displaced the find modal');

        $editor->findModal->open('Find in Files');
        $editor->openSearch('Search', static fn (): null => null, static fn (): null => null, static fn (): null => null);
        $this->assertFalse($editor->isFindModalOpen(), 'the search modal displaced it too');

        $editor->findModal->open('Find in Files');
        $editor->openPrompt('Go to line', '', static fn (): null => null);
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
        // Nine rows (title, padding, the three-row filter field, two items,
        // padding, bottom) centred a third down → title row 3, items at 8 and 9.
        $editor->layoutPickers([0, 0, 20, 80]);

        $this->assertTrue($editor->handlePickerClick(9, 35), 'a click on an item is consumed');
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
        $editor->openPrompt('Go to line', '', static fn (): null => null);
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

        $this->assertTrue($editor->handlePickerHover(9, 35), 'hover item 1 tints it');
        $this->assertFalse($editor->handlePickerHover(9, 35), 'same row → no change');
        $this->assertTrue($editor->handlePickerHover(3, 35), 'the title row is no item: the tint clears');
        $this->assertFalse($editor->handlePickerHover(3, 35), 'still no item → no change');

        // Hover item 1; item 0 stays selected.
        $editor->handlePickerHover(9, 35);
        $body = \implode("\n", $editor->layoutPickers([0, 0, 20, 80])[0]['rows']);
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

    public function testTheCompletionPopupSHOWSTheServersTypeColumn(): void
    {
        // `detail` — a method's signature, a property's type — has been mapped
        // onto every item since completions existed and drawn by nobody. It is
        // PhpStorm's type column, and it was already on the wire.
        $editor = $this->editor();
        $editor->getDocument()->setCursor(2, 5);
        $editor->showCompletions([
            new CompletionItem(label: 'name', insertText: 'name', kind: 'prop', detail: 'string'),
            new CompletionItem(label: 'handle', insertText: 'handle', kind: 'fn', detail: '(Request $r): Response'),
        ]);

        $rows = $editor->render(new RenderContext(120, 20));
        $body = \implode("\n", $rows);

        $this->assertStringContainsString('string', $body, "the property's type is shown");
        $this->assertStringContainsString('(Request $r): Response', $body, "and the method's signature");
        $this->assertStringContainsString("\x1b[38;5;244m", $body, 'in the muted pen, so the name still reads first');
        $this->assertExactWidth($rows, 120);
    }

    public function testATypeTooLongForTheBoxYIELDSToTheNAME(): void
    {
        // A completion you cannot read the name of is not a completion. The
        // type is what you check once the name is already the one you meant, so
        // it is the half that gets truncated — and at some width it disappears
        // rather than squeezing the label below what a name needs.
        $editor = $this->editor();
        $editor->getDocument()->setCursor(2, 5);
        $editor->showCompletions([
            new CompletionItem(
                label: 'withAVeryLongDescriptiveName',
                insertText: 'withAVeryLongDescriptiveName',
                kind: 'fn',
                detail: '(ContainerInterface $container, LoggerInterface $logger, array $options = []): static',
            ),
        ]);

        $rows = $editor->render(new RenderContext(60, 20));
        $body = \implode("\n", $rows);

        $this->assertStringContainsString('withAVeryLongDescr', $body, 'the name survives, truncated at worst');
        // The exact cell, not just "shorter": the width is one subtraction, and
        // an off-by-one there still renders something perfectly plausible.
        $this->assertStringContainsString('(ContainerI…', $body, 'the type takes exactly what the name left');
        $this->assertStringNotContainsString('(ContainerIn', $body, 'and not one column more');
        $this->assertExactWidth($rows, 60);
    }

    public function testANameThatFillsTheBoxLeavesTheTypeNOTHINGAndIsNotSqueezed(): void
    {
        // The end of the same rule: when the name alone fills the row, the type
        // is not shown at all — not one column of it, and not an ellipsis
        // standing in for it. A single leftover column would cost the name a
        // character to say nothing.
        $editor = $this->editor();
        $editor->getDocument()->setCursor(2, 5);
        $editor->showCompletions([
            new CompletionItem(
                label: 'aNameLongEnoughToFillTheWholeCompletionRowByItself',
                insertText: 'aNameLongEnoughToFillTheWholeCompletionRowByItself',
                kind: 'fn',
                detail: 'string',
            ),
        ]);

        $rows = $editor->render(new RenderContext(60, 20));
        $body = \implode("\n", $rows);

        // The whole cell, because "no type" has near-misses that all look
        // plausible: a one-column type showing a bare ellipsis, or a type cell
        // of negative width that the frame's rail quietly absorbs. Both cost
        // the name a character to say nothing.
        $this->assertStringContainsString(
            '┃▌aNameLongEnoughToFillTheWholeCompletionR… fn ┃',
            AnsiUtils::stripAnsiCodes($body),
            'every column that is left goes to the name, and the type is simply absent',
        );
        $this->assertExactWidth($rows, 60);
    }

    /** @param non-empty-string $doc */
    private function documentedEditor(string $doc): CodeEditorWidget
    {
        $editor = $this->editor();
        $editor->getDocument()->setCursor(2, 5);
        $editor->showCompletions([
            new CompletionItem(label: 'greet', insertText: 'greet', kind: 'fn', detail: 'string', documentation: $doc),
            new CompletionItem(label: 'name', insertText: 'name', kind: 'prop', detail: 'string'),
        ]);

        return $editor;
    }

    public function testTheSelectedItemsDOCBLOCKIsShownBesideThePopup(): void
    {
        // Both servers send `documentation` and neither sends it with the
        // completion — it comes back on the resolve the type column already
        // asks for, so the panel costs no extra traffic.
        $editor = $this->documentedEditor("Greets somebody by name.\n\npublic function greet(string \$who): string");

        $rows = $editor->render(new RenderContext(120, 14));
        $screen = \implode("\n", \array_map(AnsiUtils::stripAnsiCodes(...), $rows));

        $this->assertStringContainsString('Greets somebody by name.', $screen);
        $this->assertStringContainsString('public function greet(string $who): string', $screen);
        $this->assertExactWidth($rows, 120);
    }

    public function testTheDOCBLOCKPanelIsTALLERThanTheListWhenItNeedsToBe(): void
    {
        // Two matching completions must not mean two lines of docblock: the
        // panel's height is its own. A panel bounded by the list would show a
        // sentence and a half beside a two-item popup.
        $editor = $this->documentedEditor("one\ntwo\nthree\nfour\nfive\nsix");

        $screen = \implode("\n", \array_map(AnsiUtils::stripAnsiCodes(...), $editor->render(new RenderContext(120, 14))));

        $this->assertStringContainsString('six', $screen, 'the sixth line is there beside a two-row list');
    }

    public function testTheDOCBLOCKFollowsTheSelection(): void
    {
        // The panel is about the row you are ON. Moving to an item with no
        // documentation must take the panel with it rather than leave the
        // previous item's prose beside the new name.
        $editor = $this->documentedEditor('Greets somebody by name.');
        $editor->render(new RenderContext(120, 14));

        $editor->movePopupSelection(1); // → `name`, which has no documentation
        $rows = $editor->render(new RenderContext(120, 14));
        $screen = \implode("\n", \array_map(AnsiUtils::stripAnsiCodes(...), $rows));

        $this->assertStringNotContainsString('Greets somebody by name.', $screen);
        // The panel is GONE, not blank: an empty frame beside the list is a
        // box that says nothing and takes forty-six columns to say it.
        $this->assertSame(0, \substr_count(AnsiUtils::stripAnsiCodes($rows[3]), needle: '╭'), 'no docblock box on screen');
        $this->assertSame(1, \substr_count(AnsiUtils::stripAnsiCodes($rows[3]), needle: '┏'), 'the list alone, in its heavy frame');
    }

    public function testTheDOCBLOCKGoesLEFTWhenThereIsNoRoomOnTheRight(): void
    {
        // A caret far along a long line: the popup is anchored under it, near
        // the right edge, so the panel cannot go where it normally goes. A
        // fixture with the caret at column 5 proves nothing here — it has room
        // on the right and never takes the other branch.
        $editor = $this->editor("<?php\n\$a = 1;\n".\str_repeat('x', times: 55)." echo\n");
        $editor->getDocument()->setCursor(2, 60);
        $editor->showCompletions([new CompletionItem(label: 'greet', insertText: 'greet', kind: 'fn', detail: '', documentation: 'Greets somebody.')]);

        $rows = $editor->render(new RenderContext(100, 10));
        $row = AnsiUtils::stripAnsiCodes($rows[4]);

        $this->assertStringContainsString('Greets somebody.', $row, 'the panel moved to the other side rather than vanishing');
        $this->assertLessThan(\mb_strpos($row, needle: 'greet '), \mb_strpos($row, needle: 'Greets somebody.'), 'and it is to the LEFT of the list');
        // The panel's own drop shadow falls to its right: flush against the
        // popup it paints over the popup's left border.
        $this->assertStringContainsString('│ ┃▌greet', $row, 'both boxes keep all four walls');
        $this->assertExactWidth($rows, 100);
    }

    public function testNoROOMOnEitherSideMeansNoPanelRatherThanAKeyhole(): void
    {
        // Twenty columns of a docblock is not a docblock. The popup keeps the
        // pane it was given, and every row still measures exactly.
        $editor = $this->editor();
        $editor->getDocument()->setCursor(2, 5);
        $editor->showCompletions([new CompletionItem(label: 'greet', insertText: 'greet', kind: 'fn', detail: '', documentation: 'Greets somebody.')]);

        $rows = $editor->render(new RenderContext(40, 14));

        $this->assertStringNotContainsString('Greets somebody.', \implode("\n", \array_map(AnsiUtils::stripAnsiCodes(...), $rows)));
        $this->assertExactWidth($rows, 40);
    }

    public function testAPopupThatOpenedUPWARDGrowsItsPanelUpwardToo(): void
    {
        // The caret is directly under an upward popup. A panel aligned to the
        // popup's TOP would grow down across the line being typed.
        $editor = $this->editor("<?php\n\n\n\necho 'hi';\n");
        $editor->getDocument()->setCursor(4, 5);
        $editor->showCompletions([new CompletionItem(label: 'greet', insertText: 'greet', kind: 'fn', detail: '', documentation: "one\ntwo\nthree\nfour\nfive")]);

        // A pane with the caret on its last row but one: the popup opens above.
        $rows = $editor->render(new RenderContext(120, 6));
        $plain = \array_map(AnsiUtils::stripAnsiCodes(...), $rows);

        // The caret is on row 4. The room above is what caps the panel, so the
        // docblock is CUT rather than the panel sliding down over the line
        // being typed — clamping the position instead would do exactly that.
        $this->assertStringContainsString('one', $plain[1], 'the panel is there, beside the popup');
        $this->assertStringContainsString('▼', $plain[3], 'saying there is more of it');
        $this->assertStringNotContainsString('│', $plain[4], 'and it stops at the popup\'s bottom edge, above the caret row');
        $this->assertExactWidth($rows, 120);
    }

    public function testThePanelTakesTheRightHandSideWhenItJUSTFits(): void
    {
        // Both sides of the boundary, because a fit test that is one column out
        // still draws a panel — on the wrong side, or not at all — and every
        // row still measures exactly either way.
        $editor = $this->editor();
        $editor->getDocument()->setCursor(2, 5);
        $editor->showCompletions([new CompletionItem(label: 'greet', insertText: 'greet', kind: 'fn', detail: '', documentation: 'Greets somebody.')]);

        // The popup's frame ends at column 25 here and the panel needs 46 + 2,
        // so 71 is the exact width at which the right-hand side works.
        $fits = $editor->render(new RenderContext(71, 10));
        $this->assertStringContainsString('┓╭', AnsiUtils::stripAnsiCodes($fits[3]), 'flush against the popup, on the right');
        $this->assertExactWidth($fits, 71);

        $oneShort = $editor->render(new RenderContext(70, 10));
        $this->assertStringNotContainsString('Greets somebody.', \implode("\n", \array_map(AnsiUtils::stripAnsiCodes(...), $oneShort)), 'one column short and there is no room on either side');
        $this->assertExactWidth($oneShort, 70);
    }

    public function testTheDOCBLOCKIsCUTToTheRoomAboveTheCaret(): void
    {
        // The room decides the height BEFORE the text is cut. The count is the
        // assertion: a panel one row taller would sit over the caret line, and
        // one row shorter would throw away a line it had room for.
        $editor = $this->editor("<?php\n\n\n\necho 'hi';\n");
        $editor->getDocument()->setCursor(4, 5);
        $editor->showCompletions([new CompletionItem(label: 'greet', insertText: 'greet', kind: 'fn', detail: '', documentation: "one\ntwo\nthree\nfour\nfive")]);

        $plain = \array_map(AnsiUtils::stripAnsiCodes(...), $editor->render(new RenderContext(120, 6)));

        // The popup opens upward over rows 1..3, so the panel's room runs from
        // row 0 to the popup's bottom edge: two content rows between its borders.
        $this->assertStringContainsString('one', $plain[1]);
        $this->assertStringContainsString('two', $plain[2]);
        $this->assertStringNotContainsString('three', \implode("\n", $plain), 'the third line had nowhere to go');
    }

    public function testTheLEFTSideHasItsOwnBoundaryAndOneColumnDecidesIt(): void
    {
        // Found by sweeping the caret across a line of one-character words with
        // a leading space, which is what puts the anchor on an odd column: at
        // this caret the panel lands EXACTLY at column 0, and one caret earlier
        // there is no side to put it on.
        //
        // Column 0 is the case worth owning. The left placement needs a column
        // of clearance the right one does not — its shadow falls inwards — so
        // its boundary is its own, and a fixture that never reaches the edge
        // cannot tell "fits" from "fits with a column to spare".
        $fits = $this->docPopupAt(caret: 41);
        $short = $this->docPopupAt(caret: 40);

        $this->assertSame(1, \mb_strpos($fits, needle: 'DOC'), 'the panel starts at column 0, its text one in');
        $this->assertLessThan(\mb_strpos($fits, needle: '┃▌greet'), \mb_strpos($fits, needle: 'DOC'), 'left of the list');
        $this->assertStringNotContainsString('DOC', $short, 'one column short: no side to put it on');

        // And the column BELOW the boundary, which the odd grid steps over:
        // without the leading space the anchor lands on even columns, and this
        // caret is the one where the panel would begin at column -1. The guard
        // and the column it guards have to be the same subtraction — a check
        // that says "fits" about a column the placement then shifts one further
        // left writes off the start of the row.
        $this->assertStringNotContainsString('DOC', $this->docPopupAt(caret: 41, grid: ''), 'a panel at column -1 is not a panel');
    }

    /**
     * The completion row of a popup anchored at $caret on a line of
     * one-character words.
     *
     * $grid is load-bearing: a leading space shifts every word onto an odd
     * column, and the anchor moves two columns per caret — so each grid can
     * only ever reach one parity, and the boundary needs both.
     */
    private function docPopupAt(int $caret, string $grid = ' '): string
    {
        $editor = $this->editor("<?php\n\$a = 1;\n".$grid.\str_repeat('y ', times: 40)."\n");
        $editor->getDocument()->setCursor(2, $caret);
        $editor->showCompletions([new CompletionItem(label: 'greet', insertText: 'greet', kind: 'fn', detail: '', documentation: 'DOC')]);
        $rows = $editor->render(new RenderContext(100, 10));
        $this->assertExactWidth($rows, 100);

        return AnsiUtils::stripAnsiCodes($rows[4]);
    }

    public function testThePanelStopsAtTheRowsTheROOMAllows(): void
    {
        // Exactly four lines, not three and not five: the height is the room
        // less the two border rows, and an off-by-one there either throws away
        // a line it had room for or writes one past the pane.
        $editor = $this->editor();
        $editor->getDocument()->setCursor(2, 5);
        $editor->showCompletions([new CompletionItem(label: 'greet', insertText: 'greet', kind: 'fn', detail: '', documentation: "L1\nL2\nL3\nL4\nL5\nL6")]);

        $rows = $editor->render(new RenderContext(120, 9));
        $screen = \implode("\n", \array_map(AnsiUtils::stripAnsiCodes(...), $rows));

        $this->assertStringContainsString('L4', $screen);
        $this->assertStringNotContainsString('L5', $screen);
        $this->assertExactWidth($rows, 120);
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
        $editor->openPrompt('Go to line', '', static fn (): null => null);
        $editor->layoutPickers([0, 0, 20, 80]); // records the prompt box

        $host = Probe::get($editor, 'overlayHost');
        $box = Probe::get($host, 'promptBox');
        $this->assertInstanceOf(ModalBox::class, $box, 'the overlay laid out a modal box');

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
        $this->assertStringContainsString('Go to line', AnsiUtils::stripAnsiCodes(\implode("\n", $placed[0]['rows'])), 'the floating prompt renders');
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
        $editor->openSearch('Search', static fn (): null => null, static fn (): null => null, static fn (): null => null);
        $this->assertTrue($editor->movePopupSelection(1), 'the search modal consumed the wheel');
        $editor->handleSearchOverlayInput("\x1b");
    }

    public function testShowDocumentClosesTheCompletionPopup(): void
    {
        $editor = $this->editor("<?php\n\$abc\n");
        $editor->getDocument()->setCursor(1, 4); // right after "abc"
        Probe::get($editor, 'completion')->show([new CompletionItem('abcdef', 'abcdef', 'var', '')]);
        $this->assertTrue($editor->isCompletionOpen(), 'sanity: the popup is actually open before the swap');

        $editor->showDocument('other.php', new Document("<?php\n"));

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

        $editor->showDocument('other.php', new Document("<?php\n"));

        $this->assertFalse(Probe::get($editor, 'overlayHost')->anyOpen(), 'showDocument must close a list overlay left open from the old buffer');
    }

    public function testMoveSelectionWithoutCandidatesIsInert(): void
    {
        // Directly on the collaborator: an empty candidate list must not
        // divide by zero when a stray wheel reaches it.
        $completion = new EditorCompletion(
            new Document(''),
            static function (): void {},
            static function (): void {},
            static fn (): bool => true,
            static function (): void {},
            static function (): void {},
            static function (): void {},
        );
        $completion->moveSelection(1);
        $this->addToAssertionCount(1); // no crash, no invalidation needed
    }
}
