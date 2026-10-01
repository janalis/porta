<?php

declare(strict_types=1);

namespace Porta\Input;

use Porta\Container\LayoutSlot;
use Porta\Layout\PreviewSplit;
use Porta\Layout\ViewRegions;
use Porta\Overlay\State\MenuOverlayController;
use Porta\Overlay\State\ToastCenter;
use Porta\Widget\ContextMenu;
use Porta\Widget\DockTabsWidget;
use Porta\Widget\HoverableList;
use Porta\Widget\MenuBarWidget;
use Porta\Widget\SeparatorWidget;
use Porta\Widget\StatusBarWidget;
use Porta\Workbench\Dock\BottomDock;
use Porta\Workbench\View\ViewPointers;
use Porta\Workbench\View\ViewRegistry;
use Symfony\Component\Tui\Render\WidgetRect;
use Symfony\Component\Tui\Tui;

/**
 * Hover-move routing (mouse mode 1003) split out of {@see MouseRouter}: on a
 * bare move it tints the target under the pointer in whichever surface owns it
 * — tree, the strip family (tabs/dock/crumbs/find tags), the pane dividers, the
 * app menu, and the open popup/panel lists — re-rendering only when a highlight
 * actually changes so the motion traffic stays cheap. The dock-strip column
 * geometry ({@see dockStripRelCol()}) lives here too because the hover path owns
 * it, but the click and right-menu paths in MouseRouter still ask for it.
 */
