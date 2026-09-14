<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget;

use PHPUnit\Framework\TestCase;
use Porta\Editor\BreadcrumbScanner;
use Porta\Editor\CompletionItem;
use Porta\Editor\EditorScroll;
use Porta\Editor\LineNumberMode;
use Porta\I18n\Translator;
use Porta\Support\AnsiText;
use Porta\Support\IntMath;
use Porta\Tests\Support\FakeEditorCompletion;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\RendersExactWidth;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\EditorCallbacks;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;

/**
 * Branch coverage for CodeEditorWidget beyond the main scenarios.
 */
/**
 * The editor's geometry and render arithmetic, tested at the boundaries where
 * an off-by-one shows: screen cell <-> document line, the sticky-header offset,
 * horizontal scroll spans, gutter and content widths, and the empty state.
 *
 * Split out of CodeEditorWidgetEdgeTest, which had grown to 93 members — with
 * CodeEditorWidgetTest at 95 it pinned the too-many-methods ratchet above every
 * production class, so the rule could not bite the code it is there for (see
 * mago.toml). These are the tests that assert numbers rather than behaviour.
 */
final class CodeEditorGeometryTest extends TestCase
{
    use RendersExactWidth;

    private function editor(string $content = "<?php\nline one\nline two\n", string $path = 'edge.php'): CodeEditorWidget
    {
        $editor = new CodeEditorWidget();
        $editor->openFile($path, $content);
        $editor->setFocused(true);

        return $editor;
    }

    public function testCaretScreenCellRowFloorsAtZeroNotNegativeOne(): void
    {
        $lines = "<?php\n";
        for ($i = 0; $i < 50; ++$i) {
            $lines .= "line {$i}\n";
        }
        $editor = $this->editor($lines);
        $editor->getDocument()->setCursor(30, 0);
        $editor->render(new RenderContext(80, 5)); // settles vScroll near line 30

        // Move the cursor ABOVE the settled vScroll without re-rendering:
        // screenRowFor() returns -1 (caret above the viewport). max(0,-1)=0;
        // a max(-1,...) mutant would leave the row at -1 instead.
        $editor->getDocument()->setCursor(0, 0);

        $this->assertSame(0, $editor->caretScreenCell()['row'], 'a caret scrolled above the viewport must clamp its screen row to 0');
    }

    public function testCaretScreenCellRowAddsStickyLineCountNotSubtractsIt(): void
    {
        // A deep class scope so at least one sticky header is pinned once
        // scrolled past its opening line.
        $lines = "<?php\nclass Demo\n{\n";
        for ($i = 0; $i < 40; ++$i) {
            $lines .= "    // padding {$i}\n";
        }
        $lines .= "    public function tail(): void {}\n}\n";
        $editor = $this->editor($lines);
        $editor->getDocument()->setCursor(38, 0);
        $editor->render(new RenderContext(80, 10)); // narrow viewport forces scroll, pinning the class header sticky

        $stickyCount = \count(Probe::get($editor, 'stickyLines'));
        $this->assertGreaterThan(0, $stickyCount, 'sanity: at least one sticky header pinned');

        $scroll = Probe::get($editor, 'scroll');
        $foldModel = Probe::get($editor, 'foldModel');
        $expectedBase = \max(0, $foldModel->screenRowFor($scroll->vScroll, 38));

        $cell = $editor->caretScreenCell();
        // Exact formula: max(0, screenRowFor(...)) + stickyCount. A
        // Plus-&gt;Minus mutant would produce expectedBase - stickyCount instead.
        $this->assertSame($expectedBase + $stickyCount, $cell['row']);
    }

    public function testDocumentLineAtClampsANegativeRelativeRowToZero(): void
    {
        $editor = $this->editor("<?php\nline1\nline2\n");
        $editor->render(new RenderContext(80, 10));

        // No sticky lines here, so relRow=-1 skips the early sticky-line
        // branch (relRow &gt;= 0 is false) and falls through to the
        // max(0, relRow - stickyCount) line with a genuinely negative input.
        $this->assertSame($editor->documentLineAt(0), $editor->documentLineAt(-1), 'a negative row must clamp to row 0, not go further negative');
    }

