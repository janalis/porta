<?php

declare(strict_types=1);

namespace Porta\Input;

use Porta\Debug\DebugController;
use Porta\Editor\BufferManager;
use Porta\Editor\BufferService;
use Porta\Layout\LayoutController;
use Porta\Overlay\MenuOverlayController;
use Porta\Support\IntMath;
use Porta\Tree\TreeOperations;
use Porta\Ui\BidiTui;
use Porta\Ui\BottomDock;
use Porta\Ui\ContextMenuBuilder;
use Porta\Ui\ContextMenuOpener;
use Porta\Ui\DockTab;
use Porta\Ui\WorkspaceViews;
use Porta\Widget\ContextMenu;
use Porta\Widget\DockTabsWidget;
use Porta\Widget\MenuBarWidget;
use Porta\Widget\SeparatorWidget;
use Porta\Widget\StatusBarWidget;
use Porta\Widget\TerminalPanelWidget;
use Symfony\Component\Tui\Render\WidgetRect;
use Symfony\Component\Tui\Tui;

/**
 * Routes every mouse event to the pane under the pointer: wheel scrolling,
 * hover highlighting (mouse mode 1003), text/column/tab drags, pane resize
 * drags, popup and dropdown clicks, and the right-click context menu. Owns
 * the cross-event drag state and the double/triple-click counter that the
 * coordinator used to carry.
 *
 * Guard order is behavior: active drags run first, then resize drags, then
 * right-press, then the modal popups, then the panes front-to-back.
 *
 * Two rules hold across every pane here:
 *
 * - A left-click that targets a pane FOCUSES that pane — including a click on
 *   a strip tab, which focuses the pane it reveals even when that pane was
 *   already the one displayed. A click that targets a FILE focuses whichever
 *   pane the file lands in (see {@see BufferService::focusEditorArea()}, which
 *   is why a tab click reaches the image or help viewer rather than the editor).
 * - A click activates on the FIRST press; multiplicity belongs to the editor
 *   alone (see {@see ClickCounter}).
 */
final class MouseRouter
{
    /** True while a left-drag started in the editor is extending a text selection. */
    private bool $mouseSelecting = false;
    /** True while an Alt+Shift left-drag is building a column (rectangular) selection. */
    private bool $columnSelecting = false;
    /** True while a left-drag started on a tab is reordering it; $tabDragPath is the dragged buffer. */
    private bool $tabDragging = false;

    /** @var array{tab: DockTab, index: int}|null the dock-strip tab being drag-reordered */
    private ?array $dockTabDrag = null;
    private ?string $tabDragPath = null;

    /** Double/triple-click detection, kept out of the routing logic. */
    private readonly ClickCounter $clicks;

    public function __construct(
        private readonly HitTester $hit,
        private readonly Tui $tui,
        private readonly EditorLayoutPanes $panes,
        private readonly BottomDock $dock,
        private readonly DockTabsWidget $dockTabs,
        private readonly MenuBarWidget $menuBar,
        private readonly ContextMenu $contextMenu,
        private readonly LayoutController $layout,
        private readonly MenuOverlayController $overlay,
        private readonly BufferManager $buffers,
        private readonly BufferService $bufferService,
        private readonly TreeOperations $treeOps,
        private readonly ContextMenuBuilder $contextItems,
        private readonly GitMouseRouter $git,
        private readonly WorkspaceViews $views,
        private readonly SettingsMouseRouter $settings,
        private readonly WelcomeMouseRouter $welcome,
        private readonly BidiTui $bidi,
        private readonly DebugController $debug,
        private readonly SeparatorWidget $separator,
        private readonly ContextMenuOpener $opener,
        private readonly StatusBarWidget $statusBar,
        private readonly HoverRouter $hover,
    ) {
        $this->clicks = new ClickCounter();
    }