final readonly class HoverRouter
{
    public function __construct(
        private HitTester $hit,
        private Tui $tui,
        private EditorLayoutPanes $panes,
        private BottomDock $dock,
        private DockTabsWidget $dockTabs,
        private MenuBarWidget $menuBar,
        private ContextMenu $contextMenu,
        private MenuOverlayController $overlay,
        private ModalWindowRouter $windows,
        private ViewPointers $pointers,
        private ViewRegistry $views,
        #[LayoutSlot('separator')]
        private SeparatorWidget $separator,
        private ToastCenter $toasts,
        private StatusBarWidget $statusBar,
        private TooltipRouter $tooltips,
        private ViewRegions $regions,
        private PreviewSplit $previewSplit,
    ) {}

    /**
     * A bare hover-move: highlight the row under the pointer in whichever list
     * popup/panel is open (editor overlay/completion, results panel, go-to-file).
     * Re-renders only when the highlighted row actually changes, so the extra
     * motion traffic from mouse mode 1003 stays cheap.
     */
    public function handle(MouseEvent $mouse, ?WidgetRect $editorRect, ?string $view = null): void
    {
        // The tooltip follows the same owners in the same order, on its own
        // path: the tints below report whether a highlight changed, the card
        // what the thing under the pointer IS.
        $this->tooltips->route($mouse, $view);

        // The toast stack floats above every modal: over a card, only its ×
        // tint reacts, and the hover never leaks to what is beneath.
        if ($this->toasts->hoverAt($mouse->row, $mouse->col)) {
            return;
        }

        // A right-click context menu floats over every view and is modal, so it
        // gets first refusal: highlight the item under the pointer.
        if ($this->contextMenu->isOpen()) {
            if (null !== ($item = $this->hit->hitRegion($this->overlay->contextMenuDropdown(), $mouse->row, $mouse->col)) && $this->contextMenu->hoverItem($item)) {
                $this->tui->requestRender();
            }

            return;
        }

        // A floating picker owns hover while open (screen coordinates); the
        // workspace beneath is dimmed, so its pane tints would be invisible
        // anyway. The menu bar keeps first refusal, like the other modals.
        if ($this->panes->editor->isAnyPickerOpen()) {
            // A pointer the menu takes has left the picker: say so, or its
            // close button stays lit under the dropdown.
            $miss = $this->handleMenuHover($mouse);
            if ($this->panes->editor->handlePickerHover($miss ? -1 : $mouse->row, $miss ? -1 : $mouse->col)) {
                $this->tui->requestRender();
            }

            return;
        }

        // The floating Find-in-Files modal owns hover while open (screen
        // coordinates); the workspace beneath is dimmed, so its pane tints
        // would be invisible anyway. The menu bar keeps first refusal.
        if ($this->panes->editor->isFindModalOpen()) {
            $miss = $this->handleMenuHover($mouse);
            if ($this->panes->editor->findModal->hoverAt($miss ? -1 : $mouse->row, $miss ? -1 : $mouse->col)) {
                $this->tui->requestRender();
            }

            return;
        }

        // The Welcome modal floats over whatever is beneath it and is modal, so
        // it owns hover next: the menu bar keeps first refusal, then the box.
        if ($this->windows->isModalOpen()) {
            $this->handleMenuHover($mouse) ? $this->windows->leave() : $this->windows->hover($mouse);

            return;
        }

        // The status bar rides along under every view, like the menu bar: a
        // clickable cell under the pointer underlines.
        $this->updateStatusHover($mouse);

        // An open view window owns its hover targets exclusively; the
        // tree/editor behind it are out of reach. The menu bar rides along
        // over any window, so it gets first refusal, then the window's frame
        // (its × and resize handles light), then the region.
        if (null !== $view && $this->views->isAnyOpen()) {
            $this->hoverViewWindow($mouse, $view);

            return;
        }
        $this->handleMainHover($mouse, $editorRect);
    }

    /** The open view window's hover: the menu's if it takes the pointer, else the frame's, the divider's and the view's. */
    private function hoverViewWindow(MouseEvent $mouse, string $view): void
    {
        if ($this->handleMenuHover($mouse)) {
            $this->windows->leave(); // the menu took the pointer: nothing on the window stays lit

            return;
        }
        $this->windows->hover($mouse);
        // The region's divider is core's, like its drag (MouseRouter arms that
        // for every view): every view's column thickens under the pointer,
        // without the view having to remember to.
        $region = $this->regions->find($view);
        if (null !== $region) {
            $this->updateSeparatorHover($mouse, $region->separator());
        }
        $this->pointers->of($view)->hover($mouse);
    }

    /**
     * The pointer's column relative to the dock strip when it is over the visible
     * strip — any of a folder strip's rows — else null. The strip carries the
     * tool tabs and its top row doubles as the panel resize grab, so three
     * routes test it — wheel-cycle, hover, right-menu.
     */
    public function dockStripRelCol(MouseEvent $mouse): ?int
    {
        $stripRect = $this->hit->rect($this->dockTabs);
        if (!$this->dock->stripVisible() || null === $stripRect || !$stripRect->contains($mouse->row, $mouse->col)) {
            return null;
        }

        return $mouse->col - $stripRect->col;
    }

    private function updateTreeHover(MouseEvent $mouse): void
    {
        if ($this->hit->hover($mouse->row, $mouse->col, $this->panes->tree)) {
            $this->tui->requestRender();
        }
    }

    /**
     * Strip hover: the tab/crumb under the pointer tints, and leaving a strip
     * clears it. Every strip is asked on every move — the pointer left one to
     * arrive at another — so the three results are collected and then combined,
     * never short-circuited.
     */
    private function updateStripHover(MouseEvent $mouse): void
    {
        $tabsRect = $this->hit->rect($this->panes->tabBar);
        $tabsRel = null !== $tabsRect && $tabsRect->contains($mouse->row, $mouse->col) ? $tabsRect->toRelative($mouse->row, $mouse->col) : null;
        $tabsChanged = null !== $tabsRel ? $this->panes->tabBar->hoverTab($tabsRel['row'], $tabsRel['col']) : $this->panes->tabBar->clearHover();

        $stripCol = $this->dockStripRelCol($mouse);
        $onStrip = null !== $stripCol;
        // On a tab → tint it; on the empty tail → that row is the resize grab, so
        // heavy-rule it instead (the sidebar's thin/heavy face, here horizontal).
        $relCol = $stripCol ?? -1;
        $onTab = $onStrip && $this->dockTabs->isOnTab($relCol);
        $tintChanged = $onTab ? $this->dockTabs->hoverTabAt($relCol) : $this->dockTabs->clearHover();
        // The grab is the top row's tail alone — the row a press arms
        // (LayoutController::armPanelResize, the strip's height above the
        // panel), the dock's top edge.
        $stripRect = $this->hit->rect($this->dockTabs);
        $onGrab = $onStrip && !$onTab && null !== $stripRect && $mouse->row === $stripRect->row;
        $grabChanged = $onGrab ? $this->dockTabs->hoverGrab() : $this->dockTabs->clearGrab();
        $dockChanged = $tintChanged || $grabChanged;

        // The breadcrumbs are a strip too: each crumb is a click target, so
        // each crumb tints. The bar renders no rows without a scope, so a
        // stale rect is possible — the crumb hit-test itself is the guard.
        $crumbRect = $this->hit->rect($this->panes->breadcrumbs);
        $crumbRel = null !== $crumbRect && $crumbRect->contains($mouse->row, $mouse->col) ? $crumbRect->toRelative($mouse->row, $mouse->col) : null;
        $crumbsChanged = null !== $crumbRel ? $this->panes->breadcrumbs->hoverAt($crumbRel['col']) : $this->panes->breadcrumbs->clearHover();

        // The find bar's option tags. It renders zero rows when closed and the
        // renderer keeps the last non-empty rect, so its own liveness gates
        // the hit-test rather than a null rect.
        $findRect = $this->panes->findBar->isOpen() ? $this->hit->rect($this->panes->findBar) : null;
        $findRel = null !== $findRect && $findRect->contains($mouse->row, $mouse->col) ? $findRect->toRelative($mouse->row, $mouse->col) : null;
        $findChanged = null !== $findRel ? $this->panes->findBar->hoverAt($findRel['row'], $findRel['col']) : $this->panes->findBar->clearHover();

        if ($tabsChanged || $dockChanged || $crumbsChanged || $findChanged) {
            $this->tui->requestRender();
        }
    }

    private function updateStatusHover(MouseEvent $mouse): void
    {
        $rect = $this->hit->rect($this->statusBar);
        $col = null !== $rect && $rect->contains($mouse->row, $mouse->col) ? $rect->toRelative($mouse->row, $mouse->col)['col'] : null;
        if ($this->statusBar->hoverAt($col)) {
            $this->tui->requestRender();
        }
    }

    /**
     * The pane divider thickens under the pointer, so a resizable boundary
     * looks like one before you find it by accident. A collapsed sidebar
     * detaches its separator, which nulls the rect — no liveness guard needed.
     */
    private function updateSeparatorHover(MouseEvent $mouse, SeparatorWidget $separator): void
    {
        if ($this->hit->hoverDivider($mouse->row, $mouse->col, $separator)) {
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
        if ($this->menuBar->isDropdownShown()) {
            $region = $this->overlay->menuDropdown();
            if (null !== ($item = $this->hit->hitRegion($region, $mouse->row, $mouse->col))) {
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

            // The frame's border cells: consumed (no tint), so a hover on the
            // box edge never leaks to the pane beneath.
            return true === $region?->contains($mouse->row, $mouse->col);
        }

        // Closed: highlight the title under the pointer, clearing when off the bar.
        $rel = $this->hit->hit($this->menuBar, $mouse->row, $mouse->col);
        $hovered = null !== $rel ? $this->menuBar->titleAtColumn($rel['col']) : null;
        if ($this->menuBar->hoverTitle($hovered)) {
            $this->tui->requestRender();
        }

        return null !== $rel;
    }

    /** Hover routing for the normal editor layout: tree, menu, editor popup, panels. */
    private function handleMainHover(MouseEvent $mouse, ?WidgetRect $editorRect): void
    {
        $this->updateTreeHover($mouse);
        $this->updateStripHover($mouse);
        $this->updateListHover($mouse);
        $this->updateSeparatorHover($mouse, $this->separator);
        $this->updateSeparatorHover($mouse, $this->previewSplit->separator);

        if ($this->handleMenuHover($mouse)) {
            return;
        }

        if ($this->panes->editor->isCompletionOpen() && null !== $editorRect && $editorRect->contains($mouse->row, $mouse->col)) {
            $rel = $editorRect->toRelative($mouse->row, $mouse->col);
            if ($this->panes->editor->handlePopupHover($rel['row'], $rel['col'])) {
                $this->tui->requestRender();
            }

            return;
        }

        // A gutter mark answers the pointer: underlined here, its meaning in
        // words on the tooltip card ({@see TooltipRouter}).
        $rel = null !== $editorRect && $editorRect->contains($mouse->row, $mouse->col) ? $editorRect->toRelative($mouse->row, $mouse->col) : ['row' => -1, 'col' => -1];
        if ($this->panes->editor->hoverGutter($rel['row'], $rel['col'])) {
            $this->tui->requestRender();
        }
    }

    /**
     * Tint the row under the pointer in the visible dock panel and the
     * go-to-file popup, clearing it when the pointer leaves. Runs before the
     * menu/popup branches return, so moving from a panel row onto a dropdown
     * still drops the row's tint. Both are hidden-when-closed widgets whose
     * last-frame rect outlives them, so their own liveness gates the hit-test.
     */
    private function updateListHover(MouseEvent $mouse): void
    {
        $lists = [];
        $panel = $this->dock->visiblePanel();
        if ($panel instanceof HoverableList) {
            $lists[] = $panel;
        }
        if ([] !== $lists && $this->hit->hover($mouse->row, $mouse->col, ...$lists)) {
            $this->tui->requestRender();
        }
    }
}