    public function testDocumentLineAtAtExactlyTheStickyCountFloorsAtZeroNotOne(): void
    {
        $lines = "<?php\nclass Demo\n{\n";
        for ($i = 0; $i < 40; ++$i) {
            $lines .= "    // padding {$i}\n";
        }
        $lines .= "    public function tail(): void {}\n}\n";
        $editor = $this->editor($lines);
        $editor->getDocument()->setCursor(38, 0);
        $editor->render(new RenderContext(80, 10));

        $stickyCount = \count(Probe::get($editor, 'stickyLines'));
        $this->assertGreaterThan(0, $stickyCount);

        $scroll = Probe::get($editor, 'scroll');
        $foldModel = Probe::get($editor, 'foldModel');
        $expected = $foldModel->docLineAtRow($scroll->vScroll, 0);

        // relRow == stickyCount: not caught by the early sticky-line branch
        // (relRow &lt; stickyCount is false), so relRow-stickyCount == 0 exactly.
        // An IncrementInteger mutant (floor 1) would ask docLineAtRow for row 1
        // instead of row 0, a DecrementInteger mutant for row -1.
        $this->assertSame($expected, $editor->documentLineAt($stickyCount));
    }

    // --- centerOnLine (stripe click) -------------------------------------------

    public function testStripeClickCentersTheViewportOnTheClickedLine(): void
    {
        $lines = "<?php\n";
        for ($i = 0; $i < 100; ++$i) {
            $lines .= "line {$i}\n";
        }
        $editor = $this->editor($lines);
        $editor->render(new RenderContext(40, 10));
        $scroll = Probe::get($editor, 'scroll');
        $before = $scroll->vScroll;

        // The stripe is the rightmost rendered column.
        $editor->clickAt(5, 39);

        $this->assertNotSame($before, $scroll->vScroll, 'a stripe click must actually move the viewport (centerOnLine wired through)');
    }

    // --- scrollHBy widest-visible-line scan boundary ---------------------------

    public function testScrollHByScansExactlyTheVisibleLinesNotOneShortOrOneOver(): void
    {
        // 3 visible content rows (lines 0-2): "<?php" (5), 20 a's, 50 b's — the
        // widest visible line is 50. A 4th, off-screen line (200 c's) must
        // NOT be counted, and dropping the last visible line from the scan
        // must not either (both would change the exact clamp target).
        $editor = $this->editor("<?php\n".\str_repeat('a', times: 20)."\n".\str_repeat('b', times: 50)."\n".\str_repeat('c', times: 200)."\n");
        $editor->render(new RenderContext(80, 3));

        $editor->scrollHBy(1000); // scroll far right; clamp caps at (widest VISIBLE line - 10)
        $scroll = Probe::get($editor, 'scroll');
        $this->assertSame(40, $scroll->hScroll, 'the clamp must be driven by exactly the 3 visible lines (widest 50), not a truncated or over-extended scan');
    }

    public function testScrollHByLastVisibleLineBoundaryUsesLineCountNotContentRowsWhenTheDocumentIsShort(): void
    {
        // A short, 2-line document (no trailing newline, so the widest line
        // IS the document's true last line) in a tall viewport: the document
        // itself, not the viewport, is the binding constraint on the scan's
        // upper bound. An off-by-one that shortens the range excludes this
        // widest line entirely.
        $editor = $this->editor("<?php\n".\str_repeat('x', times: 100));
        $editor->render(new RenderContext(80, 10));

        $editor->scrollHBy(1000);
        $scroll = Probe::get($editor, 'scroll');
        $this->assertSame(90, $scroll->hScroll, 'the scan must reach the last real document line (widest 100), not stop one short of it');
    }

    // --- insertText / jumpTo side effects ---------------------------------------