    public function route(MouseEvent $mouse): void
    {
        // Under an RTL locale the frame's glyphs are reordered after layout, so a
        // click's screen column is mapped back to the logical column the widgets
        // rendered before any rect/hit-testing (identity for LTR content).
        $mouse = $mouse->withColumn($this->bidi->logicalColumn($mouse->row, $mouse->col));

        $treeRect = $this->hit->rect($this->panes->tree());
        $tabsRect = $this->hit->rect($this->panes->tabBar());
        $editorRect = $this->hit->rect($this->panes->editor());
        $sepRect = $this->hit->rect($this->separator);

        // While a full view (git or settings) owns the main area, the
        // tree/tabs/editor (and the menu bar inside the editor pane) are
        // detached — but the renderer still holds their last-frame rects. Null
        // them so no stale-rect branch routes events to an invisible widget.
        $gitViewOpen = $this->views->isGitOpen();
        $settingsViewOpen = $this->views->isSettingsOpen();
        if ($this->views->isAnyOpen()) {
            $treeRect = null;
            $tabsRect = null;
            $editorRect = null;
            $sepRect = null;
        }
        // The git/settings sidebars' drag columns and left edges (each router
        // owns its widgets; the rects exist only while its view is swapped in).
        $gitSepRect = $gitViewOpen ? $this->git->separatorRect() : null;
        $gitLeftRect = $gitViewOpen ? $this->git->leftRect() : null;
        $settingsSepRect = $settingsViewOpen ? $this->settings->separatorRect() : null;
        $settingsLeftRect = $settingsViewOpen ? $this->settings->leftRect() : null;

        if ($mouse->wheel) {
            // The Welcome modal is app-modal: every wheel is its while open.
            if ($this->welcome->isModalOpen()) {
                $this->welcome->handleWheel($mouse);
                $this->tui->requestRender();

                return;
            }
            $this->handleWheel($mouse, $treeRect, $tabsRect, $editorRect);

            return;
        }

        // Bare hover-move (mouse mode 1003): track the row under the pointer in
        // whichever list popup/panel is open, like PhpStorm's menu hovering.
        if ($mouse->isMove()) {
            $this->hover->handle($mouse, $editorRect, match (true) {
                $settingsViewOpen => ActiveView::Settings,
                $gitViewOpen => ActiveView::Git,
                default => ActiveView::Editor,
            });

            return;
        }

        // Drags in progress (text/column selection, tab reorder, pane resize)
        // consume the event wherever the pointer now is.
        if (
            $this->continueTextDrag($mouse, $editorRect)
            || $this->continueColumnDrag($mouse, $editorRect)
            || $this->continueTabDrag($mouse, $tabsRect)
            || $this->continueDockTabDrag($mouse)
            || $this->git->continueDiffDrag($mouse)
            || $this->welcome->continueDrag($mouse)
            || $this->continueFindModalDrag($mouse)
            || $this->layout->continueResizeDrag($mouse, $treeRect, $gitLeftRect, $settingsLeftRect)
        ) {
            return;
        }

        // Right-click opens a context menu for whichever pane is under the pointer
        // — swallowed while the Welcome modal is open (no context menus over it).
        if ($mouse->isRightPress()) {
            if (!$this->welcome->isModalOpen()) {
                $this->handleRightPress($mouse, $treeRect, $tabsRect, $editorRect);
            }

            return;
        }

        if (!$mouse->isLeftPress()) {
            return;
        }
        // A fresh press ends any prior drag arming; only an editor/tab press re-arms it.
        $this->mouseSelecting = false;
        $this->columnSelecting = false;
        $this->tabDragging = false;
        $this->dockTabDrag = null;
        $this->git->cancelDrag();
        $this->welcome->cancelDrag();
        $this->panes->editor()->cancelFindModalDrag();
        $this->layout->cancelDrags();

        if ($this->handleModalPopupClick($mouse, $editorRect, $this->views->isAnyOpen() ? FullView::Open : FullView::Closed)) {
            return;
        }

        // Separator drags: a press on the tree↔editor separator column, or on the top edge
        // of the terminal/results panel, arms a resize (must beat the pane hit-tests below).
        if ($this->layout->armSidebarResize($sepRect, $mouse)) {
            return;
        }
        if ($this->layout->armGitSidebarResize($gitSepRect, $mouse)) {
            return;
        }
        if ($this->layout->armSettingsSidebarResize($settingsSepRect, $mouse)) {
            return;
        }
        // The dock strip is both the tool tabs and the resize grab: a click
        // on a tab wins, a click on its empty tail falls through to resize
        // (which sits on the strip row — one row above the visible panel).
        if ($this->tryDockTabClick($mouse)) {
            return;
        }
        $panel = $this->dock->visiblePanel();
        if (null !== $panel && $this->layout->armPanelResize($panel, $mouse, $this->dock->stripVisible() ? -1 : 0)) {
            return;
        }

        $this->handlePaneClick($mouse, $treeRect, $tabsRect, $editorRect);
    }

    /**
     * Continue an armed Find-in-Files drag (corner resize or title-bar move)
     * in screen coordinates — the modal floats over the whole workspace, so
     * no pane clamps the pointer; the modal's own layout clamps the box.
     * False when nothing is armed.
     */
    private function continueFindModalDrag(MouseEvent $mouse): bool
    {
        if (!$mouse->isLeftDrag() && !$mouse->isLeftRelease()) {
            return false;
        }
        if (!$this->panes->editor()->continueFindModalDrag($mouse->row, $mouse->col, $mouse->isLeftRelease())) {
            return false;
        }
        $this->tui->requestRender();

        return true;
    }

    /** Whether $rect exists and the pointer is inside it — the wheel-scroll hit guard. */
    private function containsPointer(?WidgetRect $rect, MouseEvent $mouse): bool
    {
        return null !== $rect && $rect->contains($mouse->row, $mouse->col);
    }

