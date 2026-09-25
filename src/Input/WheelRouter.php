<?php

declare(strict_types=1);

namespace Porta\Input;

use Porta\Overlay\State\MenuOverlayController;
use Porta\Ui\BidiTui;
use Porta\Widget\ContextMenu;
use Porta\Widget\MenuBarWidget;
use Porta\Widget\NavigablePaneWidget;
use Porta\Workbench\Dock\BottomDock;
use Porta\Workbench\View\ViewPointers;
use Porta\Workbench\View\ViewRegistry;
use Porta\Workbench\View\WheelAxis;
use Porta\Workbench\View\WheelGesture;
use Symfony\Component\Tui\Render\WidgetRect;
use Symfony\Component\Tui\Tui;

/**
 * Wheel routing, split out of {@see MouseRouter}: 140-odd lines that touch none
 * of the router's cross-event drag state, so they were only ever sharing a file
 * with it. Joins the already-extracted satellites (ClickCounter, HitTester,
 * HoverRouter, GitMouseRouter, SettingsMouseRouter, WelcomeMouseRouter).
 *
 * The guard order inside {@see route()} is behaviour, exactly as it is in the
 * router's own spine — the pointer can be over several surfaces at once and the
 * topmost must win.
 */
final readonly class WheelRouter
{
    /** Ctrl+wheel burst detection — newed, not injected, like MouseRouter's ClickCounter. */
    private ZoomGesture $zoom;

    public function __construct(
        private HitTester $hit,
        private Tui $tui,
        private EditorLayoutPanes $panes,
        private ViewPointers $pointers,
        private ViewRegistry $views,
        private BottomDock $dock,
        private MenuBarWidget $menuBar,
        private ContextMenu $contextMenu,
        private MenuOverlayController $overlay,
        private BidiTui $bidi,
        private HoverRouter $hover,
    ) {
        $this->zoom = new ZoomGesture();
    }

    /**
     * One Ctrl+wheel notch on a viewer pane: a burst glides by a factor (a
     * trackpad's Ctrl+scroll, the nearest thing to a pinch a terminal can
     * deliver), an isolated notch steps the zoom ladder.
     *
     */
    private function zoomToward(NavigablePaneWidget $pane, MouseEvent $mouse, WidgetRect $rect): void
    {
        $rel = $rect->toRelative($mouse->row, $mouse->col);
        $dir = $mouse->wheelDelta < 0 ? 1 : -1;
        $factor = $this->zoom->glideFactor($dir);
        null === $factor
            ? $pane->zoomAt($dir, $rel['col'], $rel['row'])
            : $pane->magnifyAt($factor, $rel['col'], $rel['row']);
    }

    /**
     * Route one wheel notch to the pane under the pointer, in the same guard
     * order the click path uses: popups and dropdowns first, then the dock
     * panel, then the editor/tree/tab panes.
     */
    public function route(MouseEvent $mouse, ?WidgetRect $treeRect, ?WidgetRect $tabsRect, ?WidgetRect $editorRect): void
    {
        // Horizontal wheel (trackpad swipe or shift+wheel): only the panes
        // with long lines scroll sideways; everything else ignores it.
        if (0 !== $mouse->wheelHDelta) {
            if ($this->pointers->of($this->views->activeId())->wheel(new WheelGesture($mouse, WheelAxis::Horizontal, WheelStep::cols($mouse)))) {
                $this->tui->requestRender();

                return;
            }
            if ($this->hit->contains($tabsRect, $mouse) && !$this->panes->tabBar->position()->isSide()) {
                // MEASURED 2026-09-11, and the comment this replaces was
                // wrong: a strip of LATIN tab names is not mirrored under RTL,
                // only right-anchored — "‹ a.php │ b.php ›" keeps its order and
                // moves to the right of the pane. Tab order reverses only when
                // the NAMES are Hebrew, which for a code editor is the rare
                // case. So this flip matches the on-screen order for Hebrew
                // filenames and opposes it for Latin ones, and one boolean
                // cannot be right for both. Left as it stands because
                // IdeTabScrollTest pins the current choice and changing it is a
                // behaviour decision, not a comment fix.
                $this->panes->tabBar->scrollBy($this->bidi->isMirrored() ? -$mouse->wheelHDelta : $mouse->wheelHDelta);
                $this->tui->requestRender();

                return;
            }
            if ($this->hit->contains($editorRect, $mouse)) {
                $this->panes->editor->scrollHBy(WheelStep::cols($mouse));
                $this->tui->requestRender();

                return;
            }
            $navigable = $this->panes->navigablePane();
            if (null !== $navigable && $this->hit->contains($this->hit->rect($navigable), $mouse)) {
                $navigable->scrollBy(WheelStep::cols($mouse), 0);
                $this->tui->requestRender();
            }

            return;
        }

        // The tab strip: a wheel over it pages through overflowing tabs — one
        // An open menu owns the wheel inside its dropdown region, at the shared
        // WheelStep rate like every other list — separator-skipping, so a notch
        // lands only on selectable items.
        if ($this->contextMenu->isOpen() && true === $this->overlay->contextMenuDropdown()?->contains($mouse->row, $mouse->col)) {
            $this->contextMenu->moveSelection(WheelStep::rows($mouse));
            $this->tui->requestRender();

            return;
        }
        if ($this->menuBar->isOpen() && true === $this->overlay->menuDropdown()?->contains($mouse->row, $mouse->col)) {
            $this->menuBar->moveSelection(WheelStep::rows($mouse));
            $this->tui->requestRender();

            return;
        }

        // A floating picker owns the wheel wherever the pointer is — the
        // workspace beneath is dimmed and must not scroll away under it.
        // Only the menu dropdowns (which paint above it) win first.
        if ($this->panes->editor->isAnyPickerOpen()) {
            $this->panes->editor->movePopupSelection(WheelStep::rows($mouse));
            $this->tui->requestRender();

            return;
        }

        // wide tab per notch on the horizontal strip, the panel-list three
        // rows per notch on the vertical one — one folder tab, where they are.
        if ($this->hit->contains($tabsRect, $mouse)) {
            // The side strip is a list of rows; the horizontal one moves one
            // whole TAB per notch — a different unit, not the same constant.
            $tabBar = $this->panes->tabBar;
            $tabBar->position()->isSide() ? $tabBar->scrollByRows(WheelStep::rows($mouse)) : $tabBar->scrollBy($mouse->wheelDelta);
            $this->tui->requestRender();

            return;
        }

        // The Find modal floats over the workspace and owns input while
        // open — scroll its preview instead of the editor behind it.
        $findBox = $this->panes->editor->isFindModalOpen() ? $this->panes->editor->findModal->lastBox() : null;
        if (null !== $findBox && $findBox->contains($mouse->row, $mouse->col)) {
            $this->panes->editor->scrollFindModal(WheelStep::rows($mouse));
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
        if (null !== $panel && $this->hit->contains($this->hit->rect($panel), $mouse)) {
            $panel->wheelBy(WheelStep::rows($mouse));
            $this->tui->requestRender();

            return;
        }

        $step = WheelStep::rows($mouse);
        if ($this->hit->contains($editorRect, $mouse)) {
            // An open editor popup (completion, list overlay, search modal)
            // owns the wheel — its selection moves instead of the buffer
            // scrolling away underneath it.
            if (!$this->panes->editor->movePopupSelection($step)) {
                $this->panes->editor->scrollBy($step);
            }
            $this->tui->requestRender();

            return;
        }
        // Whatever pane holds the slot: a plain notch is its own wheel verb (the
        // help pane scrolls rows, a navigable one pans by half-rows), and
        // Ctrl+wheel zooms toward the pointer for the panes that can.
        $pane = $this->panes->pane();
        $paneRect = null === $pane ? null : $this->hit->rect($pane);
        if (null !== $pane && null !== $paneRect && $paneRect->contains($mouse->row, $mouse->col)) {
            $navigable = $pane instanceof NavigablePaneWidget ? $pane : null;
            $mouse->ctrl && null !== $navigable
                ? $this->zoomToward($navigable, $mouse, $paneRect)
                : $pane->wheelBy($step);
            $this->tui->requestRender();

            return;
        }
        if ($this->hit->contains($treeRect, $mouse)) {
            $this->panes->tree->scrollBy($step);
            $this->tui->requestRender();

            return;
        }
        if (!$this->pointers->of($this->views->activeId())->wheel(new WheelGesture($mouse, WheelAxis::Vertical, $step))) {
            // No view pane is under the pointer.
            return;
        }
        $this->tui->requestRender();
    }
}
