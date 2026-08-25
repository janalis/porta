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
        $this->document = new Document(implode("\n", array_map(static fn (int $i): string => "line{$i}", range(0, 99))));
        $this->foldModel = new FoldModel($this->document);
        $this->scroll = new EditorScroll($this->document, $this->foldModel, function (): void {
            ++$this->invalidations;
        });
        $this->scroll->setContentRows(24);
    }

    /** One render pass with the caret attached, recording the caret key. */
    private function settle(): void
    {
        $this->scroll->clampToCaret(24, static fn (int $vScroll): int => 0);
    }

    public function testAWheelScrollLeavesTheCaretWhereItIs(): void
    {
        $this->scroll->scrollBy(30);

        $this->assertSame(30, $this->scroll->vScroll());
        $this->assertSame(0, $this->document->getCursorLine());
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

        $this->assertSame(30, $this->scroll->vScroll());
    }

    public function testACaretLineChangeSnapsTheViewportBackToTheCaret(): void
    {
        $this->settle();
        $this->scroll->scrollBy(30);
        $this->settle();

        $this->document->setCursor(1, 0);
        $this->settle();

        $this->assertSame(1, $this->scroll->vScroll());
    }

    public function testACaretColumnChangeAloneReattachesTheViewport(): void
    {
        $this->settle();
        $this->scroll->scrollBy(30);
        $this->settle();

        $this->document->setCursor(0, 3);
        $this->settle();

        $this->assertSame(0, $this->scroll->vScroll());
    }

    public function testADocumentVersionChangeAloneReattachesTheViewport(): void
    {
        $this->settle();
        $this->scroll->scrollBy(30);
        $this->settle();

        // Forward-delete at the caret keeps line AND column; only the version moves.
        $this->document->deleteForward();
        $this->settle();

        $this->assertSame(0, $this->document->getCursorLine());
        $this->assertSame(0, $this->scroll->vScroll());
    }

    public function testADetachedViewportSnapsOffAFoldInteriorWithoutUnfoldingIt(): void
    {
        $this->foldModel->setRegions([[10, 40]], $this->document->getVersion());
        $this->foldModel->foldAll();
        $this->settle();

        $this->scroll->scrollBy(20); // lands inside the folded 11..40 interior
        $this->settle();

        $this->assertSame(10, $this->scroll->vScroll());
        $this->assertSame(40, $this->foldModel->foldEndAt(10));
    }

    public function testCenterOnLineReattachesTheViewportToTheCaret(): void
    {
        $this->settle();
        $this->scroll->scrollBy(50);
        $this->settle();
        $this->assertSame(50, $this->scroll->vScroll());

        $this->scroll->centerOnLine(80);
        $this->assertSame(68, $this->scroll->vScroll()); // 80 - 24/2

        // Re-attached: the next render clamp brings the line-0 caret back into view.
        $this->settle();
        $this->assertSame(0, $this->scroll->vScroll());
    }

    public function testScrollByClampsToTheDocumentEnd(): void
    {
        $this->scroll->scrollBy(1000);

        $this->assertSame(99, $this->scroll->vScroll());
    }

    public function testAOneLineDocumentCannotScrollAtAll(): void
    {
        $document = new Document('only');
        $scroll = new EditorScroll($document, new FoldModel($document), static function (): void {});

        $scroll->scrollBy(5);

        $this->assertSame(0, $scroll->vScroll());
    }

    public function testAnAttachedViewportKeepsClampingWhenTheViewportShrinks(): void
    {
        $this->document->setCursor(30, 0);
        $this->settle(); // 24 rows: vScroll lands at 7 to show line 30

        // The pane shrinks with a parked caret: an ATTACHED viewport must
        // re-clamp the caret into the smaller window (a wrongly-detached one
        // would hold vScroll and hide the caret).
        $this->scroll->clampToCaret(5, static fn (int $vScroll): int => 0);

        $this->assertSame(26, $this->scroll->vScroll());
    }
}