    /** Wheel: scroll whichever pane is under the pointer. */
    private function handleWheel(MouseEvent $mouse, ?WidgetRect $treeRect, ?WidgetRect $tabsRect, ?WidgetRect $editorRect): void
    {
        // Horizontal wheel (trackpad swipe or shift+wheel): only the panes
        // with long lines scroll sideways; everything else ignores it.
        $imageRect = $this->hit->rect($this->panes->imageViewer());
        if (0 !== $mouse->wheelHDelta) {
            if ($this->git->scrollHorizontal($mouse)) {
                $this->tui->requestRender();

                return;
            }
            if ($this->containsPointer($tabsRect, $mouse) && !$this->panes->tabBar()->position()->isSide()) {
                // The strip renders mirrored under RTL, so the visual swipe
                // direction flips to match the on-screen tab order.
                $this->panes->tabBar()->scrollBy($this->bidi->isMirrored() ? -$mouse->wheelHDelta : $mouse->wheelHDelta);
                $this->tui->requestRender();

                return;
            }
            if ($this->containsPointer($editorRect, $mouse)) {
                $this->panes->editor()->scrollHBy(WheelStep::cols($mouse));
                $this->tui->requestRender();

                return;
            }
            if ($this->containsPointer($imageRect, $mouse)) {
                $this->panes->imageViewer()->scrollBy(WheelStep::cols($mouse), 0);
                $this->tui->requestRender();
            }

            return;
        }

        // The tab strip: a wheel over it pages through overflowing tabs — one
        // An open menu owns the wheel inside its dropdown region, at the shared
        // WheelStep rate like every other list — separator-skipping, so a notch
        // lands only on selectable items.
        if ($this->contextMenu->isOpen() && null !== $this->hit->hitRegion($this->overlay->contextMenuDropdown(), $mouse->row, $mouse->col)) {
            $this->contextMenu->moveSelection(WheelStep::rows($mouse));
            $this->tui->requestRender();

            return;
        }
        if ($this->menuBar->isOpen() && null !== $this->hit->hitRegion($this->overlay->menuDropdown(), $mouse->row, $mouse->col)) {
            $this->menuBar->moveSelection(WheelStep::rows($mouse));
            $this->tui->requestRender();

            return;
        }

        // wide tab per notch on the horizontal strip, the panel-list three
        // rows per notch on the vertical one.
        if ($this->containsPointer($tabsRect, $mouse)) {
            // The side strip is a list of rows; the horizontal one moves one
            // whole TAB per notch — a different unit, not the same constant.
            $tabBar = $this->panes->tabBar();
            $tabBar->scrollBy($tabBar->position()->isSide() ? WheelStep::rows($mouse) : $mouse->wheelDelta);
            $this->tui->requestRender();

            return;
        }

        // The Find modal composites inside the editor pane and owns input while
        // open — scroll its preview instead of the editor behind it.
        $findBox = $this->panes->editor()->isFindModalOpen() ? $this->panes->editor()->findModal()->lastBox() : null;
        if (null !== $findBox && $findBox->contains($mouse->row, $mouse->col)) {
            $this->panes->editor()->scrollFindModal(WheelStep::rows($mouse));
            $this->tui->requestRender();

            return;
        }

        // Wheel over the dock strip cycles the tool tabs — an explicit panel
        // switch, so the focus follows like a strip click would.
        if (null !== $this->hover->dockStripRelCol($mouse)) {
            $this->dock->cycle($mouse->wheelDelta);
            $this->tui->requestRender();

            return;
        }

        // The visible dock panel (results / debug / terminal) scrolls its
        // list — the terminal scrolls its scrollback (wheel up → into history).
        $panel = $this->dock->visiblePanel();
        if (null !== $panel && $this->containsPointer($this->hit->rect($panel), $mouse)) {
            match (true) {
                // Scrollback runs the other way: wheel up goes INTO history.
                $panel instanceof TerminalPanelWidget => $panel->scroll(WheelStep::rows($mouse, factor: -1)),
                default => $panel->scrollBy(WheelStep::rows($mouse)),
            };
            $this->tui->requestRender();

            return;
        }

        $step = WheelStep::rows($mouse);
        if ($this->containsPointer($editorRect, $mouse)) {
            // An open editor popup (completion, list overlay, search modal)
            // owns the wheel — its selection moves instead of the buffer
            // scrolling away underneath it.
            if (!$this->panes->editor()->movePopupSelection($step)) {
                $this->panes->editor()->scrollBy($step);
            }
            $this->tui->requestRender();

            return;
        }
        if (null !== $imageRect && $imageRect->contains($mouse->row, $mouse->col)) {
            // Plain wheel pans the image; Ctrl+wheel zooms toward the pointer.
            $rel = $imageRect->toRelative($mouse->row, $mouse->col);
            $mouse->ctrl
                ? $this->panes->imageViewer()->zoomAt($mouse->wheelDelta < 0 ? 1 : -1, $rel['col'], $rel['row'])
                // Pixel rows are half-height, so a notch covers the same distance.
                : $this->panes->imageViewer()->scrollBy(0, WheelStep::rows($mouse, factor: 2));
            $this->tui->requestRender();

            return;
        }
        if (null !== ($helpRect = $this->hit->rect($this->panes->helpViewer())) && $helpRect->contains($mouse->row, $mouse->col)) {
            $this->panes->helpViewer()->scrollBy($step);
            $this->tui->requestRender();

            return;
        }
        if ($this->containsPointer($treeRect, $mouse)) {
            $this->panes->tree()->scrollBy($step);
            $this->tui->requestRender();

            return;
        }
        if (!$this->git->scrollVertical($mouse, $step) && !$this->settings->scrollVertical($mouse)) {
            // No full view's pane is under the pointer.
            return;
        }
        $this->tui->requestRender();
    }