    public function testInsertTextInvalidates(): void
    {
        $editor = $this->editor();
        $before = $editor->getRenderRevision();

        $editor->insertText('x');

        $this->assertGreaterThan($before, $editor->getRenderRevision());
    }

    public function testJumpToCentersScrollsAndClosesCompletion(): void
    {
        $lines = "<?php\n";
        for ($i = 0; $i < 100; ++$i) {
            $lines .= "line {$i}\n";
        }
        $editor = $this->editor($lines);
        $editor->render(new RenderContext(40, 10));
        $editor->showCompletions([new CompletionItem(label: 'foo', insertText: 'foo', kind: 'fn', detail: '')]);
        $this->assertTrue($editor->isCompletionOpen(), 'sanity: completion open');
        $scroll = Probe::get($editor, 'scroll');
        $before = $scroll->vScroll;
        $beforeRev = $editor->getRenderRevision();

        $editor->jumpTo(80, 0);

        $this->assertNotSame($before, $scroll->vScroll, 'jumpTo must center the target line');
        $this->assertFalse($editor->isCompletionOpen(), 'jumpTo must close any open completion popup');
        $this->assertGreaterThan($beforeRev, $editor->getRenderRevision(), 'jumpTo must invalidate');
    }

    // --- clearExtraCarets / notifyChange pass-throughs -------------------------
    // (closeCompletion() itself is covered by testJumpToCentersScrollsAndClosesCompletion above.)

    public function testClearExtraCaretsActuallyClearsThem(): void
    {
        $editor = $this->editor("<?php\nline1\nline2\n");
        $mc = Probe::get($editor, 'multiCaret');
        $mc->toggleCaretAt(2, 0);
        $this->assertTrue($mc->hasMultiple());

        $editor->clearExtraCarets();

        $this->assertFalse($mc->hasMultiple());
    }

    public function testNotifyChangeInvalidatesAndSchedulesDidChange(): void
    {
        $editor = $this->editor();
        $before = $editor->getRenderRevision();
        $scheduled = false;
        $editor->setCallbacks(new EditorCallbacks(completion: new FakeEditorCompletion(
            scheduleDidChange: static function () use (&$scheduled): void {
                $scheduled = true;
            },
        )));

        $editor->notifyChange();

        $this->assertGreaterThan($before, $editor->getRenderRevision(), 'notifyChange must invalidate');
        $this->assertTrue($scheduled, 'notifyChange must schedule didChange');
    }

    // --- stickyHeadersAt boundary math ------------------------------------------

    public function testStickyHeadersAtUsesTheExactRowsMinusFourBudget(): void
    {
        // A deeply nested set of scopes so MAX_STICKY_ROWS would cap it if the
        // budget were larger than intended; here we want rows-4 itself to be
        // the binding constraint, distinguishing it from rows-3 or rows-5.
        $lines = "<?php\n";
        for ($depth = 0; $depth < 6; ++$depth) {
            $lines .= \str_repeat('    ', $depth)."function f{$depth}()\n".\str_repeat('    ', $depth)."{\n";
        }
        for ($depth = 5; $depth >= 0; --$depth) {
            $lines .= \str_repeat('    ', $depth)."}\n";
        }
        $editor = $this->editor($lines);
        // Deep inside the innermost scope, well past every opening line.
        $doc = $editor->getDocument();
        $innerLine = null;
        for ($i = 0; $i < $doc->getLineCount(); ++$i) {
            if (!\str_contains($doc->getLine($i), 'function f5')) {
                continue;
            }
            $innerLine = $i + 1;

            break;
        }
        $this->assertNotNull($innerLine);
        $doc->setCursor($innerLine, 0);

        foreach ([7, 6] as $rows) {
            $editor->render(new RenderContext(80, $rows));
            $scroll = Probe::get($editor, 'scroll');
            $foldModel = Probe::get($editor, 'foldModel');
            $top = $foldModel->docLineAtRow($scroll->vScroll, 0) ?? 0;
            $budget = IntMath::clamp($rows - 4, 0, EditorScroll::MAX_STICKY_ROWS);
            $expected = BreadcrumbScanner::stickyHeaders($editor->scopes(), $top, $budget);

            $actual = Probe::get($editor, 'stickyLines');
            $this->assertSame(\array_map(static fn (array $h): int => $h['line'], $expected), $actual, "rows={$rows}");
        }
    }

