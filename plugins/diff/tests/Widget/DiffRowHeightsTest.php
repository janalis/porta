<?php

declare(strict_types=1);

namespace Porta\DiffPlugin\Tests\Widget;

use PHPUnit\Framework\TestCase;
use Porta\DiffPlugin\DiffDocument;
use Porta\DiffPlugin\Widget\DiffRowHeights;

final class DiffRowHeightsTest extends TestCase
{
    private const string RAW = <<<'DIFF'
        diff --git a/a.txt b/a.txt
        --- a/a.txt
        +++ b/a.txt
        @@ -1,2 +1,2 @@
         keep
        -oooooooooooooooooooooooooooooo
        +nnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnn
        DIFF;

    /** At 40 columns the unified body is 28 wide: the hunk 1, keep 1, 30 o's 2, 70 n's 3. */
    private static function wrapped(): DiffRowHeights
    {
        $document = DiffDocument::parse(self::RAW);

        return DiffRowHeights::of($document, $document->rows(), 40, true);
    }

    public function testALineTakesAsManyRowsAsItsTextBreaksInto(): void
    {
        $heights = self::wrapped();

        $this->assertSame([1, 1, 2, 3], [$heights->height(0), $heights->height(1), $heights->height(2), $heights->height(3)]);
        $this->assertSame(1, $heights->height(4), 'past the end: one');
    }

    public function testAPairIsAsTallAsItsTallerHalf(): void
    {
        $document = DiffDocument::parse(self::RAW);
        // 61 columns: each half's body 24 wide — the o's take 2, the n's 3.
        $heights = DiffRowHeights::of($document, $document->splitRows(), 61, true);

        $this->assertSame(3, $heights->height(2), 'the changed pair: the n\'s three rows');
        $this->assertSame(1, $heights->height(1), 'the context pair');
    }

    public function testAPairWithABlankHalfIsAsTallAsItsText(): void
    {
        $document = DiffDocument::parse("@@ -1,2 +1,1 @@\n-a\n-b\n+c\n");
        $heights = DiffRowHeights::of($document, $document->splitRows(), 61, true);

        $this->assertSame(1, $heights->height(2), 'b beside nothing: the blank half adds no row');
    }

    public function testUnwrappedOrWithNoDiffEveryRowIsOne(): void
    {
        $document = DiffDocument::parse(self::RAW);
        $unwrapped = DiffRowHeights::of($document, $document->rows(), 40, false);
        $this->assertSame(1, $unwrapped->height(3));
        $this->assertSame([3, 0], $unwrapped->at(0, 0, 3));

        $this->assertNull(DiffRowHeights::of(null, [], 40, true)->at(0, 0, 0), 'nothing to show');
    }

    /**
     * A too-narrow pane has no text to break: every row one, whatever the line.
     * Twelve columns leave the unified body nothing at all.
     */
    public function testAPaneWithNoRoomForTextWrapsNothing(): void
    {
        $document = DiffDocument::parse(self::RAW);

        $this->assertSame(1, DiffRowHeights::of($document, $document->rows(), 12, true)->height(3));
    }

    public function testAtWalksScreenRowsToTheRowAndSegmentTheyShow(): void
    {
        $heights = self::wrapped();

        $this->assertSame([0, 0], $heights->at(0, 0, 0));
        $this->assertSame([2, 1], $heights->at(0, 0, 3), 'the o\'s second row');
        $this->assertSame([3, 2], $heights->at(0, 0, 6), 'the n\'s last row');
        $this->assertSame([3, 0], $heights->at(2, 0, 2), 'scrolled two rows down');
        $this->assertSame([3, 1], $heights->at(3, 1, 0), 'scrolled one row into the n\'s');
        $this->assertNull($heights->at(3, 1, 2), 'past the last row, counted from inside one');
        $this->assertNull($heights->at(0, 0, 7), 'past the last row');
        $this->assertNull($heights->at(0, 0, -1), 'above the first');
    }

    public function testRevealMovesAsLittleAsShowsTheCursor(): void
    {
        $heights = self::wrapped();

        $this->assertSame([1, 0], $heights->reveal(3, 0, 1, 4), 'above: scroll up to it');
        $this->assertSame([0, 0], $heights->reveal(0, 0, 2, 4), 'rows 0–2 take four: already shown');
        $this->assertSame([2, 1], $heights->reveal(0, 0, 3, 4), 'the n\'s need three: down until their last row shows, the o\'s second above');
        $this->assertSame([3, 0], $heights->reveal(0, 0, 3, 2), 'a row taller than the pane is shown from its top');
        $this->assertSame([3, 0], $heights->reveal(3, 2, 3, 4), 'scrolled into the cursor\'s row: back to its top');
    }

    public function testScrolledMovesByScreenRowsAndStopsWhereThePaneIsFull(): void
    {
        $heights = self::wrapped();

        $this->assertSame([2, 1], $heights->scrolled(0, 0, 10, 4), 'the n\'s three rows and the o\'s last fill four');
        $this->assertSame([3, 1], $heights->scrolled(0, 0, 10, 2), 'a row taller than the pane scrolls through to its last screen row');
        $this->assertSame([3, 0], $heights->scrolled(3, 1, -1, 2), 'one row up, inside the n\'s');
        $this->assertSame([0, 0], $heights->scrolled(2, 1, -9, 4), 'never above the top');
        $this->assertSame([0, 0], $heights->scrolled(0, 0, 5, 7), 'it all fits');
        $this->assertSame([0, 0], DiffRowHeights::of(null, [], 40, true)->scrolled(0, 0, 3, 4), 'nothing shown: nothing to scroll');
    }

    public function testFittingCountsAPage(): void
    {
        $heights = self::wrapped();

        $this->assertSame(3, $heights->fitting(0, 4), 'hunk, keep and the o\'s');
        $this->assertSame(1, $heights->fitting(3, 2), 'at least one, though it does not fit');
        $this->assertSame(3, $heights->fitting(3, 5), 'past the end each row counts one');
        $this->assertSame(1, $heights->fitting(0, 0));
        $this->assertSame(1, $heights->fitting(2, 4), 'the rows BELOW the scroll: the n\'s do not fit under the o\'s');
    }
}