    /**
     * The pointer clamped into $rect (so a drag past an edge pins to the last
     * row/column) and converted to rect-relative coordinates.
     *
     * @return array{row: int, col: int}
     */
    private function clampedRelative(WidgetRect $rect, MouseEvent $mouse): array
    {
        $row = IntMath::clamp($mouse->row, $rect->row, $rect->row + $rect->rows - 1);
        $col = IntMath::clamp($mouse->col, $rect->col, $rect->col + $rect->columns - 1);

        return $rect->toRelative($row, $col);
    }

    /**
     * Active text selection: a left-drag started in the editor keeps extending
     * on motion/release, wherever the pointer now is.
     */
    private function continueTextDrag(MouseEvent $mouse, ?WidgetRect $editorRect): bool
    {
        if (!$this->mouseSelecting || !$mouse->isLeftDrag() && !$mouse->isLeftRelease()) {
            return false;
        }
        if (null !== $editorRect) {
            $rel = $this->clampedRelative($editorRect, $mouse);
            $this->panes->editor()->extendSelectionTo($rel['row'], $rel['col']);
        }
        if ($mouse->isLeftRelease()) {
            $this->mouseSelecting = false;
        }
        $this->tui->requestRender();

        return true;
    }

    /**
     * Alt+Shift column selection: an editor-started drag rebuilds the
     * rectangle on motion/release (gated by the flag, not per-event modifiers).
     */
    private function continueColumnDrag(MouseEvent $mouse, ?WidgetRect $editorRect): bool
    {
        if (!$this->columnSelecting || !$mouse->isLeftDrag() && !$mouse->isLeftRelease()) {
            return false;
        }
        if (null !== $editorRect) {
            $rel = $this->clampedRelative($editorRect, $mouse);
            $this->panes->editor()->extendColumnSelectionTo($rel['row'], $rel['col']);
        }
        if ($mouse->isLeftRelease()) {
            $this->columnSelecting = false;
        }
        $this->tui->requestRender();

        return true;
    }

    /** Tab reordering: a left-drag started on a tab moves it under the pointer. */
    private function continueTabDrag(MouseEvent $mouse, ?WidgetRect $tabsRect): bool
    {
        if (!$this->tabDragging || !$mouse->isLeftDrag() && !$mouse->isLeftRelease()) {
            return false;
        }
        if (null !== $tabsRect && null !== $this->tabDragPath) {
            // Clamp both axes into the strip so a drag that wanders off it still
            // resolves to the nearest tab (row matters for the vertical list).
            $row = IntMath::clamp($mouse->row, $tabsRect->row, $tabsRect->row + $tabsRect->rows - 1);
            $col = IntMath::clamp($mouse->col, $tabsRect->col, $tabsRect->col + $tabsRect->columns - 1);
            $rel = $tabsRect->toRelative($row, $col);
            $target = $this->panes->tabBar()->tabIndexAt($rel['row'], $rel['col']);
            if (null !== $target) {
                $order = $this->buffers->all();
                $current = null;
                foreach ($order as $i => $buffer) {
                    if ($buffer->path !== $this->tabDragPath) {
                        continue;
                    }

                    $current = $i;
                    break;
                }
                // This IS the drag threshold, and it is in the right unit: a
                // reorder needs the pointer over a DIFFERENT tab, so a wobble
                // inside the one you pressed resolves to the same index and
                // does nothing. A cell-based threshold on top would break the
                // vertical list, where one row is one whole tab.
                if (null !== $current && $current !== $target) {
                    $this->buffers->reorder($current, $target);
                    // A pure reorder mutates no widget, so the render pass would be
                    // a full-tree cache hit — and the strip's provider-fingerprint
                    // check lives in beforeRender, which cached subtrees never run.
                    // Invalidate the strip so this frame actually repaints it.
                    $this->panes->tabBar()->invalidate();
                    $this->tui->requestRender();
                }
            }
        }
        if ($mouse->isLeftRelease()) {
            $this->tabDragging = false;
        }

        return true;
    }

