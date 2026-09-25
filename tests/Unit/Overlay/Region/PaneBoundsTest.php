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
use Symfony\Component\Tui\Widget\ContainerWidget;

final class PaneBoundsTest extends TestCase
{
    /** The screen the panes are drawn on: the root every frame rewrites to its size. */
    private ?ContainerWidget $root = null;

    /** Put $pane on a 100x40 screen at the given rect, as a frame would have. */
    private function place(Renderer $renderer, TestOverlayPane $pane, int $row, int $col, int $rows, int $columns): void
    {
        $this->root ??= new ContainerWidget();
        $this->root->add($pane);
        Probe::object($renderer, 'positionTracker')->setWidgetRect($this->root, new WidgetRect(0, 0, 100, 40));
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

    /**
     * The renderer keeps a detached pane's rect — the tree a narrow layout
     * took out — and it describes the screen it was last drawn on: it counts
     * for nothing.
     */
    public function testADetachedPaneIsNotCounted(): void
    {
        $renderer = new Renderer(new StyleSheet());
        $live = new TestOverlayPane();
        $detached = new TestOverlayPane();
        $this->place($renderer, $live, row: 1, col: 0, rows: 9, columns: 60);
        $this->place($renderer, $detached, row: 2, col: 0, rows: 26, columns: 30);
        $this->root?->remove($detached);

        $this->assertSame([1, 0, 10, 60], PaneBounds::union([$live, $detached], $renderer));
    }

    /**
     * A pane that drew no rows keeps the rect of the last frame it did, which
     * after a shrink lies off the new screen: clipped to the screen the last
     * frame drew, it is nothing — and one only partly off is cut to it.
     */
    public function testARectFromALargerScreenIsClippedToTheCurrentOne(): void
    {
        $renderer = new Renderer(new StyleSheet());
        $editor = new TestOverlayPane();
        $rule = new TestOverlayPane();
        $top = new TestOverlayPane();
        $dock = new TestOverlayPane();
        $this->place($renderer, $editor, row: 1, col: 0, rows: 9, columns: 60);
        $this->place($renderer, $dock, row: 1, col: 80, rows: 20, columns: 20);
        $this->place($renderer, $rule, row: 28, col: 0, rows: 1, columns: 100);
        $this->place($renderer, $top, row: 0, col: 0, rows: 1, columns: 100);
        Probe::object($renderer, 'positionTracker')->setWidgetRect($this->root, new WidgetRect(0, 0, 60, 12));

        $this->assertSame(
            [0, 0, 10, 60],
            PaneBounds::union([$editor, $rule, $top, $dock], $renderer),
            'the rule below the screen and the dock right of it are nothing; the top one is cut to 60 columns',
        );
    }

    public function testAPaneOfARootNeverDrawnIsNotCounted(): void
    {
        $renderer = new Renderer(new StyleSheet());
        $pane = new TestOverlayPane();
        new ContainerWidget()->add($pane);
        Probe::object($renderer, 'positionTracker')->setWidgetRect($pane, new WidgetRect(1, 0, 10, 5));

        $this->assertNull(PaneBounds::union([$pane], $renderer));
    }

    /**
     * A pane is clipped to the screen — its root's rect — not to its parent's:
     * a container the last frame skipped keeps a rect as stale as the pane's.
     */
    public function testANestedPaneIsClippedToTheScreenNotToItsParent(): void
    {
        $renderer = new Renderer(new StyleSheet());
        $root = new ContainerWidget();
        $group = new ContainerWidget();
        $pane = new TestOverlayPane();
        $root->add($group);
        $group->add($pane);
        $tracker = Probe::object($renderer, 'positionTracker');
        $tracker->setWidgetRect($root, new WidgetRect(0, 0, 60, 12));
        $tracker->setWidgetRect($group, new WidgetRect(0, 0, 100, 40));
        $tracker->setWidgetRect($pane, new WidgetRect(28, 0, 100, 1));

        $this->assertNull(PaneBounds::union([$pane], $renderer));
    }
}
