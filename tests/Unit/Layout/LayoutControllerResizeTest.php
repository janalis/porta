<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Layout;

use PHPUnit\Framework\TestCase;
use Porta\Input\MouseEvent;
use Porta\Tests\Support\BuildsLayoutController;
use Porta\Tests\Support\Probe;
use Porta\Widget\TerminalPanelWidget;
use Symfony\Component\Tui\Render\WidgetRect;

/**
 * The sidebar resize drags, in both writing directions. RTL mirrors the pane
 * order ({@see \Porta\Layout\PaneLayout::order()}), so the sidebar sits on the
 * right, its separator is the column before it, and the drag grows the pane
 * leftward — the mirror image of every assertion below.
 */
final class LayoutControllerResizeTest extends TestCase
{
    use BuildsLayoutController;

    private function press(int $col): MouseEvent
    {
        $event = MouseEvent::parse(\sprintf("\x1b[<0;%d;%dM", $col + 1, 6));
        $this->assertInstanceOf(MouseEvent::class, $event);

        return $event;
    }

    private function drag(int $col): MouseEvent
    {
        $event = MouseEvent::parse(\sprintf("\x1b[<32;%d;%dM", $col + 1, 6));
        $this->assertInstanceOf(MouseEvent::class, $event);

        return $event;
    }

    private function release(int $col): MouseEvent
    {
        $event = MouseEvent::parse(\sprintf("\x1b[<0;%d;%dm", $col + 1, 6));
        $this->assertInstanceOf(MouseEvent::class, $event);

        return $event;
    }

    public function testTheTreeSeparatorArmsAndResizesLeftToRight(): void
    {
        $layout = $this->controller();
        // The tree owns columns 0..19; its separator is the column past it.
        $separator = new WidgetRect(row: 0, col: 20, columns: 1, rows: 30);
        $tree = new WidgetRect(row: 0, col: 0, columns: 20, rows: 30);

        $this->assertFalse($layout->armSidebarResize($separator, $this->press(19)), 'a press inside the tree is not a resize');
        $this->assertTrue($layout->armSidebarResize($separator, $this->press(20)), 'the separator column arms the drag');

        $this->assertTrue($layout->continueResizeDrag($this->drag(30), $tree), 'the tree drag consumed the event');
        $this->assertSame(30, $this->tree->width(), 'dragging right widens the tree to the pointer');
    }

    public function testTheTreeSeparatorArmsAndResizesRightToLeftUnderRtl(): void
    {
        $layout = $this->controller('he');
        // Mirrored: the tree owns columns 80..99, so its separator is column 79
        // and the pane grows leftward as the pointer moves away from the edge.
        $separator = new WidgetRect(row: 0, col: 79, columns: 1, rows: 30);
        $tree = new WidgetRect(row: 0, col: 80, columns: 20, rows: 30);

        $this->assertTrue($layout->armSidebarResize($separator, $this->press(79)), 'the mirrored separator column arms the drag');

        $layout->continueResizeDrag($this->drag(70), $tree);
        $this->assertSame(30, $this->tree->width(), 'dragging left widens the mirrored tree to the pointer');
    }

    public function testTheGitSidebarDragIsMirroredToo(): void
    {
        $layout = $this->controller('he');
        $separator = new WidgetRect(row: 0, col: 59, columns: 1, rows: 30);
        $left = new WidgetRect(row: 0, col: 60, columns: 40, rows: 30);

        $this->assertTrue($layout->armViewSidebarResize('git', $separator, $this->press(59)));

        $this->assertTrue($layout->continueResizeDrag($this->drag(50), null, $left), 'the view-sidebar drag consumed the event');
        $this->assertSame(50, $this->gitSidebar->width(), 'the git sidebar grows leftward under RTL');
    }

    public function testTheSettingsSidebarDragIsMirroredToo(): void
    {
        $layout = $this->controller('he');
        $separator = new WidgetRect(row: 0, col: 69, columns: 1, rows: 30);
        $left = new WidgetRect(row: 0, col: 70, columns: 30, rows: 30);

        $this->assertTrue($layout->armViewSidebarResize('settings', $separator, $this->press(69)));

        $layout->continueResizeDrag($this->drag(60), null, $left);
        $this->assertSame(40, $this->settingsSidebar->width(), 'the settings sidebar grows leftward under RTL');
    }