    // --- matchingBrackets memoization key ---------------------------------------

    public function testMatchingBracketsRecomputesOnCursorLineChangeNotJustColumn(): void
    {
        $editor = $this->editor("<?php\nfunction a() {}\nfunction b() {}\n");
        $editor->setFocused(true);
        $editor->getDocument()->setCursor(1, 13); // inside a()'s braces
        $editor->render(new RenderContext(80, 10));
        $first = Probe::get($editor, 'bracketsMemo');

        $editor->getDocument()->setCursor(2, 13); // same column, different line, inside b()'s braces
        $editor->render(new RenderContext(80, 10));
        $second = Probe::get($editor, 'bracketsMemo');

        $this->assertNotSame($first, $second, 'the memo key must include the cursor LINE, not just column and version');
    }

    public function testMatchingBracketsRecomputesOnVersionChangeAtTheSameCursorPosition(): void
    {
        $editor = $this->editor("<?php\nfunction a() {}\n");
        $editor->setFocused(true);
        $editor->getDocument()->setCursor(1, 13);
        $editor->render(new RenderContext(80, 10));
        $first = Probe::get($editor, 'bracketsMemo');

        $editor->getDocument()->insert(' '); // bumps version; cursor moves to col 14 though — restore it
        $editor->getDocument()->setCursor(1, 13);
        $editor->render(new RenderContext(80, 10));
        $second = Probe::get($editor, 'bracketsMemo');

        $this->assertNotSame($first, $second, 'an edit must invalidate the memo even at the same (line, col)');
    }

    // --- render(): rows floor, empty-state gutter reset, contentRows, lastVisibleDocLine ---

    public function testRenderRowsWithNoRequestedRowsFallsBackToLineCountCappedAtThirty(): void
    {
        // A 50-line document, getRows()=0: rows = max(1, 0&gt;0 ? 0 : min(30,50)).
        // A GreaterThan mutant (&gt;=0) would take the getRows()=0 branch directly,
        // collapsing this to 1; min(29,...)/min(31,...) mutants would collapse
        // the cap to 29 or 31 instead of exactly 30.
        $lines = "<?php\n";
        for ($i = 0; $i < 49; ++$i) {
            $lines .= "line {$i}\n";
        }
        $editor = $this->editor($lines);

        $rendered = $editor->render(new RenderContext(80, 0));

        $this->assertCount(30, $rendered);
    }

    public function testRenderResetsGutterWidthToZeroNotNegativeOneWhenNoFileIsOpen(): void
    {
        $editor = new CodeEditorWidget();
        $editor->render(new RenderContext(80, 10)); // no file open

        $this->assertSame(0, $editor->gutterWidth());
    }

    public function testRenderSetsContentRowsOnTheScroll(): void
    {
        $editor = $this->editor("<?php\nline1\nline2\n");
        $editor->render(new RenderContext(80, 10));

        $this->assertSame($editor->contentRows(), Probe::get($editor, 'scroll')->contentRows);
        $this->assertGreaterThan(0, $editor->contentRows(), 'setContentRows must actually have run, not left the default');
    }

    public function testRenderLastVisibleDocLineFallsBackToLineCountMinusOneAtEndOfDocument(): void
    {
        $editor = $this->editor("<?php\nline1\nline2\nline3\n");
        // A tall viewport that shows the whole (short) document: docLineAtRow
        // for the last content row returns null (past the end), so the ??
        // fallback (lineCount - 1) is exercised.
        $rendered = $editor->render(new RenderContext(80, 50));

        // The bottom rows beyond the document must render as blank gutter
        // rows, not throw / not include a bogus diagnostic for a
        // past-the-end line — a structural smoke check on the fallback path.
        $this->assertCount(50, $rendered);
    }