    /**
     * A left press while a popup/menu is showing: an open editor popup (modal
     * list overlay / completion) claims the click — inside the editor pane it
     * picks an item, outside a modal overlay is dismissed, and a completion
     * click that misses falls through to caret placement. Then an open context
     * menu or application menu runs/dismisses on its own hit test. Returns
     * whether the click was consumed.
     */
    private function handleModalPopupClick(MouseEvent $mouse, ?WidgetRect $editorRect, FullView $fullViewOpen = FullView::Closed): bool
    {
        // Editor popups are unreachable while a full view (git/settings) owns the
        // main area (the editor is detached); only the menus below can claim it.
        if (
            FullView::Closed === $fullViewOpen
            && null !== $this->panes->editor()->getContext() // detached under an image tab → no invisible modal may eat clicks
            && ($this->panes->editor()->isPromptOpen() || $this->panes->editor()->isOverlayOpen() || $this->panes->editor()->isCompletionOpen() || $this->panes->editor()->isSearchOverlayOpen())
        ) {
            if (null !== $editorRect && $editorRect->contains($mouse->row, $mouse->col)) {
                $rel = $editorRect->toRelative($mouse->row, $mouse->col);
                if ($this->panes->editor()->handlePopupClick($rel['row'], $rel['col'])) {
                    $this->tui->requestRender();

                    return true;
                }
            }
            if (
                (null === $editorRect || !$editorRect->contains($mouse->row, $mouse->col))
                && ($this->panes->editor()->isPromptOpen() || $this->panes->editor()->isOverlayOpen() || $this->panes->editor()->isSearchOverlayOpen())
            ) {
                $this->panes->editor()->handlePopupClick(-1, -1); // outside the modal overlay → dismiss
                $this->tui->requestRender();

                return true;
            }
        }

        // An open context menu: a click on an item runs it; a click anywhere else
        // dismisses it (the panel floats over the panes, so hit-test its region).
        if ($this->contextMenu->isOpen()) {
            $item = $this->hit->hitRegion($this->overlay->contextMenuDropdown(), $mouse->row, $mouse->col);
            if (null !== $item) {
                $this->contextMenu->runItem($item);
            }
            if (null === $item) {
                $this->contextMenu->close();
            }
            $this->tui->requestRender();

            return true;
        }

        // Menu bar: click a title to open (or switch) its dropdown.
        if (null !== ($rel = $this->hit->hit($this->menuBar, $mouse->row, $mouse->col))) {
            if (null !== ($title = $this->menuBar->titleAtColumn($rel['col']))) {
                $closeOpenTitle = $this->menuBar->isOpen() && $this->menuBar->openIndex() === $title;
                if ($closeOpenTitle) {
                    $this->menuBar->close(); // second click on the open title → close
                }
                if (!$closeOpenTitle) {
                    $this->menuBar->open($title); // open, or switch to a different title
                    $this->tui->setFocus($this->menuBar);
                }
            }
            $this->tui->requestRender();

            return true;
        }
        // Dropdown item: the panel floats over the editor pane, outside the menu
        // bar's rect, so hit-test the tracked overlay region directly.
        if ($this->menuBar->isOpen()) {
            $item = $this->hit->hitRegion($this->overlay->menuDropdown(), $mouse->row, $mouse->col);
            if (null !== $item) {
                $this->menuBar->runItem($item);
            }
            if (null === $item) {
                $this->menuBar->close(); // a click anywhere else dismisses an open menu
            }
            $this->tui->requestRender();

            return true;
        }

        // The floating Find-in-Files modal, after the menus: ×, drags and
        // zone clicks land in screen coordinates; an outside click closes it
        // (its own semantics — unlike the Welcome modal it is dismissable).
        if ($this->panes->editor()->isFindModalOpen()) {
            $this->panes->editor()->findModal()->clickAt($mouse->row, $mouse->col);
            $this->tui->requestRender();

            return true;
        }

        // The Welcome modal, after the menus (the menu bar stays reachable over
        // it): ×/drag/select inside the box, swallowed everywhere else.
        if ($this->welcome->tryModalClick($mouse)) {
            return true;
        }

        return false;
    }