    public function testContinueResizeDragIsInertWhenNothingIsArmed(): void
    {
        $layout = $this->controller();
        $tree = new WidgetRect(row: 0, col: 0, columns: 20, rows: 30);

        $this->assertFalse($layout->continueResizeDrag($this->drag(50), $tree), 'no sidebar is armed, so nothing consumes the drag');
        $this->assertSame(30, $this->tree->width(), 'and nothing moved from the default width (a drag to col 50 would have set it to 50)');
    }

    public function testAViewSidebarThatDoesNotConsumeTheEventDoesNotShortCircuitTheLoop(): void
    {
        // The git sidebar is armed (so $this->viewResizes is non-empty and the
        // loop body actually runs), but a bare press is neither a drag nor a
        // release, so continueSidebarResize itself returns false for it — the
        // loop must fall through to "nothing consumed this", not short-circuit
        // to true just because a view resize exists.
        $layout = $this->controller();
        $separator = new WidgetRect(row: 0, col: 59, columns: 1, rows: 30);
        $this->assertTrue($layout->armViewSidebarResize('git', $separator, $this->press(59)));

        $this->assertFalse($layout->continueResizeDrag($this->press(70), null, new WidgetRect(row: 0, col: 60, columns: 40, rows: 30)));
    }

    public function testAPlainPressWhileArmedDoesNotResize(): void
    {
        $layout = $this->controller();
        $separator = new WidgetRect(row: 0, col: 20, columns: 1, rows: 30);
        $tree = new WidgetRect(row: 0, col: 0, columns: 20, rows: 30);

        $this->assertTrue($layout->armSidebarResize($separator, $this->press(20)));
        // A press (not a drag/release) while armed must not itself resize —
        // only motion (drag) or the terminating release do.
        $this->assertFalse($layout->continueResizeDrag($this->press(50), $tree), 'a bare press does not consume the drag');
        $this->assertSame(30, $this->tree->width(), 'and does not resize either (a drag/release to col 50 would have set it to 50)');
    }

    public function testReleaseCommitsTheWidthPersistsAndEndsTheDrag(): void
    {
        $layout = $this->controller();
        $separator = new WidgetRect(row: 0, col: 20, columns: 1, rows: 30);
        $tree = new WidgetRect(row: 0, col: 0, columns: 20, rows: 30);

        $this->assertTrue($layout->armSidebarResize($separator, $this->press(20)));
        $layout->continueResizeDrag($this->drag(30), $tree);
        Probe::set($this->tui, 'renderRequested', false);

        $this->assertTrue($layout->continueResizeDrag($this->release(35), $tree), 'the release itself consumes the event');
        $this->assertSame(35, $this->tree->width(), 'the release applies the final pointer position');
        $this->assertTrue(Probe::get($this->tui, 'renderRequested'), 'the release requests a render');

        $file = $this->project->dir.'/.porta/layout.json';
        $this->assertTrue(\is_file($file), 'the release persisted the new width');
        /** @var array<string, mixed> $saved */
        $saved = \json_decode((string) \file_get_contents($file), associative: true);
        $this->assertSame(35, $saved['sidebarWidthPercent'], 'persisted as a percent of the 100-column terminal');

        // The drag is now disarmed: a further drag (no fresh press) must not move it.
        $layout->continueResizeDrag($this->drag(50), $tree);
        $this->assertSame(35, $this->tree->width(), 'disarmed after release, further motion is inert');
    }

    public function testReleaseAtTheStartWidthDoesNotPersistAgain(): void
    {
        $layout = $this->controller();
        $separator = new WidgetRect(row: 0, col: 20, columns: 1, rows: 30);
        $tree = new WidgetRect(row: 0, col: 0, columns: 20, rows: 30);
        $file = $this->project->dir.'/.porta/layout.json';

        $this->assertTrue($layout->armSidebarResize($separator, $this->press(20)));
        // Release at the column that reproduces the tree's own start width
        // (30, its default): width is unchanged, so the `!==` guard must skip
        // the commit — an `&&`-vs-`||` bug would commit regardless, and a
        // redundant commit still isn't a *first* persist.
        $layout->continueResizeDrag($this->release(30), $tree);

        $this->assertSame(30, $this->tree->width(), 'width did not move');
        $this->assertFalse(\is_file($file), 'nothing was ever committed to disk');
    }