    // --- computeGeometry boundaries ----------------------------------------

    public function testComputeGeometryNumberWidthIsZeroWhenLineNumbersAreOff(): void
    {
        $editor = $this->editor("<?php\n");
        $editor->display->setLineNumbers(LineNumberMode::Off);
        $editor->setVcsGutter(false);

        $editor->render(new RenderContext(80, 5));

        // With line numbers off and vcs gutter off, gutterWidth is exactly
        // numberWidth(0) + marker + fold + space = 3. An IncrementInteger mutant
        // (numberWidth=1) would make it 4.
        $this->assertSame(3, $editor->gutterWidth());
    }

    public function testComputeGeometryGutterWidthIncludesTheVcsColumnWhenEnabled(): void
    {
        $editor = $this->editor("<?php\n");
        $editor->display->setLineNumbers(LineNumberMode::Off);
        $editor->setVcsGutter(false);
        $editor->render(new RenderContext(80, 5));
        $without = $editor->gutterWidth();

        $editor->setVcsGutter(true);
        $editor->render(new RenderContext(80, 5));
        $with = $editor->gutterWidth();

        $this->assertSame($without + 1, $with, 'the vcs gutter column must add exactly 1, not 0 or 2');
    }

    public function testAPaneTooNarrowForAGutterGetsNONERatherThanAnOversizedRow(): void
    {
        // This test used to assert that contentWidth FLOORS at one — which was
        // the defect, not the contract. The gutter had no matching ceiling, so
        // a one-column pane produced `gutter + 1` columns of row and the
        // renderer threw: twenty-one RenderExceptions in `.porta/error.log`.
        //
        // A row is `gutter + content + stripe`. The gutter is a fixed run of
        // single-column cells and cannot be cut through a marker, so below the
        // width that seats one it goes entirely and the text keeps the pane.
        $editor = $this->editor("<?php\n");

        $rendered = $editor->render(new RenderContext(1, 5));

        $this->assertSame(0, $editor->gutterWidth(), 'no room for a gutter');
        $contentWidth = Probe::get($editor, 'lastStripeCol') - $editor->gutterWidth();
        $this->assertSame(0, $contentWidth, 'nor for text; the stripe is the whole row');
        $this->assertExactWidth($rendered, 1);
    }

    public function testTheFirstWidthThatSEATSAGutterAlsoSeatsText(): void
    {
        // The rule, stated as an invariant rather than at a width I worked out
        // by hand — the gutter's own width depends on the line count and on
        // whether the vcs strip is on, so a hard-coded column is a guess that
        // stops discriminating the moment either changes.
        //
        // A gutter that appears one column too early fits the pane perfectly
        // well and shows a line number with no file beside it. The pane is for
        // the file.
        $editor = $this->editor("<?php\n\$distinctive = 1;\n");

        $seatsAGutter = null;
        foreach (\range(start: 1, end: 40) as $columns) {
            $editor->render(new RenderContext($columns, 4));
            if ($editor->gutterWidth() > 0) {
                $seatsAGutter = $columns;

                break;
            }
        }

        $this->assertNotNull($seatsAGutter, 'the gutter comes back at some width');
        $contentWidth = Probe::get($editor, 'lastStripeCol') - $editor->gutterWidth();
        $this->assertGreaterThanOrEqual(1, $contentWidth, 'and never before there is room for text too');

        // One column narrower: no gutter, and the row is all file.
        $rendered = $editor->render(new RenderContext($seatsAGutter - 1, 4));
        $this->assertSame(0, $editor->gutterWidth());
        // Which slice of the line shows depends on the horizontal scroll, so
        // assert that the row carries the FILE rather than a particular window
        // into it.
        $this->assertStringContainsString('php', AnsiUtils::stripAnsiCodes($rendered[0]));
        $this->assertExactWidth($rendered, $seatsAGutter - 1);
    }

