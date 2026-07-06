<?php

declare(strict_types=1);

namespace Porta\Input;

use Porta\Editor\BufferManager;
use Porta\Editor\BufferService;
use Porta\Layout\LayoutController;
use Porta\Overlay\MenuOverlayController;
use Porta\Tree\TreeOperations;
use Porta\Ui\ContextMenuBuilder;
use Porta\Widget\BreadcrumbsWidget;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\ContextMenu;
use Porta\Widget\DiffPanelWidget;
use Porta\Widget\FileTreeWidget;
use Porta\Widget\GoToFileWidget;
use Porta\Widget\MenuBarWidget;
use Porta\Widget\ResultsPanelWidget;
use Porta\Widget\TabBarWidget;
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
    private float $lastClickTime = 0.0;
    private int $lastClickRow = -1;
    private int $lastClickCol = -1;
    private int $clickCount = 0;

    public function __construct(
        private readonly HitTester $hit,
        private readonly Tui $tui,
        private readonly FileTreeWidget $tree,
        private readonly TabBarWidget $tabBar,
        private readonly CodeEditorWidget $editor,
        private readonly MenuBarWidget $menuBar,
        private readonly ContextMenu $contextMenu,
        private readonly BreadcrumbsWidget $breadcrumbs,
        private readonly TerminalPanelWidget $terminalPanel,
        private readonly ResultsPanelWidget $resultsPanel,
        private readonly GoToFileWidget $goToFile,
        private readonly DiffPanelWidget $diffPanel,
        private readonly LayoutController $layout,
        private readonly MenuOverlayController $overlay,
        private readonly BufferManager $buffers,
        private readonly BufferService $bufferService,
        private readonly TreeOperations $treeOps,
        private readonly ContextMenuBuilder $contextItems,
    ) {}

    public function route(MouseEvent $mouse): void
    {
        $treeRect = $this->hit->rect($this->tree);
        $tabsRect = $this->hit->rect($this->tabBar);
        $editorRect = $this->hit->rect($this->editor);

        if ($mouse->wheel) {
            $this->handleWheel($mouse, $treeRect, $editorRect);

            return;
        }

        // Bare hover-move (mouse mode 1003): track the row under the pointer in
        // whichever list popup/panel is open, like PhpStorm's menu hovering.
        if ($mouse->isMove()) {
            $this->handleHover($mouse, $editorRect);

            return;
        }

        // Drags in progress (text/column selection, tab reorder, pane resize)
        // consume the event wherever the pointer now is.
        if ($this->continueTextDrag($mouse, $editorRect)
            || $this->continueColumnDrag($mouse, $editorRect)
            || $this->continueTabDrag($mouse, $tabsRect)
            || $this->layout->continueResizeDrag($mouse, $treeRect)
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
        $this->layout->cancelDrags();

        if ($this->handleModalPopupClick($mouse, $editorRect)) {
            return;
        }

        // Separator drags: a press on the tree↔editor gap column, or on the top edge
        // of the terminal/diff panel, arms a resize (must beat the pane hit-tests below).
        if ($this->layout->armSidebarResize($treeRect, $mouse)) {
            return;
        }
        if ($this->layout->armPanelResize($this->terminalPanel, $mouse) || $this->layout->armPanelResize($this->diffPanel, $mouse)) {
            return;
        }

        $this->handlePaneClick($mouse, $treeRect, $tabsRect, $editorRect);
    }

    /** Wheel: scroll whichever pane is under the pointer. */
    private function handleWheel(MouseEvent $mouse, ?WidgetRect $treeRect, ?WidgetRect $editorRect): void
    {
        $step = 3 * $mouse->wheelDelta;
        $terminalRect = $this->hit->rect($this->terminalPanel);
        if ($this->terminalPanel->isOpen() && null !== $terminalRect && $terminalRect->contains($mouse->row, $mouse->col)) {
            $this->terminalPanel->scroll($mouse->wheelDelta < 0 ? 3 : -3); // wheel up → into history
        } elseif (null !== $editorRect && $editorRect->contains($mouse->row, $mouse->col)) {
            $this->editor->scrollBy($step);
        } elseif (null !== $treeRect && $treeRect->contains($mouse->row, $mouse->col)) {
            $key = $mouse->wheelDelta < 0 ? "\x1b[A" : "\x1b[B";
            for ($i = 0; $i < 3; ++$i) {
                $this->tree->handleInput($key);
            }
        } else {
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
        $row = max($rect->row, min($mouse->row, $rect->row + $rect->rows - 1));
        $col = max($rect->col, min($mouse->col, $rect->col + $rect->columns - 1));

        return $rect->toRelative($row, $col);
    }

    /**
     * Active text selection: a left-drag started in the editor keeps extending
     * on motion/release, wherever the pointer now is.
     */
    private function continueTextDrag(MouseEvent $mouse, ?WidgetRect $editorRect): bool
    {
        if (!$this->mouseSelecting || (!$mouse->isLeftDrag() && !$mouse->isLeftRelease())) {
            return false;
        }
        if (null !== $editorRect) {
            $rel = $this->clampedRelative($editorRect, $mouse);
            $this->editor->extendSelectionTo($rel['row'], $rel['col']);
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
            $this->editor->extendColumnSelectionTo($rel['row'], $rel['col']);
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
            $col = max($tabsRect->col, min($mouse->col, $tabsRect->col + $tabsRect->columns - 1));
            $rel = $tabsRect->toRelative($mouse->row, $col);
            $target = $this->tabBar->tabIndexAtColumn($rel['col']);
            if (null !== $target) {
                $order = $this->buffers->all();
                $current = null;
                foreach ($order as $i => $buffer) {
                    if ($buffer->path === $this->tabDragPath) {
                        $current = $i;
                        break;
                    }
                }
                if (null !== $current && $current !== $target) {
                    $this->buffers->reorder($current, $target);
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
    private function handleModalPopupClick(MouseEvent $mouse, ?WidgetRect $editorRect): bool
    {
        if ($this->editor->isOverlayOpen() || $this->editor->isCompletionOpen()) {
            if (null !== $editorRect && $editorRect->contains($mouse->row, $mouse->col)) {
                $rel = $editorRect->toRelative($mouse->row, $mouse->col);
                if ($this->editor->handlePopupClick($rel['row'], $rel['col'])) {
                    $this->tui->requestRender();

                    return true;
                }
            } elseif ($this->editor->isOverlayOpen()) {
                $this->editor->handlePopupClick(-1, -1); // outside the modal overlay → dismiss
                $this->tui->requestRender();

                return true;
            }
        }

        // An open context menu: a click on an item runs it; a click anywhere else
        // dismisses it (the panel floats over the panes, so hit-test its region).
        if ($this->contextMenu->isOpen()) {
            if (null !== ($item = $this->hit->hitRegion($this->overlay->contextMenuDropdown(), $mouse->row, $mouse->col))) {
                $this->contextMenu->runItem($item);
            } else {
                $this->contextMenu->close();
            }
            $this->tui->requestRender();

            return true;
        }

        // Menu bar: click a title to open (or switch) its dropdown.
        if (null !== ($rel = $this->hit->hit($this->menuBar, $mouse->row, $mouse->col))) {
            if (null !== ($title = $this->menuBar->titleAtColumn($rel['col']))) {
                if ($this->menuBar->isOpen() && $this->menuBar->openIndex() === $title) {
                    $this->menuBar->close(); // second click on the open title → close
                } else {
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
            if (null !== ($item = $this->hit->hitRegion($this->overlay->menuDropdown(), $mouse->row, $mouse->col))) {
                $this->menuBar->runItem($item);
            } else {
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
        // A click in the open terminal pane focuses it, so typing reaches the shell.
        if ($this->terminalPanel->isOpen() && null !== $this->hit->hit($this->terminalPanel, $mouse->row, $mouse->col)) {
            $this->tui->setFocus($this->terminalPanel);
            $this->tui->requestRender();

            return;
        }

        // Results panel: click an option toggle in the header or a result row.
        if ($this->resultsPanel->isOpen() && null !== ($rel = $this->hit->hit($this->resultsPanel, $mouse->row, $mouse->col))) {
            $this->resultsPanel->handleClick($rel['row'], $rel['col']);
            $this->tui->requestRender();

            return;
        }

        // Go-to-file strip: click a result row to open that file.
        if ($this->goToFile->isOpen() && null !== ($rel = $this->hit->hit($this->goToFile, $mouse->row, $mouse->col))) {
            $this->goToFile->handleClick($rel['row']);
            $this->tui->requestRender();

            return;
        }

        // Tab bar click: the close × closes that tab; the body switches to it and
        // arms a drag-to-reorder.
        if (null !== $tabsRect && $tabsRect->contains($mouse->row, $mouse->col)) {
            $rel = $tabsRect->toRelative($mouse->row, $mouse->col);
            $buffers = $this->buffers->all();
            if (null !== ($closeIndex = $this->tabBar->closeIndexAtColumn($rel['col']))) {
                if (isset($buffers[$closeIndex])) {
                    $this->bufferService->closeTab($buffers[$closeIndex]->path);
                }

                return;
            }
            $index = $this->tabBar->tabIndexAtColumn($rel['col']);
            if (null !== $index && isset($buffers[$index])) {
                $this->buffers->setActive($buffers[$index]->path);
                $this->bufferService->showBuffer($buffers[$index]);
                $this->tui->setFocus($this->editor);
                $this->tabDragging = true;
                $this->tabDragPath = $buffers[$index]->path;
            }

            return;
        }

        // Breadcrumbs click: jump the caret to the clicked scope's declaration.
        if (null !== ($rel = $this->hit->hit($this->breadcrumbs, $mouse->row, $mouse->col))) {
            if (null !== ($line = $this->breadcrumbs->lineAtColumn($rel['col']))) {
                $this->tui->setFocus($this->editor);
                $this->editor->jumpTo($line, 0);
                $this->tui->requestRender();
            }

            return;
        }

        // Tree click: select + open/toggle.
        if (null !== $treeRect && $treeRect->contains($mouse->row, $mouse->col)) {
            $rel = $treeRect->toRelative($mouse->row, $mouse->col);
            $this->tui->setFocus($this->tree);
            $this->tree->clickRow($rel['row']);
            $this->tui->requestRender();

            return;
        }

        // Editor press. Ctrl+Click navigates to the declaration (PhpStorm);
        // Alt+Shift begins a column selection; Alt toggles a caret; otherwise
        // place/extend a selection (drag extends it, double-click a word,
        // triple-click a line, Shift+click extends) — collapsing any extra carets.
        if (null !== $editorRect && $editorRect->contains($mouse->row, $mouse->col)) {
            $rel = $editorRect->toRelative($mouse->row, $mouse->col);
            $this->tui->setFocus($this->editor);

            if ($mouse->ctrl && !$mouse->alt && !$mouse->shift) {
                // Place the caret at the click (fold/scroll-aware), then fire the
                // same action as Ctrl+B. A ctrl-click is a navigation, not a
                // selection start, so no drag is armed.
                $this->editor->clearExtraCarets();
                $this->editor->clickAt($rel['row'], $rel['col']);
                $this->editor->runAction('goto_definition');
            } elseif ($mouse->alt && $mouse->shift) {
                $this->editor->beginColumnSelectionAt($rel['row'], $rel['col']);
                $this->columnSelecting = true;
            } elseif ($mouse->alt) {
                $this->editor->addOrRemoveCaretAt($rel['row'], $rel['col']);
            } else {
                $this->editor->clearExtraCarets();
                $count = $this->clickCountAt($mouse->row, $mouse->col);
                if ($mouse->shift) {
                    $this->editor->extendSelectionTo($rel['row'], $rel['col']);
                } elseif ($count >= 3) {
                    $this->editor->selectLineAt($rel['row'], $rel['col']);
                } elseif (2 === $count) {
                    $this->editor->selectWordAt($rel['row'], $rel['col']);
                } else {
                    $this->editor->beginSelectionAt($rel['row'], $rel['col']);
                }
                $this->mouseSelecting = true;
            }
            $this->tui->requestRender();
        }
    }

    /**
     * Sidebar hover: highlight the file-tree row under the pointer, clearing it when
     * the pointer is elsewhere. Runs on every move so the highlight tracks the mouse.
     */
    private function updateTreeHover(MouseEvent $mouse): void
    {
        $rel = $this->hit->hit($this->tree, $mouse->row, $mouse->col);
        $changed = null !== $rel
            ? $this->tree->hoverRow($rel['row'])
            : $this->tree->clearHover();
        if ($changed) {
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
    private function handleHover(MouseEvent $mouse, ?WidgetRect $editorRect): void
    {
        $this->updateTreeHover($mouse);

        if ($this->handleMenuHover($mouse)) {
            return;
        }

        if (($this->editor->isOverlayOpen() || $this->editor->isCompletionOpen()) && null !== $editorRect && $editorRect->contains($mouse->row, $mouse->col)) {
            $rel = $editorRect->toRelative($mouse->row, $mouse->col);
            if ($this->editor->handlePopupHover($rel['row'], $rel['col'])) {
                $this->tui->requestRender();
            }

            return;
        }

        if ($this->resultsPanel->isOpen() && null !== ($rel = $this->hit->hit($this->resultsPanel, $mouse->row, $mouse->col))) {
            if ($this->resultsPanel->hoverRow($rel['row'])) {
                $this->tui->requestRender();
            }

            return;
        }

        if ($this->goToFile->isOpen() && null !== ($rel = $this->hit->hit($this->goToFile, $mouse->row, $mouse->col))) {
            if ($this->goToFile->hoverRow($rel['row'])) {
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

        if (null !== $editorRect && $editorRect->contains($mouse->row, $mouse->col)) {
            $rel = $editorRect->toRelative($mouse->row, $mouse->col);
            $this->tui->setFocus($this->editor);
            // Keep an existing selection (so Cut/Copy act on it); otherwise place
            // the caret at the click, like PhpStorm.
            if (!$this->editor->getDocument()->hasSelection()) {
                $this->editor->clearExtraCarets();
                $this->editor->clickAt($rel['row'], $rel['col']);
            }
            $items = $this->contextItems->editorItems();
        } elseif (null !== $tabsRect && $tabsRect->contains($mouse->row, $mouse->col)) {
            $rel = $tabsRect->toRelative($mouse->row, $mouse->col);
            $index = $this->tabBar->tabIndexAtColumn($rel['col']);
            $buffers = $this->buffers->all();
            if (null === $index || !isset($buffers[$index])) {
                return;
            }
            $items = $this->contextItems->tabItems($buffers[$index]->path);
        } elseif (null !== $treeRect && $treeRect->contains($mouse->row, $mouse->col)) {
            $rel = $treeRect->toRelative($mouse->row, $mouse->col);
            $this->tui->setFocus($this->tree);
            $node = $this->tree->nodeAt($rel['row']);
            if (null === $node) {
                return;
            }
            $items = $this->treeOps->contextItems($node);
        } else {
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
    private function clickCountAt(int $row, int $col): int
    {
        $now = microtime(true);
        if (($now - $this->lastClickTime) < 0.4 && $row === $this->lastClickRow && $col === $this->lastClickCol) {
            $this->clickCount = min($this->clickCount + 1, 3);
        } else {
            $this->clickCount = 1;
        }
        $this->lastClickTime = $now;
        $this->lastClickRow = $row;
        $this->lastClickCol = $col;

        return $this->clickCount;
    }
}
