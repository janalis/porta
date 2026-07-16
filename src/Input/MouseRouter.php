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
use Porta\Ui\ContextMenuBuilder;
use Porta\Ui\WorkspaceViews;
use Porta\Widget\ContextMenu;
use Porta\Widget\MenuBarWidget;
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
 */
final class MouseRouter
{
    /** True while a left-drag started in the editor is extending a text selection. */
    private bool $mouseSelecting = false;
    /** True while an Alt+Shift left-drag is building a column (rectangular) selection. */
    private bool $columnSelecting = false;
    /** True while a left-drag started on a tab is reordering it; $tabDragPath is the dragged buffer. */
    private bool $tabDragging = false;
    private ?string $tabDragPath = null;

    /** Double/triple-click detection, kept out of the routing logic. */
    private readonly ClickCounter $clicks;

    public function __construct(
        private readonly HitTester $hit,
        private readonly Tui $tui,
        private readonly EditorLayoutPanes $panes,
        private readonly OverlayPanels $overlayPanels,
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
        private readonly BidiTui $bidi,
        private readonly DebugController $debug,
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

        // While a full view (git or settings) owns the main area, the
        // tree/tabs/editor (and the menu bar inside the editor pane) are
        // detached — but the renderer still holds their last-frame rects. Null
        // them so no stale-rect branch routes events to an invisible widget.
        $gitViewOpen = $this->views->isGitOpen();
        $settingsViewOpen = $this->views->isSettingsOpen();
        if ($gitViewOpen || $settingsViewOpen) {
            $treeRect = null;
            $tabsRect = null;
            $editorRect = null;
        }
        // The git/settings sidebars' drag columns and left edges (each router
        // owns its widgets; the rects exist only while its view is swapped in).
        $gitSepRect = $gitViewOpen ? $this->git->separatorRect() : null;
        $gitLeftRect = $gitViewOpen ? $this->git->leftRect() : null;
        $settingsSepRect = $settingsViewOpen ? $this->settings->separatorRect() : null;
        $settingsLeftRect = $settingsViewOpen ? $this->settings->leftRect() : null;

        if ($mouse->wheel) {
            $this->handleWheel($mouse, $treeRect, $tabsRect, $editorRect);

            return;
        }

        // Bare hover-move (mouse mode 1003): track the row under the pointer in
        // whichever list popup/panel is open, like PhpStorm's menu hovering.
        if ($mouse->isMove()) {
            $this->handleHover($mouse, $editorRect, match (true) {
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
            || $this->git->continueDiffDrag($mouse)
            || $this->layout->continueResizeDrag($mouse, $treeRect, $gitLeftRect, $settingsLeftRect)
        ) {
            return;
        }

        // Right-click opens a context menu for whichever pane is under the pointer.
        if ($mouse->isRightPress()) {
            $this->handleRightPress($mouse, $treeRect, $tabsRect, $editorRect);

            return;
        }

        if (!$mouse->isLeftPress()) {
            return;
        }
        // A fresh press ends any prior drag arming; only an editor/tab press re-arms it.
        $this->mouseSelecting = false;
        $this->columnSelecting = false;
        $this->tabDragging = false;
        $this->git->cancelDrag();
        $this->layout->cancelDrags();

        if ($this->handleModalPopupClick($mouse, $editorRect, $gitViewOpen || $settingsViewOpen ? FullView::Open : FullView::Closed)) {
            return;
        }

        // Separator drags: a press on the tree↔editor gap column, or on the top edge
        // of the terminal/results panel, arms a resize (must beat the pane hit-tests below).
        if ($this->layout->armSidebarResize($treeRect, $mouse)) {
            return;
        }
        if ($this->layout->armGitSidebarResize($gitSepRect, $mouse)) {
            return;
        }
        if ($this->layout->armSettingsSidebarResize($settingsSepRect, $mouse)) {
            return;
        }
        // The terminal's top row is both the tab strip and the resize grab: a
        // click on a tab wins, a click on its empty tail falls through to resize.
        if ($this->tryTerminalTabClick($mouse)) {
            return;
        }
        if ($this->layout->armPanelResize($this->overlayPanels->terminalPanel(), $mouse)) {
            return;
        }
        if ($this->layout->armPanelResize($this->overlayPanels->resultsPanel(), $mouse)) {
            return;
        }
        if ($this->layout->armPanelResize($this->overlayPanels->debugPanel(), $mouse)) {
            return;
        }

        $this->handlePaneClick($mouse, $treeRect, $tabsRect, $editorRect);
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
                $this->panes->editor()->scrollHBy(6 * $mouse->wheelHDelta);
                $this->tui->requestRender();

                return;
            }
            if ($this->containsPointer($imageRect, $mouse)) {
                $this->panes->imageViewer()->scrollBy(6 * $mouse->wheelHDelta, 0);
                $this->tui->requestRender();
            }

            return;
        }

        // The tab strip: a wheel over it pages through overflowing tabs — one
        // wide tab per notch on the horizontal strip, the panel-list three
        // rows per notch on the vertical one.
        if ($this->containsPointer($tabsRect, $mouse)) {
            $tabBar = $this->panes->tabBar();
            $tabBar->scrollBy(($tabBar->position()->isSide() ? 3 : 1) * $mouse->wheelDelta);
            $this->tui->requestRender();

            return;
        }

        // The Find modal composites inside the editor pane and owns input while
        // open — scroll its preview instead of the editor behind it.
        if ($this->containsPointer($editorRect, $mouse) && $this->panes->editor()->isFindModalOpen()) {
            $this->panes->editor()->scrollFindModal(3 * $mouse->wheelDelta);
            $this->tui->requestRender();

            return;
        }

        // The results tool window (its own bottom rect).
        $resultsRect = $this->hit->rect($this->overlayPanels->resultsPanel());
        if ($this->overlayPanels->resultsPanel()->isOpen() && $this->containsPointer($resultsRect, $mouse)) {
            $this->overlayPanels->resultsPanel()->scrollBy(3 * $mouse->wheelDelta);
            $this->tui->requestRender();

            return;
        }

        // The debug panel scrolls its frames/variables list the same way.
        $debugRect = $this->hit->rect($this->overlayPanels->debugPanel());
        if ($this->overlayPanels->debugPanel()->isOpen() && $this->containsPointer($debugRect, $mouse)) {
            $this->overlayPanels->debugPanel()->scrollBy(3 * $mouse->wheelDelta);
            $this->tui->requestRender();

            return;
        }

        $step = 3 * $mouse->wheelDelta;
        $terminalRect = $this->hit->rect($this->overlayPanels->terminalPanel());
        if ($this->overlayPanels->terminalPanel()->isOpen() && $this->containsPointer($terminalRect, $mouse)) {
            $this->overlayPanels->terminalPanel()->scroll($mouse->wheelDelta < 0 ? 3 : -3); // wheel up → into history
            $this->tui->requestRender();

            return;
        }
        if ($this->containsPointer($editorRect, $mouse)) {
            $this->panes->editor()->scrollBy($step);
            $this->tui->requestRender();

            return;
        }
        if (null !== $imageRect && $imageRect->contains($mouse->row, $mouse->col)) {
            // Plain wheel pans the image; Ctrl+wheel zooms toward the pointer.
            $rel = $imageRect->toRelative($mouse->row, $mouse->col);
            $mouse->ctrl
                ? $this->panes->imageViewer()->zoomAt($mouse->wheelDelta < 0 ? 1 : -1, $rel['col'], $rel['row'])
                : $this->panes->imageViewer()->scrollBy(0, 2 * $step);
            $this->tui->requestRender();

            return;
        }
        if (null !== ($helpRect = $this->hit->rect($this->panes->helpViewer())) && $helpRect->contains($mouse->row, $mouse->col)) {
            $this->panes->helpViewer()->scrollBy($step);
            $this->tui->requestRender();

            return;
        }
        if ($this->containsPointer($treeRect, $mouse)) {
            $key = $mouse->wheelDelta < 0 ? "\x1b[A" : "\x1b[B";
            for ($i = 0; $i < 3; ++$i) {
                $this->panes->tree()->handleInput($key);
            }
            $this->tui->requestRender();

            return;
        }
        if (!$this->git->scrollVertical($mouse, $step) && !$this->settings->scrollVertical($mouse)) {
            // Neither the git panes nor the settings list are under the pointer.
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
            && ($this->panes->editor()->isOverlayOpen() || $this->panes->editor()->isCompletionOpen() || $this->panes->editor()->isSearchOverlayOpen() || $this->panes->editor()->isFindModalOpen())
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
                && ($this->panes->editor()->isOverlayOpen() || $this->panes->editor()->isSearchOverlayOpen() || $this->panes->editor()->isFindModalOpen())
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
    /** A press on the terminal tab strip (row 0): select/close a tab, or false to fall through to resize. */
    private function tryTerminalTabClick(MouseEvent $mouse): bool
    {
        $panel = $this->overlayPanels->terminalPanel();
        $rect = $this->hit->rect($panel);
        if (!$panel->isOpen() || null === $rect || $mouse->row !== $rect->row || $mouse->col < $rect->col || $mouse->col >= ($rect->col + $rect->columns)) {
            return false;
        }
        if (!$panel->handleTabClick($mouse->col - $rect->col)) {
            return false;
        }
        $this->tui->setFocus($panel);
        $this->tui->requestRender();

        return true;
    }

    private function tryOverlayPanelClick(MouseEvent $mouse): bool
    {
        // A click in the open terminal pane focuses it, so typing reaches the
        // shell. (The tab strip on row 0 is handled before resize, in the press
        // path, so a body click here just refocuses.)
        if ($this->overlayPanels->terminalPanel()->isOpen() && null !== $this->hit->hit($this->overlayPanels->terminalPanel(), $mouse->row, $mouse->col)) {
            $this->tui->setFocus($this->overlayPanels->terminalPanel());
            $this->tui->requestRender();

            return true;
        }

        // Results panel: click a result row (file header collapse/expand or jump).
        // Focusing first gives Escape a route to close the pane; a jump refocuses the editor after.
        if ($this->overlayPanels->resultsPanel()->isOpen() && null !== ($rel = $this->hit->hit($this->overlayPanels->resultsPanel(), $mouse->row, $mouse->col))) {
            $this->tui->setFocus($this->overlayPanels->resultsPanel());
            $this->overlayPanels->resultsPanel()->handleClick($rel['row']);
            $this->tui->requestRender();

            return true;
        }

        // Debug panel: click a frame (jump) or a variable (expand/collapse).
        if ($this->overlayPanels->debugPanel()->isOpen() && null !== ($rel = $this->hit->hit($this->overlayPanels->debugPanel(), $mouse->row, $mouse->col))) {
            $this->tui->setFocus($this->overlayPanels->debugPanel());
            $this->overlayPanels->debugPanel()->handleClick($rel['row']);
            $this->tui->requestRender();

            return true;
        }

        // Go-to-file strip: click a result row to open that file.
        if ($this->overlayPanels->goToFile()->isOpen() && null !== ($rel = $this->hit->hit($this->overlayPanels->goToFile(), $mouse->row, $mouse->col))) {
            $this->overlayPanels->goToFile()->handleClick($rel['row']);
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
            }

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
     * Sidebar hover: highlight the file-tree row under the pointer, clearing it when
     * the pointer is elsewhere. Runs on every move so the highlight tracks the mouse.
     */
    private function updateTreeHover(MouseEvent $mouse): void
    {
        if ($this->hit->hover($mouse->row, $mouse->col, $this->panes->tree())) {
            $this->tui->requestRender();
        }
    }

    /**
     * Application-menu hover: an open menu highlights the dropdown item under the
     * pointer and switches to a sibling title the pointer moves onto; a closed bar
     * hover-highlights the title under the pointer (clearing when off the bar).
     * Returns whether the hover was consumed by the menu bar.
     */
    private function handleMenuHover(MouseEvent $mouse): bool
    {
        if ($this->menuBar->isOpen()) {
            if (null !== ($item = $this->hit->hitRegion($this->overlay->menuDropdown(), $mouse->row, $mouse->col))) {
                if ($this->menuBar->hoverItem($item)) {
                    $this->tui->requestRender();
                }

                return true;
            }
            if (null !== ($rel = $this->hit->hit($this->menuBar, $mouse->row, $mouse->col))) {
                $title = $this->menuBar->titleAtColumn($rel['col']);
                if (null !== $title && $title !== $this->menuBar->openIndex()) {
                    $this->menuBar->open($title);
                    $this->tui->requestRender();
                }

                return true;
            }

            return false;
        }

        // Closed: highlight the title under the pointer, clearing when off the bar.
        $rel = $this->hit->hit($this->menuBar, $mouse->row, $mouse->col);
        $hovered = null !== $rel ? $this->menuBar->titleAtColumn($rel['col']) : null;
        if ($this->menuBar->hoverTitle($hovered)) {
            $this->tui->requestRender();
        }

        return null !== $rel;
    }

    /**
     * A bare hover-move: highlight the row under the pointer in whichever list
     * popup/panel is open (editor overlay/completion, results panel, go-to-file).
     * Re-renders only when the highlighted row actually changes, so the extra
     * motion traffic from mouse mode 1003 stays cheap.
     */
    private function handleHover(MouseEvent $mouse, ?WidgetRect $editorRect, ActiveView $view = ActiveView::Editor): void
    {
        // A right-click context menu floats over every view and is modal, so it
        // gets first refusal: highlight the item under the pointer.
        if ($this->contextMenu->isOpen()) {
            if (null !== ($item = $this->hit->hitRegion($this->overlay->contextMenuDropdown(), $mouse->row, $mouse->col)) && $this->contextMenu->hoverItem($item)) {
                $this->tui->requestRender();
            }

            return;
        }

        // Each full-view state owns its hover targets exclusively; the tree/editor
        // targets are detached while a view is swapped in. The menu bar rides
        // along into either view, so it gets first refusal before the region.
        if (ActiveView::Settings === $view) {
            if (!$this->handleMenuHover($mouse)) {
                $this->settings->hover($mouse);
            }

            return;
        }
        if (ActiveView::Git === $view) {
            if (!$this->handleMenuHover($mouse)) {
                $this->git->hover($mouse);
            }

            return;
        }
        $this->handleMainHover($mouse, $editorRect);
    }

    /** Hover routing for the normal editor layout: tree, menu, editor popup, panels. */
    private function handleMainHover(MouseEvent $mouse, ?WidgetRect $editorRect): void
    {
        $this->updateTreeHover($mouse);

        if ($this->handleMenuHover($mouse)) {
            return;
        }

        if (
            ($this->panes->editor()->isOverlayOpen() || $this->panes->editor()->isCompletionOpen() || $this->panes->editor()->isSearchOverlayOpen() || $this->panes->editor()->isFindModalOpen())
            && null !== $editorRect
            && $editorRect->contains($mouse->row, $mouse->col)
        ) {
            $rel = $editorRect->toRelative($mouse->row, $mouse->col);
            if ($this->panes->editor()->handlePopupHover($rel['row'], $rel['col'])) {
                $this->tui->requestRender();
            }

            return;
        }

        if ($this->overlayPanels->resultsPanel()->isOpen() && null !== ($rel = $this->hit->hit($this->overlayPanels->resultsPanel(), $mouse->row, $mouse->col))) {
            if ($this->overlayPanels->resultsPanel()->hoverRow($rel['row'])) {
                $this->tui->requestRender();
            }

            return;
        }

        if ($this->overlayPanels->debugPanel()->isOpen() && null !== ($rel = $this->hit->hit($this->overlayPanels->debugPanel(), $mouse->row, $mouse->col))) {
            if ($this->overlayPanels->debugPanel()->hoverRow($rel['row'])) {
                $this->tui->requestRender();
            }

            return;
        }

        if ($this->overlayPanels->goToFile()->isOpen() && null !== ($rel = $this->hit->hit($this->overlayPanels->goToFile(), $mouse->row, $mouse->col))) {
            if ($this->overlayPanels->goToFile()->hoverRow($rel['row'])) {
                $this->tui->requestRender();
            }
        }
    }

    /**
     * Open the context menu for whichever pane is under a right-click: the editor
     * (edit/navigate/refactor actions), a tab (close actions) or a tree node
     * (file operations). A right-click supersedes any open application menu.
     */
    private function handleRightPress(MouseEvent $mouse, ?WidgetRect $treeRect, ?WidgetRect $tabsRect, ?WidgetRect $editorRect): void
    {
        $this->menuBar->close();

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
                return;
            }
            $items = $this->treeOps->contextItems($node);
        }
        if (null === $items) {
            return;
        }

        $this->overlay->setContextAnchor($mouse->row, $mouse->col);
        $this->contextMenu->open($items);
        $this->tui->requestRender();
    }

    /**
     * Consecutive-click count at a screen cell: 1, 2 (double) or 3 (triple),
     * within a 0.4 s window on the same cell; resets otherwise.
     */
}
