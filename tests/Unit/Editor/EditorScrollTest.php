<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\TestCase;
use Porta\Editor\Document;
use Porta\Editor\EditorScroll;
use Porta\Editor\FoldModel;

final class EditorScrollTest extends TestCase
{
    private Document $document;
    private FoldModel $foldModel;
    private EditorScroll $scroll;
    private int $invalidations = 0;

    #[\Override]
    protected function setUp(): void
    {
        $this->document = new Document(\implode("\n", \array_map(static fn (int $i): string => "line{$i}", \range(start: 0, end: 99))));
        $this->foldModel = new FoldModel($this->document);
        $this->scroll = new EditorScroll($this->document, $this->foldModel, function (): void {
            ++$this->invalidations;
        });
        $this->scroll->contentRows = 24;
    }

    /** One render pass with the caret attached, recording the caret key. */
    private function settle(): void
    {
        $this->scroll->clampToCaret(24, static fn (int $vScroll): int => 0);
    }

    public function testAWheelScrollLeavesTheCaretWhereItIs(): void
    {
        $this->scroll->scrollBy(30);

        $this->assertSame(30, $this->scroll->vScroll);
        $this->assertSame(0, $this->document->cursorLine);
        $this->assertSame(1, $this->invalidations);
    }

    public function testAWheelScrollPreservesTheSelection(): void
    {
        $this->document->setSelectionRange(0, 0, 1, 3);

        $this->scroll->scrollBy(30);

        $this->assertTrue($this->document->hasSelection());
    }

    public function testADetachedViewportSurvivesTheRenderClamp(): void
    {
        $this->settle();
        $this->scroll->scrollBy(30);

        $this->settle();

        $this->assertSame(30, $this->scroll->vScroll);
    }

    public function testACaretLineChangeSnapsTheViewportBackToTheCaret(): void
    {
        $this->settle();
        $this->scroll->scrollBy(30);
        $this->settle();

        $this->document->setCursor(1, 0);
        $this->settle();

        $this->assertSame(1, $this->scroll->vScroll);
    }

    public function testACaretColumnChangeAloneReattachesTheViewport(): void
    {
        $this->settle();
        $this->scroll->scrollBy(30);
        $this->settle();

        $this->document->setCursor(0, 3);
        $this->settle();

        $this->assertSame(0, $this->scroll->vScroll);
    }

    public function testADocumentVersionChangeAloneReattachesTheViewport(): void
    {
        $this->settle();
        $this->scroll->scrollBy(30);
        $this->settle();

        // Forward-delete at the caret keeps line AND column; only the version moves.
        $this->document->deleteForward();
        $this->settle();

        $this->assertSame(0, $this->document->cursorLine);
        $this->assertSame(0, $this->scroll->vScroll);
    }

    public function testADetachedViewportSnapsOffAFoldInteriorWithoutUnfoldingIt(): void
    {
        $this->foldModel->setRegions([[10, 40]], $this->document->version);
        $this->foldModel->foldAll();
        $this->settle();

        $this->scroll->scrollBy(20); // lands inside the folded 11..40 interior
        $this->settle();

        $this->assertSame(10, $this->scroll->vScroll);
        $this->assertSame(40, $this->foldModel->foldEndAt(10));
    }

    public function testCenterOnLineReattachesTheViewportToTheCaret(): void
    {
        $this->settle();
        $this->scroll->scrollBy(50);
        $this->settle();
        $this->assertSame(50, $this->scroll->vScroll);

        $this->scroll->centerOnLine(80);
        $this->assertSame(68, $this->scroll->vScroll); // 80 - 24/2

        // Re-attached: the next render clamp brings the line-0 caret back into view.
        $this->settle();
        $this->assertSame(0, $this->scroll->vScroll);
    }

    public function testScrollByClampsToTheDocumentEnd(): void
    {
        $this->scroll->scrollBy(1000);

        $this->assertSame(99, $this->scroll->vScroll);
    }

    public function testAOneLineDocumentCannotScrollAtAll(): void
    {
        $document = new Document('only');
        $scroll = new EditorScroll($document, new FoldModel($document), static function (): void {});

        $scroll->scrollBy(5);

        $this->assertSame(0, $scroll->vScroll);
    }

    public function testAnAttachedViewportKeepsClampingWhenTheViewportShrinks(): void
    {
        $this->document->setCursor(30, 0);
        $this->settle(); // 24 rows: vScroll lands at 7 to show line 30

        // The pane shrinks with a parked caret: an ATTACHED viewport must
        // re-clamp the caret into the smaller window (a wrongly-detached one
        // would hold vScroll and hide the caret).
        $this->scroll->clampToCaret(5, static fn (int $vScroll): int => 0);

        $this->assertSame(26, $this->scroll->vScroll);
    }