    public function testTheGutterCOMESBACKAsSoonAsThePaneCanSeatIt(): void
    {
        // The other side of the all-or-nothing rule: this must not be a
        // one-way trip into a gutterless editor.
        $editor = $this->editor("<?php\n");

        $editor->render(new RenderContext(1, 5));
        $this->assertSame(0, $editor->gutterWidth());

        $rendered = $editor->render(new RenderContext(80, 5));

        $this->assertGreaterThan(0, $editor->gutterWidth(), 'the gutter returns with the room');
        $this->assertExactWidth($rendered, 80);
    }

    // --- followCaret inlay shift direction --------------------------------------

    public function testFollowCaretAddsTheInlayShiftNotSubtractsIt(): void
    {
        $editor = $this->editor("<?php\n\$x = foo();\n");
        $editor->getDocument()->setCursor(1, 11); // just after "foo()"
        $editor->decorations->setInlayHints([1 => [['col' => 11, 'text' => ': int']]]);

        $editor->render(new RenderContext(80, 10));

        // With the shift added, the caret's followed display column sits
        // past the raw column by the inlay hint's width; with it subtracted,
        // it would sit BEFORE the raw column instead. Assert via the
        // horizontal scroll needed to keep a caret placed right at the
        // narrow edge in view.
        $narrow = $this->editor("<?php\n\$x = foo();\n");
        $narrow->getDocument()->setCursor(1, 11);
        $narrow->decorations->setInlayHints([1 => [['col' => 11, 'text' => ': int']]]);
        $narrow->render(new RenderContext(15, 10));
        $scroll = Probe::get($narrow, 'scroll');

        $this->assertGreaterThan(0, $scroll->hScroll, 'the inlay hint widens the followed column, forcing a scroll in a narrow pane');
    }

    // --- renderEmptyState centering math -----------------------------------

    public function testRenderEmptyStateCentersTheHintHorizontallyFlooringAtZero(): void
    {
        $editor = new CodeEditorWidget();
        $translator = new Translator('en');
        $hint = $translator->trans('No file open · press %key% to open a file', ['%key%' => 'Ctrl+Shift+N']);
        $text = AnsiText::truncate($hint, 200);
        $textWidth = AnsiUtils::visibleWidth($text);
        $expectedLeft = \max(0, \intdiv(200 - $textWidth, num2: 2));

        $rendered = $editor->render(new RenderContext(200, 5));
        $hintRow = null;
        foreach ($rendered as $row) {
            $plain = AnsiUtils::stripAnsiCodes($row);
            if (\str_contains($plain, 'No file open')) {
                $hintRow = $plain;
                break;
            }
        }
        $this->assertNotNull($hintRow);
        $leadingSpaces = \strlen($hintRow) - \strlen(\ltrim($hintRow, characters: ' '));
        // Exact leading-space count pins the divisor (2, not 1 or 3) and the
        // floor (0, not -1 — unreachable here since truncate() guarantees
        // textWidth &lt;= columns, but pinned for documentation).
        $this->assertSame($expectedLeft, $leadingSpaces);
    }

    public function testRenderEmptyStatePutsTheHintOnTheExactMiddleRow(): void
    {
        $editor = new CodeEditorWidget();

        $rendered = $editor->render(new RenderContext(80, 5)); // middle = intdiv(5,2) = 2
        $middleHasHint = \str_contains(AnsiUtils::stripAnsiCodes($rendered[2]), 'No file open');
        $otherRowsBlank =
            !\str_contains(AnsiUtils::stripAnsiCodes($rendered[0]), 'No file open')
            && !\str_contains(AnsiUtils::stripAnsiCodes($rendered[1]), 'No file open')
            && !\str_contains(AnsiUtils::stripAnsiCodes($rendered[3]), 'No file open')
            && !\str_contains(AnsiUtils::stripAnsiCodes($rendered[4]), 'No file open');

        $this->assertTrue($middleHasHint, 'the hint must land on row intdiv(rows,2), row 2 of 5');
        $this->assertTrue($otherRowsBlank, 'every other row must be blank, not the hint (rules out the ternary being flipped)');
    }
}