    /** A left press on the panes, tried front-to-back; the editor is the final target. */
    private function handlePaneClick(MouseEvent $mouse, ?WidgetRect $treeRect, ?WidgetRect $tabsRect, ?WidgetRect $editorRect): void
    {
        if ($this->git->tryPaneClick($mouse)) {
            return;
        }
        if ($this->settings->tryPaneClick($mouse)) {
            return;
        }
        if ($this->tryOverlayPanelClick($mouse)) {
            return;
        }
        if ($this->tryChromeClick($mouse, $tabsRect, $treeRect)) {
            return;
        }
        // The image viewer replaces the editor on image tabs: a click focuses it.
        $imageRect = $this->hit->rect($this->panes->imageViewer());
        if (null !== $imageRect && $imageRect->contains($mouse->row, $mouse->col)) {
            $this->tui->setFocus($this->panes->imageViewer());
            $this->tui->requestRender();

            return;
        }
        // The help viewer likewise: a click focuses it and follows a link hit.
        $helpRect = $this->hit->rect($this->panes->helpViewer());
        if (null !== $helpRect && $helpRect->contains($mouse->row, $mouse->col)) {
            $this->tui->setFocus($this->panes->helpViewer());
            $rel = $helpRect->toRelative($mouse->row, $mouse->col);
            $this->panes->helpViewer()->clickAt($rel['row'], $rel['col']);
            $this->tui->requestRender();

            return;
        }
        $this->handleEditorClick($mouse, $editorRect);
    }

    /** The floating bottom/overlay panels: terminal, results and go-to-file. */
    /** A press on the dock strip: select/close a tool tab, or false to fall through to resize. */
    private function tryDockTabClick(MouseEvent $mouse): bool
    {
        $rect = $this->hit->rect($this->dockTabs);
        if (!$this->dock->stripVisible() || null === $rect || $mouse->row !== $rect->row || $mouse->col < $rect->col || $mouse->col >= ($rect->col + $rect->columns)) {
            return false;
        }
        $col = $mouse->col - $rect->col;
        // A click on an overflow ‹/› pages the strip, before any tab hit —
        // mirroring the editor tab bar.
        if (null !== ($step = $this->dockTabs->scrollIndicatorAt($mouse->row - $rect->row, $col))) {
            $this->dockTabs->scrollBy($step);
            $this->tui->requestRender();

            return true;
        }
        // Resolve the drag source BEFORE the click runs — a close click mutates
        // the strip. dragSourceAt is null on a close ×, so only a select arms.
        $source = $this->dockTabs->dragSourceAt($col);
        if (!$this->dockTabs->handleTabClick($col)) {
            return false;
        }
        $this->dockTabDrag = $source;

        return true;
    }

    /**
     * Dock-strip tab reordering: a left-drag started on a tab body moves it
     * under the pointer, within its own family (session tabs among
     * themselves, tool tabs among themselves).
     */
    private function continueDockTabDrag(MouseEvent $mouse): bool
    {
        if (null === $this->dockTabDrag || !$mouse->isLeftDrag() && !$mouse->isLeftRelease()) {
            return false;
        }
        $rect = $this->dock->stripVisible() ? $this->hit->rect($this->dockTabs) : null;
        if (null !== $rect) {
            // Clamp into the strip so a drag that wanders off it still resolves.
            $col = IntMath::clamp($mouse->col, $rect->col, $rect->col + $rect->columns - 1) - $rect->col;
            $target = $this->dockTabs->familyIndexAt($col);
            if (null !== $target && $target['tab'] === $this->dockTabDrag['tab'] && $target['index'] !== $this->dockTabDrag['index']) {
                $this->dockTabs->moveTab($target['tab'], $this->dockTabDrag['index'], $target['index']);
                $this->dockTabDrag = $target;
                // A pure reorder mutates no widget (see continueTabDrag).
                $this->dockTabs->invalidate();
                $this->tui->requestRender();
            }
        }
        if ($mouse->isLeftRelease()) {
            $this->dockTabDrag = null;
        }

        return true;
    }

    private function tryOverlayPanelClick(MouseEvent $mouse): bool
    {
        // A click in the visible dock panel focuses it first, so Escape has a
        // route and terminal typing reaches the shell; results/debug rows also
        // activate (header collapse / jump / frame jump / expand toggle). The
        // dock strip above the panel is handled before resize, in the press
        // path, so a terminal body click here just refocuses.
        $panel = $this->dock->visiblePanel();
        if (null !== $panel && null !== ($rel = $this->hit->hit($panel, $mouse->row, $mouse->col))) {
            $this->tui->setFocus($panel);
            match (true) {
                $panel instanceof TerminalPanelWidget => null,
                default => $panel->handleClick($rel['row']),
            };
            $this->tui->requestRender();

            return true;
        }

        return false;
    }

