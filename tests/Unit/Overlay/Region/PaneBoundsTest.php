<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Overlay\Region;

use PHPUnit\Framework\TestCase;
use Porta\Overlay\Region\PaneBounds;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\TestOverlayPane;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Render\WidgetRect;
use Symfony\Component\Tui\Style\StyleSheet;

final class PaneBoundsTest extends TestCase
{
    private function place(Renderer $renderer, TestOverlayPane $pane, int $row, int $col, int $rows, int $columns): void
    {
        Probe::object($renderer, 'positionTracker')->setWidgetRect($pane, new WidgetRect($row, $col, $columns, $rows));
    }

    public function testNoPanesReturnsNull(): void
    {
        $renderer = new Renderer(new StyleSheet());
        $this->assertNull(PaneBounds::union([], $renderer));
    }

    public function testAPaneWithNoRectYetReturnsNull(): void
    {
        $renderer = new Renderer(new StyleSheet());
        $this->assertNull(PaneBounds::union([new TestOverlayPane()], $renderer), 'no frame yet: nothing to lay out against');
    }

    public function testARectlessPaneIsSkippedNotAbortingTheUnion(): void
    {
        $renderer = new Renderer(new StyleSheet());
        $noRect = new TestOverlayPane();
        $withRect = new TestOverlayPane();
        $this->place($renderer, $withRect, row: 2, col: 3, rows: 5, columns: 10);

        // The rectless pane comes FIRST: skipping it must not abort the loop
        // before the valid one after it is reached.
        $bounds = PaneBounds::union([$noRect, $withRect], $renderer);
        $this->assertSame([2, 3, 7, 13], $bounds);
    }

    public function testAZeroHeightUnionIsNotCountedAsHavingBounds(): void
    {
        // bottom === top exactly: a degenerate (zero-height) union must not
        // count as "has bounds" (proves `>`, not `>=`).
        $renderer = new Renderer(new StyleSheet());
        $pane = new TestOverlayPane();
        $this->place($renderer, $pane, row: 5, col: 0, rows: 0, columns: 10);
        $this->assertNull(PaneBounds::union([$pane], $renderer));
    }

    public function testAZeroWidthUnionIsNotCountedAsHavingBounds(): void
    {
        // right === left exactly: a degenerate (zero-width) union must not
        // count as "has bounds" either (proves `&&`, not `||`, and `>` on the
        // right/left comparison too).
        $renderer = new Renderer(new StyleSheet());
        $pane = new TestOverlayPane();
        $this->place($renderer, $pane, row: 5, col: 3, rows: 4, columns: 0);
        $this->assertNull(PaneBounds::union([$pane], $renderer));
    }

    public function testMultiplePanesUnionToTheirOuterBounds(): void
    {
        $renderer = new Renderer(new StyleSheet());
        $a = new TestOverlayPane();
        $b = new TestOverlayPane();
        $this->place($renderer, $a, row: 2, col: 1, rows: 3, columns: 4);
        $this->place($renderer, $b, row: 0, col: 10, rows: 2, columns: 3);

        $this->assertSame([0, 1, 5, 13], PaneBounds::union([$a, $b], $renderer));
    }
}
