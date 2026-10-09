<?php

declare(strict_types=1);

namespace Porta\Input;

use Porta\Container\LayoutSlot;
use Porta\Editor\Buffer;
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
        private readonly ModalWindowRouter $windows,
        private readonly BidiTui $bidi,
        private readonly GutterClickRouter $gutter,
        #[LayoutSlot('separator')]
        private readonly SeparatorWidget $separator,
        private readonly HoverRouter $hover,
        private readonly ContextMenuRouter $rightClick,
        private readonly TreeDragRouter $treeDrag,
        private readonly ToastCenter $toasts,
        private readonly WheelRouter $wheel,
        private readonly StatusBarClickRouter $statusClick,
        private readonly BreadcrumbsClickRouter $crumbClick,
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
        $activeView = null;
        $viewSepRect = null;
        $viewLeftRect = null;

        // While a view window is open the tree, tabs and editor stay mounted
        // behind it, dimmed — and out of reach: the window is modal, so a press
        // beside it closes it rather than landing on them. Null their rects so
        // no branch below routes an event to them.
        if ($this->views->isAnyOpen()) {
            $treeRect = null;
            $tabsRect = null;
            $editorRect = null;
            $sepRect = null;
            // The open view's drag column and left edge. Core hit-tests its OWN
            // widgets — it minted these regions — so the two verbs the views used
            // to expose for this are gone rather than shared, and one pair of rects
            // replaces a pair per view core happened to know about.
            $activeView = $this->views->activeId();
            $viewRegion = null === $activeView ? null : $this->regions->find($activeView);
            if (null !== $viewRegion) {
                $viewSepRect = $this->hit->rect($viewRegion->separator());
                $viewLeftRect = $this->hit->rect($viewRegion->sidebar());
            }
        }

        match (true) {
            // The Welcome modal is app-modal: every wheel is its while open.
            $mouse->wheel && $this->windows->isModalOpen() => $this->wheelModal($mouse),
            $mouse->wheel => $this->wheel->route($mouse, $treeRect, $tabsRect, $editorRect),
            // Bare hover-move (mouse mode 1003): track the row under the pointer in
            // whichever list popup/panel is open, like PhpStorm's menu hovering.
            $mouse->isMove() => $this->hover->handle($mouse, $editorRect, $this->views->activeId()),
            $this->continueDrag($mouse, $treeRect, $tabsRect, $editorRect, $viewLeftRect) => null,
            default => $this->press($mouse, $treeRect, $tabsRect, $editorRect, $sepRect, $activeView, $viewSepRect),
        };
    }

    private function wheelModal(MouseEvent $mouse): void
    {
        $this->windows->wheel(new WheelGesture($mouse, WheelAxis::Vertical, WheelStep::rows($mouse)));
        $this->tui->requestRender();
    }

    /**
     * Drags in progress (text/column selection, tab reorder, pane resize)
     * consume the event wherever the pointer now is.
     */
    private function continueDrag(MouseEvent $mouse, ?WidgetRect $treeRect, ?WidgetRect $tabsRect, ?WidgetRect $editorRect, ?WidgetRect $viewLeftRect): bool
    {
        return (
            $this->continueTextDrag($mouse, $editorRect)
            || $this->continueColumnDrag($mouse, $editorRect)
            || $this->continueTabDrag($mouse, $tabsRect)
            || $this->continuePaneDrag($mouse)
            || $this->continueDockTabDrag($mouse)
            || $this->treeDrag->continueDrag($mouse, $treeRect, $editorRect)
            || $this->pointers->of($this->views->activeId())->continueDrag($mouse)
            || $this->windows->continueDrag($mouse)
            || $this->continueWindowDrag($mouse)
            || $this->layout->continueResizeDrag($mouse, $treeRect, $viewLeftRect)
        );
    }

    /** A press, once no drag took the event: the right button's menu, or the left's click. */
    private function press(
        MouseEvent $mouse,
        ?WidgetRect $treeRect,
        ?WidgetRect $tabsRect,
        ?WidgetRect $editorRect,
        ?WidgetRect $sepRect,
        ?string $activeView,
        ?WidgetRect $viewSepRect,
    ): void {
        // Right-click opens a context menu for whichever pane is under the pointer
        // — swallowed while the Welcome modal is open (no context menus over it).
        if ($mouse->isRightPress()) {
            if (!$this->windows->isModalOpen()) {
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
        $this->windows->cancelDrag();
        $this->panes->editor->cancelWindowDrags();
        $this->layout->cancelDrags();

        $taken =
            // The toast stack floats above every modal: its ✖ dismisses, a card
            // body swallows the click, anywhere else falls straight through.
            ($this->toasts->clickAt($mouse->row, $mouse->col) && $this->rendered())
            || $this->handleModalPopupClick($mouse, $editorRect, $this->views->isAnyOpen() ? FullView::Open : FullView::Closed)
            // The status bar's own cells: problem counts, caret position, LSP
            // status, a cell's menu (the branch), and the file part, whose
            // double-click reveals the file in the tree. Below the modals — an open
            // dropdown may cover the row — and above the pane hit-tests, which do
            // not reach the chrome anyway.
            || $this->statusClick->route($mouse, $this->rightClick->openStatusMenu(...), $this->clicks)
            // Separator drags: a press on the tree↔editor separator column, or on the top edge
            // of the terminal/results panel, arms a resize (must beat the pane hit-tests below).
            || $this->layout->armSidebarResize($sepRect, $mouse)
            // The editor↔preview divider, while a previewed file shows in Split.
            || $this->layout->armPreviewResize($mouse)
            || (null !== $activeView && $this->layout->armViewSidebarResize($activeView, $viewSepRect, $mouse))
            // The dock strip is both the tool tabs and the resize grab: a click
            // on a tab wins, a click on its empty tail falls through to resize
            // (which sits on the strip's top row — the dock's top edge).
            || $this->tryDockTabClick($mouse)
            || (null !== ($panel = $this->dock->visiblePanel()) && $this->layout->armPanelResize($panel, $mouse, $this->dock->stripVisible() ? -$this->dockTabs->stripRows() : 0));
        if (!$taken) {
            $this->handlePaneClick($mouse, $treeRect, $tabsRect, $editorRect);
        }
    }

    /**
     * Continue an armed move or resize of Find in Files or a picker in screen
     * coordinates — they float over the whole workspace, so no pane clamps
     * the pointer; each window's frame clamps its box. False when nothing is
     * armed.
     */
    private function continueWindowDrag(MouseEvent $mouse): bool
    {
        return ($mouse->isLeftDrag() || $mouse->isLeftRelease()) && $this->panes->editor->continueWindowDrag($mouse->row, $mouse->col, $mouse->isLeftRelease()) && $this->rendered();
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
        if (null === $this->paneDrag || null === $pane || (!$mouse->isLeftDrag() && !$mouse->isLeftRelease())) {
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
        if (!$this->mouseSelecting || (!$mouse->isLeftDrag() && !$mouse->isLeftRelease())) {
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
        if (!$this->columnSelecting || (!$mouse->isLeftDrag() && !$mouse->isLeftRelease())) {
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
        if (!$this->tabDragging || (!$mouse->isLeftDrag() && !$mouse->isLeftRelease())) {
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
        return $this->clickedCompletion($mouse, $editorRect, $fullViewOpen) || $this->clickedMenu($mouse) || $this->clickedFloating($mouse);
    }

    /**
     * The completion popup is unreachable while a view window covers the
     * editor; only the menus below can claim the click. The floating pickers
     * get their screen-coords branch after the menus — dropdowns paint above them.
     */
    private function clickedCompletion(MouseEvent $mouse, ?WidgetRect $editorRect, FullView $fullViewOpen): bool
    {
        if (
            FullView::Closed !== $fullViewOpen
            || null === $this->panes->editor->getContext() // detached under an image tab → no invisible popup may eat clicks
            || !$this->panes->editor->isCompletionOpen()
            || null === $editorRect
            || !$editorRect->contains($mouse->row, $mouse->col)
        ) {
            return false;
        }
        $rel = $editorRect->toRelative($mouse->row, $mouse->col);

        return $this->panes->editor->handlePopupClick($rel['row'], $rel['col']) && $this->rendered();
    }

    /** An open context menu, a menu-bar title, an open dropdown: what a click on a menu does. */
    private function clickedMenu(MouseEvent $mouse): bool
    {
        return match (true) {
            $this->contextMenu->isOpen() => $this->clickContextMenu($mouse),
            // Menu bar: click a title to open (or switch) its dropdown.
            null !== ($rel = $this->hit->hit($this->menuBar, $mouse->row, $mouse->col)) => $this->clickMenuTitle($rel['col']),
            $this->menuBar->isOpen() => $this->clickMenuDropdown($mouse),
            default => false,
        };
    }

    /**
     * An open context menu: a click on an item runs it; a click on the
     * frame's border cells is swallowed (a 1-cell mis-click must not
     * dismiss); a click anywhere else dismisses it (the panel floats over
     * the panes, so hit-test its region).
     */
    private function clickContextMenu(MouseEvent $mouse): bool
    {
        $region = $this->overlay->contextMenuDropdown();
        $item = $this->hit->hitRegion($region, $mouse->row, $mouse->col);
        if (null !== $item) {
            $this->contextMenu->runItem($item);
        }
        if (null === $item && true !== $region?->contains($mouse->row, $mouse->col)) {
            $this->contextMenu->close();
        }

        return $this->rendered();
    }

    private function clickMenuTitle(int $col): bool
    {
        if (null !== ($title = $this->menuBar->titleAtColumn($col))) {
            $closeOpenTitle = $this->menuBar->isDropdownShown() && $this->menuBar->openIndex() === $title;
            if ($closeOpenTitle) {
                $this->menuBar->close(); // second click on the open title → close
            }
            if (!$closeOpenTitle) {
                $this->menuBar->open($title); // open, or switch to a different title
                $this->tui->setFocus($this->menuBar);
            }
        }

        return $this->rendered();
    }

    /**
     * Dropdown item: the panel floats over the editor pane, outside the menu
     * bar's rect, so hit-test the tracked overlay region directly. A click
     * on the frame's border cells is swallowed, not a dismissal.
     */
    private function clickMenuDropdown(MouseEvent $mouse): bool
    {
        $region = $this->overlay->menuDropdown();
        $item = $this->hit->hitRegion($region, $mouse->row, $mouse->col);
        if (null !== $item) {
            $this->menuBar->runItem($item);
        }
        if (null === $item && true !== $region?->contains($mouse->row, $mouse->col)) {
            $this->menuBar->close(); // a click anywhere else dismisses an open menu
        }

        return $this->rendered();
    }

    /** What floats over the panes, after the menus: the pickers, Find-in-Files, the Welcome modal and the view window's frame. */
    private function clickedFloating(MouseEvent $mouse): bool
    {
        // The floating pickers (list overlay, Search Everywhere, the mini
        // prompt): item/✖/outside-dismiss in screen coordinates. No
        // attachment guard — the pickers are painted above every pane, so a
        // click on a visible box belongs to it wherever the editor is.
        $picker = $this->panes->editor->isAnyPickerOpen();
        if (!$picker && !$this->panes->editor->isFindModalOpen()) {
            // The Welcome modal, then the view window's frame (the menu bar stays
            // reachable over both): the ✖, a drag or a click outside. A press on
            // the view window's content falls through to the view's own pointer.
            return $this->windows->press($mouse);
        }
        // The floating Find-in-Files modal: ✖, drags and zone clicks land in
        // screen coordinates; an outside click closes it (its own semantics —
        // unlike the Welcome modal it is dismissable).
        $picker ? $this->panes->editor->handlePickerClick($mouse->row, $mouse->col) : $this->panes->editor->findModal->clickAt($mouse->row, $mouse->col);

        return $this->rendered();
    }

    /** A click that changed what is painted asks for a frame: true, for the caller that asks whether it was taken. */
    private function rendered(): bool
    {
        $this->tui->requestRender();

        return true;
    }

    /** A left press on the panes, tried front-to-back; the editor is the final target. */
    private function handlePaneClick(MouseEvent $mouse, ?WidgetRect $treeRect, ?WidgetRect $tabsRect, ?WidgetRect $editorRect): void
    {
        if (
            !$this->pointers->of($this->views->activeId())->press($mouse)
            && !$this->tryOverlayPanelClick($mouse)
            && !$this->tryChromeClick($mouse, $tabsRect, $treeRect)
            && !$this->pressPane($mouse)
        ) {
            $this->handleEditorClick($mouse, $editorRect);
        }
    }

    /**
     * A pane holds the editor slot on its tabs: a press focuses it, and what
     * else the press MEANS is the pane's answer — the help pane follows a
     * link, the graph pane arms a drag-pan, the image pane wants only focus.
     */
    private function pressPane(MouseEvent $mouse): bool
    {
        $pane = $this->panes->pane();
        $paneRect = null === $pane ? null : $this->hit->rect($pane);
        if (null === $pane || null === $paneRect || !$paneRect->contains($mouse->row, $mouse->col)) {
            return false;
        }
        $this->tui->setFocus($pane);
        $rel = $paneRect->toRelative($mouse->row, $mouse->col);
        if ($pane->press($rel['row'], $rel['col'])) {
            $this->paneDrag = ['row' => $mouse->row, 'col' => $mouse->col, 'moved' => false];
        }

        return $this->rendered();
    }

    /** A press on the dock strip: select/close a tool tab, or false to fall through to resize. */
    private function tryDockTabClick(MouseEvent $mouse): bool
    {
        $rect = $this->hit->rect($this->dockTabs);
        if (!$this->dock->stripVisible() || null === $rect || !$rect->contains($mouse->row, $mouse->col)) {
            return false;
        }
        $col = $mouse->col - $rect->col;
        // A click on an overflow ‹/› pages the strip, before any tab hit —
        // mirroring the editor tab bar.
        $step = $this->dockTabs->scrollIndicatorAt($mouse->row - $rect->row, $col);
        if (null !== $step) {
            $this->dockTabs->scrollBy($step);
            $this->tui->requestRender();
        }

        return null !== $step || $this->clickDockTab($col);
    }

    /** A tab or its ✖: true when the click hit one. */
    private function clickDockTab(int $col): bool
    {
        // Resolve the drag source BEFORE the click runs — a close click mutates
        // the strip. dragSourceAt is null on a close ✖, so only a select arms.
        $source = $this->dockTabs->dragSourceAt($col);
        $taken = $this->dockTabs->handleTabClick($col);
        if ($taken) {
            $this->dockTabDrag = $source;
        }

        return $taken;
    }

    /**
     * Dock-strip tab reordering: a left-drag started on a tab body moves it
     * under the pointer, within its own family (session tabs among
     * themselves, tool tabs among themselves).
     */
    private function continueDockTabDrag(MouseEvent $mouse): bool
    {
        if (null === $this->dockTabDrag || (!$mouse->isLeftDrag() && !$mouse->isLeftRelease())) {
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
        return (
            $this->clickedTabs($mouse, $tabsRect)
            || $this->clickedFindBar($mouse)
            // Navigation bar click: a toggle, a path crumb or a scope crumb.
            || $this->crumbClick->route($mouse)
            || $this->clickedTree($mouse, $treeRect)
        );
    }

    /**
     * Tab bar click: the close ✖ closes that tab; the body switches to it and
     * arms a drag-to-reorder.
     */
    private function clickedTabs(MouseEvent $mouse, ?WidgetRect $tabsRect): bool
    {
        if (null === $tabsRect || !$tabsRect->contains($mouse->row, $mouse->col)) {
            return false;
        }
        $rel = $tabsRect->toRelative($mouse->row, $mouse->col);
        $buffers = $this->buffers->all();
        $step = $this->panes->tabBar->scrollIndicatorAt($rel['row'], $rel['col']);
        $closeIndex = null === $step ? $this->panes->tabBar->closeIndexAt($rel['row'], $rel['col']) : null;
        $index = null === $step && null === $closeIndex ? $this->panes->tabBar->tabIndexAt($rel['row'], $rel['col']) : null;
        if (null !== $step) {
            $this->panes->tabBar->scrollBy($step);
            $this->tui->requestRender();
        }
        if (null !== $closeIndex && \array_key_exists($closeIndex, $buffers)) {
            $this->bufferService->closeTab($buffers[$closeIndex]->path);
        }
        if (null !== $index && \array_key_exists($index, $buffers)) {
            $this->switchToTab($buffers[$index], $mouse);
        }

        return true;
    }

    private function switchToTab(Buffer $buffer, MouseEvent $mouse): void
    {
        $this->buffers->setActive($buffer->path);
        $this->bufferService->showBuffer($buffer);
        $this->bufferService->focusEditorArea(); // an image tab focuses the viewer
        $this->tabDragging = true;
        $this->tabDragPath = $buffer->path;
        // A double-click reveals the file in the sidebar tree, selected
        // and highlighted, while focus stays in the editor.
        if (2 === $this->clicks->countAt($mouse->row, $mouse->col)) {
            $this->panes->tree->revealPath($buffer->path);
            $this->tui->requestRender();
        }
    }

    /** Find-bar click: the option tags toggle, exactly as their keys do. */
    private function clickedFindBar(MouseEvent $mouse): bool
    {
        if (!$this->panes->findBar->isOpen() || null === ($rel = $this->hit->hit($this->panes->findBar, $mouse->row, $mouse->col))) {
            return false;
        }
        $this->panes->findBar->handleClick($rel['row'], $rel['col']);

        return $this->rendered();
    }

    /**
     * Tree click: select + open/toggle, or — with Ctrl or Shift — build a
     * selection of several files without opening any of them.
     */
    private function clickedTree(MouseEvent $mouse, ?WidgetRect $treeRect): bool
    {
        if (null === $treeRect || !$treeRect->contains($mouse->row, $mouse->col)) {
            return false;
        }
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

        return $this->rendered();
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
        $editor = $this->panes->editor;
        match (true) {
            $mouse->ctrl && !$mouse->alt && !$mouse->shift => $this->ctrlClickAt($rel['row'], $rel['col']),
            $mouse->alt => $this->altClickAt($rel['row'], $rel['col'], $mouse->shift),
            $rel['col'] < $editor->gutterWidth() && null !== ($docLine = $editor->documentLineAt($rel['row'])) => $this->gutter->click($rel['col'], $docLine),
            default => $this->pressTextAt($mouse, $rel['row'], $rel['col']),
        };
    }

    /**
     * Place the caret at the click (fold/scroll-aware), then fire the same
     * action as Ctrl+B. A ctrl-click is a navigation, not a selection start,
     * so no drag is armed.
     */
    private function ctrlClickAt(int $row, int $col): void
    {
        $this->panes->editor->clearExtraCarets();
        $this->panes->editor->clickAt($row, $col);
        $this->panes->editor->runAction('goto_definition');
    }

    /** Alt+Shift starts a column selection; Alt alone adds or removes a caret. */
    // @mago-expect lint:no-boolean-flag-parameter -- the modifier IS the one fact the press carries, as MouseEvent holds it.
    private function altClickAt(int $row, int $col, bool $shift): void
    {
        if ($shift) {
            $this->panes->editor->beginColumnSelectionAt($row, $col);
            $this->columnSelecting = true;

            return;
        }
        $this->panes->editor->addOrRemoveCaretAt($row, $col);
    }

    /** A press on the text: extend with Shift, else select by click count, and arm the drag. */
    private function pressTextAt(MouseEvent $mouse, int $row, int $col): void
    {
        $editor = $this->panes->editor;
        $editor->clearExtraCarets();
        $count = $this->clicks->countAt($mouse->row, $mouse->col);
        if ($mouse->shift) {
            $editor->extendSelectionTo($row, $col);
        }
        if (!$mouse->shift && $count >= 3) {
            $editor->selectLineAt($row, $col);
        }
        if (!$mouse->shift && 2 === $count) {
            $editor->selectWordAt($row, $col);
        }
        if (!$mouse->shift && $count < 2) {
            $editor->beginSelectionAt($row, $col);
        }
        $this->mouseSelecting = true;
    }
}