    /** Editor-pane chrome: the tab bar, breadcrumbs bar and file tree. */
    private function tryChromeClick(MouseEvent $mouse, ?WidgetRect $tabsRect, ?WidgetRect $treeRect): bool
    {
        // Tab bar click: the close × closes that tab; the body switches to it and
        // arms a drag-to-reorder.
        if (null !== $tabsRect && $tabsRect->contains($mouse->row, $mouse->col)) {
            $rel = $tabsRect->toRelative($mouse->row, $mouse->col);
            $buffers = $this->buffers->all();
            if (null !== ($step = $this->panes->tabBar()->scrollIndicatorAt($rel['row'], $rel['col']))) {
                $this->panes->tabBar()->scrollBy($step);
                $this->tui->requestRender();

                return true;
            }
            if (null !== ($closeIndex = $this->panes->tabBar()->closeIndexAt($rel['row'], $rel['col']))) {
                if (array_key_exists($closeIndex, $buffers)) {
                    $this->bufferService->closeTab($buffers[$closeIndex]->path);
                }

                return true;
            }
            $index = $this->panes->tabBar()->tabIndexAt($rel['row'], $rel['col']);
            if (null !== $index && array_key_exists($index, $buffers)) {
                $this->buffers->setActive($buffers[$index]->path);
                $this->bufferService->showBuffer($buffers[$index]);
                $this->bufferService->focusEditorArea(); // an image tab focuses the viewer
                $this->tabDragging = true;
                $this->tabDragPath = $buffers[$index]->path;
                // A double-click reveals the file in the sidebar tree, selected
                // and highlighted, while focus stays in the editor.
                if (2 === $this->clicks->countAt($mouse->row, $mouse->col)) {
                    $this->panes->tree()->revealPath($buffers[$index]->path);
                    $this->tui->requestRender();
                }
            }

            return true;
        }

        // Find-bar click: the option tags toggle, exactly as their keys do.
        if ($this->panes->findBar()->isOpen() && null !== ($rel = $this->hit->hit($this->panes->findBar(), $mouse->row, $mouse->col))) {
            $this->panes->findBar()->handleClick($rel['col']);
            $this->tui->requestRender();

            return true;
        }

        // Breadcrumbs click: jump the caret to the clicked scope's declaration.
        // The bar renders zero rows without a trail (help/image tabs,
        // structureless files) but the renderer keeps its last non-empty rect,
        // so the click only counts when a segment is actually under the
        // pointer — otherwise it falls through to whatever now owns the row.
        if (null !== ($rel = $this->hit->hit($this->panes->breadcrumbs(), $mouse->row, $mouse->col)) && null !== ($line = $this->panes->breadcrumbs()->lineAtColumn($rel['col']))) {
            $this->tui->setFocus($this->panes->editor());
            $this->panes->editor()->jumpTo($line, 0);
            $this->tui->requestRender();

            return true;
        }

        // Tree click: select + open/toggle.
        if (null !== $treeRect && $treeRect->contains($mouse->row, $mouse->col)) {
            $rel = $treeRect->toRelative($mouse->row, $mouse->col);
            $this->tui->setFocus($this->panes->tree());
            $this->panes->tree()->clickRow($rel['row']);
            $this->tui->requestRender();

            return true;
        }

        return false;
    }

    /**
     * Editor press. Ctrl+Click navigates to the declaration (PhpStorm);
     * Alt+Shift begins a column selection; Alt toggles a caret; otherwise
     * place/extend a selection (drag extends it, double-click a word,
     * triple-click a line, Shift+click extends) — collapsing any extra carets.
     */
    private function handleEditorClick(MouseEvent $mouse, ?WidgetRect $editorRect): void
    {
        if (null === $editorRect || !$editorRect->contains($mouse->row, $mouse->col)) {
            return;
        }
        $rel = $editorRect->toRelative($mouse->row, $mouse->col);
        $this->tui->setFocus($this->panes->editor());
        $this->dispatchEditorPress($mouse, $rel);
        $this->tui->requestRender();
    }

    /**
     * Dispatch a left press inside the editor pane to the matching gesture.
     *
     * @param array{row: int, col: int} $rel the click in editor-relative coordinates
     */
    private function dispatchEditorPress(MouseEvent $mouse, array $rel): void
    {
        if ($mouse->ctrl && !$mouse->alt && !$mouse->shift) {
            // Place the caret at the click (fold/scroll-aware), then fire the
            // same action as Ctrl+B. A ctrl-click is a navigation, not a
            // selection start, so no drag is armed.
            $this->panes->editor()->clearExtraCarets();
            $this->panes->editor()->clickAt($rel['row'], $rel['col']);
            $this->panes->editor()->runAction('goto_definition');

            return;
        }
        if ($mouse->alt && $mouse->shift) {
            $this->panes->editor()->beginColumnSelectionAt($rel['row'], $rel['col']);
            $this->columnSelecting = true;

            return;
        }
        if ($mouse->alt) {
            $this->panes->editor()->addOrRemoveCaretAt($rel['row'], $rel['col']);

            return;
        }
        if ($rel['col'] < $this->panes->editor()->gutterWidth() && null !== ($docLine = $this->panes->editor()->documentLineAt($rel['row']))) {
            // A plain click in the gutter toggles a breakpoint on that line.
            $this->debug->toggleBreakpointAtDocLine($docLine);

            return;
        }
        $this->panes->editor()->clearExtraCarets();
        $count = $this->clicks->countAt($mouse->row, $mouse->col);
        if ($mouse->shift) {
            $this->panes->editor()->extendSelectionTo($rel['row'], $rel['col']);
        }
        if (!$mouse->shift && $count >= 3) {
            $this->panes->editor()->selectLineAt($rel['row'], $rel['col']);
        }
        if (!$mouse->shift && 2 === $count) {
            $this->panes->editor()->selectWordAt($rel['row'], $rel['col']);
        }
        if (!$mouse->shift && $count < 2) {
            $this->panes->editor()->beginSelectionAt($rel['row'], $rel['col']);
        }
        $this->mouseSelecting = true;
    }