    /** @return array{int, int} the top line and how many of its rows are scrolled away */
    private function top(): array
    {
        return [$this->scroll->vScroll, $this->scroll->vScrollSegment];
    }

    /** The wheel under soft wrap: a tall line is scrolled through a row at a time, never jumped over. */
    public function testScrollRowsStepsThroughATallLineARowAtATime(): void
    {
        $this->scroll->rowsForLine = static fn (int $line): int => 1 === $line ? 3 : 1;

        $this->scroll->scrollRows(2);
        $this->assertSame([1, 1], $this->top(), 'line 0, then into line 1');
        $this->scroll->scrollRows(2);
        $this->assertSame([2, 0], $this->top(), 'its last row, then on');
        $this->scroll->scrollRows(-1);
        $this->assertSame([1, 2], $this->top(), 'back up onto its LAST row');
        $this->scroll->scrollRows(-9);
        $this->assertSame([0, 0], $this->top(), 'and no further than the top');
        $this->assertSame(0, $this->document->cursorLine, 'the caret never moved');

        $this->settle();
        $this->scroll->scrollRows(2);
        $this->settle();
        $this->assertSame([1, 1], $this->top(), 'detached: the render clamp leaves it there');
    }

    public function testScrollRowsSkipsAFoldAndStopsOnTheLastRow(): void
    {
        $this->scroll->rowsForLine = static fn (int $line): int => 99 === $line ? 2 : 1;
        $this->foldModel->setRegions([[3, 5]], $this->document->version);
        $this->document->setCursor(3, 0);
        $this->foldModel->toggle();
        $this->document->setCursor(0, 0);

        $this->scroll->scrollRows(4);
        $this->assertSame([6, 0], $this->top(), '0, 1, 2, 3 — then past the folded 4 and 5');
        $this->scroll->scrollRows(-1);
        $this->assertSame([3, 0], $this->top(), 'and back onto the fold start');

        $this->scroll->scrollRows(500);
        $this->assertSame([99, 1], $this->top(), 'the last line\'s last row, and no further');
    }

    public function testWithEveryLineOneRowScrollRowsIsScrollBy(): void
    {
        $this->scroll->scrollRows(30);

        $this->assertSame([30, 0], $this->top());
        $this->assertSame(1, $this->invalidations);
    }

    /**
     * Scrolled INTO a tall line, then that line takes fewer rows — wrap off,
     * a wider pane — or a fold takes it in: the rows scrolled away stay inside
     * the top line, so the walk and the assembler agree on what is drawn.
     */
    public function testTheRowsScrolledIntoTheTopLineNeverOutliveIt(): void
    {
        $this->settle();
        $this->scroll->rowsForLine = static fn (int $line): int => 4 === $line ? 4 : 1;
        $this->scroll->scrollRows(7);
        $this->assertSame([4, 3], $this->top(), 'four lines, then three rows into line 4');

        $this->scroll->rowsForLine = static fn (int $line): int => 4 === $line ? 2 : 1;
        $this->settle();
        $this->assertSame([4, 1], $this->top(), 'a wider pane: its last row now');

        $this->scroll->rowsForLine = null;
        $this->settle();
        $this->assertSame([4, 0], $this->top(), 'wrap off: one row, the first');

        $this->scroll->rowsForLine = static fn (int $line): int => \in_array($line, [3, 4], strict: true) ? 4 : 1;
        $this->scroll->scrollRows(2);
        $this->foldModel->setRegions([[3, 5]], $this->document->version);
        $this->document->setCursor(3, 0);
        $this->foldModel->toggle();
        $this->document->setCursor(0, 0);
        $this->settle();
        $this->assertSame([3, 0], $this->top(), 'folded into line 3: from its first row, not line 4\'s third');
    }

    /** The same when the caret moves meanwhile: the view re-attaches and still drops the stale rows. */
    public function testAReattachedViewDropsRowsTheTopLineNoLongerHas(): void
    {
        $this->settle();
        $this->scroll->rowsForLine = static fn (int $line): int => 4 === $line ? 4 : 1;
        $this->scroll->scrollRows(7);
        $this->scroll->rowsForLine = null;

        $this->document->setCursor(6, 0); // on screen, below the top
        $this->settle();

        $this->assertSame([4, 0], $this->top());
    }
}
