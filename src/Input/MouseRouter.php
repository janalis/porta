<?php

declare(strict_types=1);

namespace Porta\Input;

use Porta\Container\LayoutSlot;
use Porta\Debug\DebugController;
use Porta\Editor\BufferManager;
use Porta\Editor\BufferService;
use Porta\Layout\LayoutController;
use Porta\Layout\ViewRegions;
use Porta\Overlay\State\MenuOverlayController;
use Porta\Overlay\State\ToastCenter;
use Porta\Support\IntMath;
use Porta\Ui\BidiTui;
use Porta\Ui\ClickIntent;
use Porta\Widget\ContextMenu;
use Porta\Widget\DockTabsWidget;
use Porta\Widget\MenuBarWidget;
use Porta\Widget\SeparatorWidget;
use Porta\Workbench\Dock\BottomDock;
use Porta\Workbench\Dock\DockTab;
use Porta\Workbench\View\ViewPointers;
use Porta\Workbench\View\ViewRegistry;
use Porta\Workbench\View\WheelAxis;
use Porta\Workbench\View\WheelGesture;
use Symfony\Component\Tui\Render\WidgetRect;
use Symfony\Component\Tui\Tui;

/**
 * Routes every mouse event to the pane under the pointer: wheel scrolling,
 * hover highlighting (mouse mode 1003), text/column/tab drags, pane resize
 * drags, popup and dropdown clicks, and the right-click context menu. Owns
 * the cross-event drag state and the double/triple-click counter that the
 * coordinator used to carry.
 *
 * Guard order is behavior: active drags run first (the graph viewer's
 * drag-pan among them — its press ARMS the drag and its release-without-motion
 * is the click-select), then resize drags, then right-press, then the modal
 * popups, then the panes front-to-back.
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

    /** @var array{tab: ?DockTab, index: int}|null the dock-strip tab being drag-reordered */
    private ?array $dockTabDrag = null;

    /** @var array{row: int, col: int, moved: bool}|null a left-drag panning the graph viewer (release without motion = select) */
    private ?array $paneDrag = null;

    private ?string $tabDragPath = null;

    /** Double/triple-click detection, kept out of the routing logic. */
    private readonly ClickCounter $clicks;

    public function __construct(
        private readonly HitTester $hit,
        private readonly Tui $tui,
        private readonly EditorLayoutPanes $panes,
        private readonly ViewPointers $pointers,
        private readonly ViewRegions $regions,
        private readonly BottomDock $dock,
        private readonly DockTabsWidget $dockTabs,
        private readonly MenuBarWidget $menuBar,
        private readonly ContextMenu $contextMenu,
        private readonly LayoutController $layout,
        private readonly MenuOverlayController $overlay,
        private readonly BufferManager $buffers,
        private readonly BufferService $bufferService,
        private readonly ViewRegistry $views,
        private readonly WelcomeMouseRouter $welcome,
        private readonly BidiTui $bidi,
        private readonly DebugController $debug,
        #[LayoutSlot('separator')]
        private readonly SeparatorWidget $separator,
        private readonly HoverRouter $hover,
        private readonly ContextMenuRouter $rightClick,
        private readonly TreeDragRouter $treeDrag,
        private readonly ToastCenter $toasts,
        private readonly WheelRouter $wheel,
        private readonly StatusBarClickRouter $statusClick,
    ) {
        $this->clicks = new ClickCounter();
    }

    public function route(MouseEvent $mouse): void
    {
        // Under an RTL locale the frame's glyphs are reordered after layout, so a
        // click's screen column is mapped back to the logical column the widgets
        // rendered before any rect/hit-testing (identity for LTR content).
        $mouse = $mouse->withColumn($this->bidi->logicalColumn($mouse->row, $mouse->col));

        $treeRect = $this->hit->rect($this->panes->tree);
        $tabsRect = $this->hit->rect($this->panes->tabBar);
        $editorRect = $this->hit->rect($this->panes->editor);
        $sepRect = $this->hit->rect($this->separator);

        // While a full view (git or settings) owns the main area, the
        // tree/tabs/editor (and the menu bar inside the editor pane) are
        // detached — but the renderer still holds their last-frame rects. Null
        // them so no stale-rect branch routes events to an invisible widget.
        if ($this->views->isAnyOpen()) {
            $treeRect = null;
            $tabsRect = null;
            $editorRect = null;
            $sepRect = null;
        }
        // The open view's drag column and left edge. Core hit-tests its OWN
        // widgets — it minted these regions — so the two verbs the views used
        // to expose for this are gone rather than shared, and one pair of rects
        // replaces a pair per view core happened to know about.
        $activeView = $this->views->isAnyOpen() ? $this->views->activeId() : null;
        $viewRegion = null === $activeView ? null : $this->regions->find($activeView);
        $viewSepRect = null === $viewRegion ? null : $this->hit->rect($viewRegion->separator());
        $viewLeftRect = null === $viewRegion ? null : $this->hit->rect($viewRegion->sidebar());

        if ($mouse->wheel) {
            // The Welcome modal is app-modal: every wheel is its while open.
            if ($this->welcome->isModalOpen()) {
                $this->welcome->wheel(new WheelGesture($mouse, WheelAxis::Vertical, WheelStep::rows($mouse)));
                $this->tui->requestRender();

                return;
            }
            $this->wheel->route($mouse, $treeRect, $tabsRect, $editorRect);

            return;
        }

        // Bare hover-move (mouse mode 1003): track the row under the pointer in
        // whichever list popup/panel is open, like PhpStorm's menu hovering.
        if ($mouse->isMove()) {
            $this->hover->handle($mouse, $editorRect, $this->views->activeId());

            return;
        }

        // Drags in progress (text/column selection, tab reorder, pane resize)
        // consume the event wherever the pointer now is.
        if (
            $this->continueTextDrag($mouse, $editorRect)
            || $this->continueColumnDrag($mouse, $editorRect)
            || $this->continueTabDrag($mouse, $tabsRect)
            || $this->continuePaneDrag($mouse)
            || $this->continueDockTabDrag($mouse)
            || $this->treeDrag->continueDrag($mouse, $treeRect, $editorRect)
            || $this->pointers->of($this->views->activeId())->continueDrag($mouse)
            || $this->welcome->continueDrag($mouse)
            || $this->continueFindModalDrag($mouse)
            || $this->layout->continueResizeDrag($mouse, $treeRect, $viewLeftRect)
        ) {
            return;
        }

        // Right-click opens a context menu for whichever pane is under the pointer
        // — swallowed while the Welcome modal is open (no context menus over it).
        if ($mouse->isRightPress()) {
            if (!$this->welcome->isModalOpen()) {
                $this->rightClick->route($mouse, $treeRect, $tabsRect, $editorRect);
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
        $this->paneDrag = null;
        $this->treeDrag->cancel();
        $this->pointers->of($this->views->activeId())->cancelDrag();
        $this->welcome->cancelDrag();
        $this->panes->editor->cancelFindModalDrag();
        $this->layout->cancelDrags();

        // The toast stack floats above every modal: its × dismisses, a card
        // body swallows the click, anywhere else falls straight through.
        if ($this->toasts->clickAt($mouse->row, $mouse->col)) {
            $this->tui->requestRender();

            return;
        }

        if ($this->handleModalPopupClick($mouse, $editorRect, $this->views->isAnyOpen() ? FullView::Open : FullView::Closed)) {
            return;
        }

        // The status bar's own cells: problem counts, caret position, LSP
        // status, and a cell's menu (the branch). Below the modals — an open
        // dropdown may cover the row — and above the pane hit-tests, which do
        // not reach the chrome anyway.
        if ($this->statusClick->route($mouse, $this->rightClick->openStatusMenu(...))) {
            return;
        }

        // Separator drags: a press on the tree↔editor separator column, or on the top edge
        // of the terminal/results panel, arms a resize (must beat the pane hit-tests below).
        if ($this->layout->armSidebarResize($sepRect, $mouse)) {
            return;
        }
        if (null !== $activeView && $this->layout->armViewSidebarResize($activeView, $viewSepRect, $mouse)) {
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
        if (!$this->panes->editor->continueFindModalDrag($mouse->row, $mouse->col, $mouse->isLeftRelease())) {
            return false;
        }
        $this->tui->requestRender();

        return true;
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
    /**
     * A graph-pane drag pans the viewport by the pointer's cell delta (world
     * moves WITH the pointer, so the pan is the negated delta in half-rows);
     * a release that never moved is a click and selects the node under it.
     */
    private function continuePaneDrag(MouseEvent $mouse): bool
    {
        $pane = $this->panes->navigablePane();
        if (null === $this->paneDrag || null === $pane || !$mouse->isLeftDrag() && !$mouse->isLeftRelease()) {
            return false;
        }
        $dCols = $mouse->col - $this->paneDrag['col'];
        $dRows = $mouse->row - $this->paneDrag['row'];
        if (0 !== $dCols || 0 !== $dRows) {
            $pane->scrollBy(-$dCols, -2 * $dRows);
            $this->paneDrag = ['row' => $mouse->row, 'col' => $mouse->col, 'moved' => true];
        }
        if ($mouse->isLeftRelease()) {
            if (!$this->paneDrag['moved']) {
                $rect = $this->hit->rect($pane);
                if (null !== $rect && $rect->contains($mouse->row, $mouse->col)) {
                    $rel = $rect->toRelative($mouse->row, $mouse->col);
                    $pane->selectAt($rel['row'], $rel['col']);
                }
            }
            $this->paneDrag = null;
        }
        $this->tui->requestRender();

        return true;
    }

    private function continueTextDrag(MouseEvent $mouse, ?WidgetRect $editorRect): bool
    {
        if (!$this->mouseSelecting || !$mouse->isLeftDrag() && !$mouse->isLeftRelease()) {
            return false;
        }
        if (null !== $editorRect) {
            $rel = $this->clampedRelative($editorRect, $mouse);
            $this->panes->editor->extendSelectionTo($rel['row'], $rel['col']);
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
            $this->panes->editor->extendColumnSelectionTo($rel['row'], $rel['col']);
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
            $target = $this->panes->tabBar->tabIndexAt($rel['row'], $rel['col']);
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
                    $this->panes->tabBar->invalidate();
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
        // The completion popup is unreachable while a full view (git/settings)
        // owns the main area (the editor is detached); only the menus below
        // can claim the click. The floating pickers get their screen-coords
        // branch after the menus — dropdowns paint above them.
        if (
            FullView::Closed === $fullViewOpen
            && null !== $this->panes->editor->getContext() // detached under an image tab → no invisible popup may eat clicks
            && $this->panes->editor->isCompletionOpen()
            && null !== $editorRect
            && $editorRect->contains($mouse->row, $mouse->col)
        ) {
            $rel = $editorRect->toRelative($mouse->row, $mouse->col);
            if ($this->panes->editor->handlePopupClick($rel['row'], $rel['col'])) {
                $this->tui->requestRender();

                return true;
            }
        }

        // An open context menu: a click on an item runs it; a click on the
        // frame's border cells is swallowed (a 1-cell mis-click must not
        // dismiss); a click anywhere else dismisses it (the panel floats over
        // the panes, so hit-test its region).
        if ($this->contextMenu->isOpen()) {
            $region = $this->overlay->contextMenuDropdown();
            $item = $this->hit->hitRegion($region, $mouse->row, $mouse->col);
            if (null !== $item) {
                $this->contextMenu->runItem($item);
            }
            if (null === $item && true !== $region?->contains($mouse->row, $mouse->col)) {
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
        // bar's rect, so hit-test the tracked overlay region directly. A click
        // on the frame's border cells is swallowed, not a dismissal.
        if ($this->menuBar->isOpen()) {
            $region = $this->overlay->menuDropdown();
            $item = $this->hit->hitRegion($region, $mouse->row, $mouse->col);
            if (null !== $item) {
                $this->menuBar->runItem($item);
            }
            if (null === $item && true !== $region?->contains($mouse->row, $mouse->col)) {
                $this->menuBar->close(); // a click anywhere else dismisses an open menu
            }
            $this->tui->requestRender();

            return true;
        }

        // The floating pickers (list overlay, Search Everywhere, the mini
        // prompt), after the menus: item/×/outside-dismiss in screen
        // coordinates. No attachment guard — the pickers are painted above
        // every pane, so a click on a visible box belongs to it wherever the
        // editor is.
        if ($this->panes->editor->isAnyPickerOpen()) {
            $this->panes->editor->handlePickerClick($mouse->row, $mouse->col);
            $this->tui->requestRender();

            return true;
        }

        // The floating Find-in-Files modal, after the menus: ×, drags and
        // zone clicks land in screen coordinates; an outside click closes it
        // (its own semantics — unlike the Welcome modal it is dismissable).
        if ($this->panes->editor->isFindModalOpen()) {
            $this->panes->editor->findModal->clickAt($mouse->row, $mouse->col);
            $this->tui->requestRender();

            return true;
        }

        // The Welcome modal, after the menus (the menu bar stays reachable over
        // it): ×/drag/select inside the box, swallowed everywhere else.
        return $this->welcome->press($mouse);
    }

    /** A left press on the panes, tried front-to-back; the editor is the final target. */
    private function handlePaneClick(MouseEvent $mouse, ?WidgetRect $treeRect, ?WidgetRect $tabsRect, ?WidgetRect $editorRect): void
    {
        if ($this->pointers->of($this->views->activeId())->press($mouse)) {
            return;
        }
        if ($this->tryOverlayPanelClick($mouse)) {
            return;
        }
        if ($this->tryChromeClick($mouse, $tabsRect, $treeRect)) {
            return;
        }
        // A pane holds the editor slot on its tabs: a press focuses it, and what
        // else the press MEANS is the pane's answer — the help pane follows a
        // link, the graph pane arms a drag-pan, the image pane wants only focus.
        $pane = $this->panes->pane();
        $paneRect = null === $pane ? null : $this->hit->rect($pane);
        if (null !== $pane && null !== $paneRect && $paneRect->contains($mouse->row, $mouse->col)) {
            $this->tui->setFocus($pane);
            $rel = $paneRect->toRelative($mouse->row, $mouse->col);
            if ($pane->press($rel['row'], $rel['col'])) {
                $this->paneDrag = ['row' => $mouse->row, 'col' => $mouse->col, 'moved' => false];
            }
            $this->tui->requestRender();

            return;
        }
        $this->handleEditorClick($mouse, $editorRect);
    }

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

    /** The floating bottom/overlay panels: terminal, results and go-to-file. */
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
            $panel->clickRow($rel['row']);
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
            if (null !== ($step = $this->panes->tabBar->scrollIndicatorAt($rel['row'], $rel['col']))) {
                $this->panes->tabBar->scrollBy($step);
                $this->tui->requestRender();

                return true;
            }
            if (null !== ($closeIndex = $this->panes->tabBar->closeIndexAt($rel['row'], $rel['col']))) {
                if (\array_key_exists($closeIndex, $buffers)) {
                    $this->bufferService->closeTab($buffers[$closeIndex]->path);
                }

                return true;
            }
            $index = $this->panes->tabBar->tabIndexAt($rel['row'], $rel['col']);
            if (null !== $index && \array_key_exists($index, $buffers)) {
                $this->buffers->setActive($buffers[$index]->path);
                $this->bufferService->showBuffer($buffers[$index]);
                $this->bufferService->focusEditorArea(); // an image tab focuses the viewer
                $this->tabDragging = true;
                $this->tabDragPath = $buffers[$index]->path;
                // A double-click reveals the file in the sidebar tree, selected
                // and highlighted, while focus stays in the editor.
                if (2 === $this->clicks->countAt($mouse->row, $mouse->col)) {
                    $this->panes->tree->revealPath($buffers[$index]->path);
                    $this->tui->requestRender();
                }
            }

            return true;
        }

        // Find-bar click: the option tags toggle, exactly as their keys do.
        if ($this->panes->findBar->isOpen() && null !== ($rel = $this->hit->hit($this->panes->findBar, $mouse->row, $mouse->col))) {
            $this->panes->findBar->handleClick($rel['row'], $rel['col']);
            $this->tui->requestRender();

            return true;
        }

        // Breadcrumbs click: jump the caret to the clicked scope's declaration.
        // The bar renders zero rows without a trail (help/image tabs,
        // structureless files) but the renderer keeps its last non-empty rect,
        // so the click only counts when a segment is actually under the
        // pointer — otherwise it falls through to whatever now owns the row.
        $rel = $this->hit->hit($this->panes->breadcrumbs, $mouse->row, $mouse->col);
        $line = null === $rel ? null : $this->panes->breadcrumbs->lineAtColumn($rel['col']);
        if (null !== $line) {
            $this->tui->setFocus($this->panes->editor);
            $this->panes->editor->jumpTo($line, 0);
            $this->tui->requestRender();

            return true;
        }

        // Tree click: select + open/toggle, or — with Ctrl or Shift — build a
        // selection of several files without opening any of them.
        if (null !== $treeRect && $treeRect->contains($mouse->row, $mouse->col)) {
            $rel = $treeRect->toRelative($mouse->row, $mouse->col);
            $this->tui->setFocus($this->panes->tree);
            // One call, because a press that defers a folder's toggle MUST arm
            // a drag to spend it: pressRow resolves what a drag would carry
            // before its own click runs, so the ordering is structural rather
            // than a comment, and "is this press modified" is derived once.
            $this->treeDrag->arm($this->panes->tree->pressRow($rel['row'], match (true) {
                $mouse->ctrl => ClickIntent::Add,
                $mouse->shift => ClickIntent::Range,
                default => ClickIntent::Open,
            }));
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
        $this->tui->setFocus($this->panes->editor);
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
            $this->panes->editor->clearExtraCarets();
            $this->panes->editor->clickAt($rel['row'], $rel['col']);
            $this->panes->editor->runAction('goto_definition');

            return;
        }
        if ($mouse->alt && $mouse->shift) {
            $this->panes->editor->beginColumnSelectionAt($rel['row'], $rel['col']);
            $this->columnSelecting = true;

            return;
        }
        if ($mouse->alt) {
            $this->panes->editor->addOrRemoveCaretAt($rel['row'], $rel['col']);

            return;
        }
        if ($rel['col'] < $this->panes->editor->gutterWidth() && null !== ($docLine = $this->panes->editor->documentLineAt($rel['row']))) {
            $this->gutterClick($rel['col'], $docLine);

            return;
        }
        $this->panes->editor->clearExtraCarets();
        $count = $this->clicks->countAt($mouse->row, $mouse->col);
        if ($mouse->shift) {
            $this->panes->editor->extendSelectionTo($rel['row'], $rel['col']);
        }
        if (!$mouse->shift && $count >= 3) {
            $this->panes->editor->selectLineAt($rel['row'], $rel['col']);
        }
        if (!$mouse->shift && 2 === $count) {
            $this->panes->editor->selectWordAt($rel['row'], $rel['col']);
        }
        if (!$mouse->shift && $count < 2) {
            $this->panes->editor->beginSelectionAt($rel['row'], $rel['col']);
        }
        $this->mouseSelecting = true;
    }

    /**
     * A plain click in the gutter: the fold column folds that region, every
     * other column toggles a breakpoint. `toggle_fold` works at the caret, so
     * the caret lands on the line first — which is what a fold click should do
     * anyway.
     */
    private function gutterClick(int $col, int $docLine): void
    {
        if ($col !== $this->panes->editor->viewport()->foldCol) {
            $this->debug->toggleBreakpointAtDocLine($docLine);

            return;
        }
        $this->panes->editor->jumpTo($docLine, 0);
        $this->panes->editor->runAction('toggle_fold');
    }
}