    /**
     * Open the context menu for whichever pane is under a right-click: the editor
     * (edit/navigate/refactor actions), a tab (close actions), a tree node
     * (file operations), a dock-strip tab (close / session actions) or a git
     * changes row (stage/discard/history). A right-click supersedes any open
     * application menu.
     *
     * Anywhere else — a pane with no menu, or a strip's empty tail — says so,
     * the same way Ctrl+F10 does. The two chains share their finish
     * ({@see ContextMenuOpener}), which is also where the rule that governs
     * both of them is written down.
     */
    private function handleRightPress(MouseEvent $mouse, ?WidgetRect $treeRect, ?WidgetRect $tabsRect, ?WidgetRect $editorRect): void
    {
        $this->menuBar->close();

        // A modal picker (go-to-file / Search Everywhere) composites over the
        // editor rows and owns input, so a right-click inside it opens no menu.
        if ($this->panes->editor()->isSearchOverlayOpen()) {
            $this->opener->flashNoMenu();

            return;
        }

        $items = null;
        $handled = false;
        if (null !== $editorRect && $editorRect->contains($mouse->row, $mouse->col)) {
            $handled = true;
            $rel = $editorRect->toRelative($mouse->row, $mouse->col);
            $this->tui->setFocus($this->panes->editor());
            // Keep an existing selection (so Cut/Copy act on it); otherwise place
            // the caret at the click, like PhpStorm.
            if (!$this->panes->editor()->getDocument()->hasSelection()) {
                $this->panes->editor()->clearExtraCarets();
                $this->panes->editor()->clickAt($rel['row'], $rel['col']);
            }
            $items = $this->contextItems->editorItems();
        }
        if (!$handled && null !== $tabsRect && $tabsRect->contains($mouse->row, $mouse->col)) {
            $handled = true;
            $rel = $tabsRect->toRelative($mouse->row, $mouse->col);
            $index = $this->panes->tabBar()->tabIndexAt($rel['row'], $rel['col']);
            $buffers = $this->buffers->all();
            if (null === $index || !array_key_exists($index, $buffers)) {
                $this->opener->flashNoMenu(); // the strip's empty tail

                return;
            }
            $items = $this->contextItems->tabItems($buffers[$index]->path);
        }
        if (!$handled && null !== $treeRect && $treeRect->contains($mouse->row, $mouse->col)) {
            $handled = true;
            $rel = $treeRect->toRelative($mouse->row, $mouse->col);
            $this->tui->setFocus($this->panes->tree());
            $node = $this->panes->tree()->nodeAt($rel['row']);
            if (null === $node) {
                $this->opener->flashNoMenu(); // below the last node

                return;
            }
            $items = $this->treeOps->contextItems($node);
        }
        $stripCol = $this->hover->dockStripRelCol($mouse);
        if (!$handled && null !== $stripCol) {
            $handled = true;
            $hit = $this->dockTabs->tabAt($stripCol);
            if (null === $hit) {
                $this->opener->flashNoMenu(); // the strip's empty tail

                return;
            }
            $items = $this->contextItems->dockTabItems($hit['tab'], $hit['key']);
        }
        if (!$handled && null !== ($entry = $this->git->changesRowAt($mouse))) {
            $items = $this->contextItems->gitChangeItems($entry);
        }
        if (!$handled && null === $items && null !== ($commit = $this->git->historyCommitAt($mouse))) {
            $items = $this->contextItems->gitCommitItems($commit);
        }
        // A right-click on the status-bar branch name offers the branch actions.
        if (!$handled && null === $items) {
            $statusRect = $this->hit->rect($this->statusBar);
            $span = $this->statusBar->branchColumnSpan();
            if (null !== $statusRect && null !== $span && $statusRect->contains($mouse->row, $mouse->col)) {
                $relCol = $statusRect->toRelative($mouse->row, $mouse->col)['col'];
                if ($relCol >= $span[0] && $relCol < $span[1]) {
                    $items = $this->contextItems->branchItems();
                }
            }
        }
        if (null === $items) {
            $this->opener->flashNoMenu();

            return;
        }

        $this->opener->openAt($items, $mouse->row, $mouse->col);
    }

    /**
     * Consecutive-click count at a screen cell: 1, 2 (double) or 3 (triple),
     * within a 0.4 s window on the same cell; resets otherwise.
     */
}