    public function testAnUnmintedViewSidebarNeverArms(): void
    {
        $layout = $this->controller();
        $rect = new WidgetRect(row: 0, col: 0, columns: 1, rows: 30);

        // No region was ever minted for this id — ViewRegions::find() returns
        // null, so the nullsafe chain must short-circuit rather than crash.
        $this->assertFalse($layout->armViewSidebarResize('never-minted', $rect, $this->press(0)));
    }

    public function testAMintedRegionWithNoSidebarSurvivesAPersist(): void
    {
        $layout = $this->controller();
        // Minted (so it shows up in regions->all()) but never given a sidebar:
        // viewWidths() must skip it via its own nullsafe, not crash reading
        // ->widthPercent off a null viewResize().
        $this->regions->mint('bare');

        $layout->nudgeSidebar(1);

        $this->addToAssertionCount(1); // reaching here without a crash is the assertion
    }

    public function testWidthToPointerSubtractsTheRectsOwnColumnUnderLtr(): void
    {
        // Every other LTR fixture in this file anchors its rect at column 0,
        // where `col - rect->col` and a corrupted `col + rect->col` coincide.
        // A view sidebar with a non-zero rect->col tells them apart.
        $layout = $this->controller();
        $separator = new WidgetRect(row: 0, col: 39, columns: 1, rows: 30);
        $left = new WidgetRect(row: 0, col: 40, columns: 30, rows: 30);

        $this->assertTrue($layout->armViewSidebarResize('git', $separator, $this->press(39)));

        $layout->continueResizeDrag($this->drag(75), null, $left);
        $this->assertSame(35, $this->gitSidebar->width(), '75 - 40 = 35, not 75 + 40');
    }

    public function testArmPanelResizeArmsAVisiblePanelAtItsTopEdgeWithTheDefaultOffset(): void
    {
        $layout = $this->controller();
        $panel = new TerminalPanelWidget();
        // OverlayWidget::isVisible() is `open && revealed`; `open` has no
        // public setter reachable without a real TerminalBuffer/session, so
        // it's set directly here — the point of this test is armPanelResize's
        // own guard, not the panel's own open() plumbing.
        Probe::set($panel, 'open', true);
        $this->tui->add($panel);
        $this->tui->requestRender();
        $this->tui->processRender();
        $rect = $this->renderer->getWidgetRect($panel);
        $this->assertInstanceOf(WidgetRect::class, $rect, 'the panel actually rendered somewhere');

        $mouse = MouseEvent::parse(\sprintf("\x1b[<0;%d;%dM", $rect->col + 1, $rect->row + 1));
        $this->assertInstanceOf(MouseEvent::class, $mouse);

        // No explicit $grabRowOffset: the default must be exactly 0, so a
        // press on the rect's own top row (not one above or below it) arms it.
        $this->assertTrue($layout->armPanelResize($panel, $mouse));
    }

    public function testArmPanelResizeRefusesAnInvisiblePanelEvenWithAStaleCachedRect(): void
    {
        $layout = $this->controller();
        $panel = new TerminalPanelWidget();
        Probe::set($panel, 'open', true);
        $this->tui->add($panel);
        $this->tui->requestRender();
        $this->tui->processRender();
        $rect = $this->renderer->getWidgetRect($panel);
        $this->assertInstanceOf(WidgetRect::class, $rect, 'sanity: it rendered while visible');

        // Now hide it WITHOUT detaching it — the renderer's cached rect from
        // the render above is still there, so the visibility guard itself
        // (not "no rect") is what must refuse the resize.
        Probe::set($panel, 'open', false);
        $mouse = MouseEvent::parse(\sprintf("\x1b[<0;%d;%dM", $rect->col + 1, $rect->row + 1));
        $this->assertInstanceOf(MouseEvent::class, $mouse);

        $this->assertFalse($layout->armPanelResize($panel, $mouse), 'invisible, despite the stale rect still resolving');
    }
}
