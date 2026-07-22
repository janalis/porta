<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Overlay;

use PHPUnit\Framework\TestCase;
use Porta\Overlay\SpanSlicer;
use Symfony\Component\Tui\Render\WidgetRect;

/**
 * The shared absolute-rows → widget-local spans math (extracted from the menu
 * overlay controller): row windowing, column clipping, and the no-overlap case.
 */
final class SpanSlicerTest extends TestCase
{
    public function testFullyContainedPanelKeepsItsSegmentsVerbatim(): void
    {
        $rect = new WidgetRect(row: 2, col: 5, rows: 10, columns: 40);

        $spans = SpanSlicer::localSpans(['AAAA', 'BBBB'], $rect, top: 4, anchor: 10, panelWidth: 4);

        $this->assertSame(
            [
                ['row' => 2, 'col' => 5, 'width' => 4, 'segment' => 'AAAA'],
                ['row' => 3, 'col' => 5, 'width' => 4, 'segment' => 'BBBB'],
            ],
            $spans,
        );
    }

    public function testRowsOutsideTheWidgetAreDropped(): void
    {
        $rect = new WidgetRect(row: 5, col: 0, rows: 2, columns: 20);

        // Panel rows land on screen rows 4..7; only 5 and 6 are inside.
        $spans = SpanSlicer::localSpans(['A', 'B', 'C', 'D'], $rect, top: 4, anchor: 0, panelWidth: 1);

        $this->assertSame(['B', 'C'], array_column($spans, 'segment'));
        $this->assertSame([0, 1], array_column($spans, 'row'));
    }

    public function testAPanelSpillingPastTheWidgetIsClipped(): void
    {
        $rect = new WidgetRect(row: 0, col: 0, rows: 5, columns: 6);

        // Panel [4, 12) over a 6-column widget → visible slice [4, 6), 2 wide.
        $spans = SpanSlicer::localSpans(['ABCDEFGH'], $rect, top: 0, anchor: 4, panelWidth: 8);

        $this->assertSame([['row' => 0, 'col' => 4, 'width' => 2, 'segment' => 'AB']], $spans);
    }

    public function testAPanelStartingBeforeTheWidgetClipsItsHead(): void
    {
        $rect = new WidgetRect(row: 0, col: 10, rows: 5, columns: 20);

        // Panel [6, 14) → the widget sees columns [10, 14): offset 4, width 4.
        $spans = SpanSlicer::localSpans(['ABCDEFGH'], $rect, top: 0, anchor: 6, panelWidth: 8);

        $this->assertSame([['row' => 0, 'col' => 0, 'width' => 4, 'segment' => 'EFGH']], $spans);
    }

    public function testANonOverlappingPanelYieldsNoSpans(): void
    {
        $rect = new WidgetRect(row: 0, col: 0, rows: 5, columns: 10);

        $this->assertSame([], SpanSlicer::localSpans(['AAAA'], $rect, top: 0, anchor: 20, panelWidth: 4));
    }
}
