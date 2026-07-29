<?php

declare(strict_types=1);

namespace Porta\Input;

use Porta\Overlay\State\MenuOverlayController;
use Porta\Ui\BidiTui;
use Porta\Widget\ContextMenu;
use Porta\Widget\MenuBarWidget;
use Porta\Workbench\Dock\BottomDock;
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
final class WheelRouter
{
    public function __construct(
        private readonly HitTester $hit,
        private readonly Tui $tui,
        private readonly EditorLayoutPanes $panes,
        private readonly BottomDock $dock,
        private readonly MenuBarWidget $menuBar,
        private readonly ContextMenu $contextMenu,
        private readonly MenuOverlayController $overlay,
        private readonly GitMouseRouter $git,
        private readonly SettingsMouseRouter $settings,
        private readonly BidiTui $bidi,
        private readonly HoverRouter $hover,
    ) {}

    /**
     * Route one wheel notch to the pane under the pointer, in the same guard
     * order the click path uses: popups and dropdowns first, then the dock
     * panel, then the editor/tree/tab panes.
     */
    public function route(MouseEvent $mouse, ?WidgetRect $treeRect, ?WidgetRect $tabsRect, ?WidgetRect $editorRect): void
    {
        // Horizontal wheel (trackpad swipe or shift+wheel): only the panes
        // with long lines scroll sideways; everything else ignores it.
        $imageRect = $this->hit->rect($this->panes->imageViewer());
        if (0 !== $mouse->wheelHDelta) {
            if ($this->git->scrollHorizontal($mouse)) {
                $this->tui->requestRender();

                return;
            }
            if ($this->hit->contains($tabsRect, $mouse) && !$this->panes->tabBar()->position()->isSide()) {
                // The strip renders mirrored under RTL, so the visual swipe
                // direction flips to match the on-screen tab order.
                $this->panes->tabBar()->scrollBy($this->bidi->isMirrored() ? -$mouse->wheelHDelta : $mouse->wheelHDelta);
                $this->tui->requestRender();

                return;
            }
            if ($this->hit->contains($editorRect, $mouse)) {
                $this->panes->editor()->scrollHBy(WheelStep::cols($mouse));
                $this->tui->requestRender();

                return;
            }
            if ($this->hit->contains($imageRect, $mouse)) {
                $this->panes->imageViewer()->scrollBy(WheelStep::cols($mouse), 0);
                $this->tui->requestRender();

                return;
            }
            if ($this->hit->contains($this->hit->rect($this->panes->graphViewer()), $mouse)) {
                $this->panes->graphViewer()->scrollBy(WheelStep::cols($mouse), 0);
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
        if (null !== $this->panes->editor()->getContext() && $this->panes->editor()->isAnyPickerOpen()) {
            $this->panes->editor()->movePopupSelection(WheelStep::rows($mouse));
            $this->tui->requestRender();

            return;
        }

        // wide tab per notch on the horizontal strip, the panel-list three
        // rows per notch on the vertical one.
        if ($this->hit->contains($tabsRect, $mouse)) {
            // The side strip is a list of rows; the horizontal one moves one
            // whole TAB per notch — a different unit, not the same constant.
            $tabBar = $this->panes->tabBar();
            $tabBar->scrollBy($tabBar->position()->isSide() ? WheelStep::rows($mouse) : $mouse->wheelDelta);
            $this->tui->requestRender();

            return;
        }

        // The Find modal floats over the workspace and owns input while
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
        if (null !== ($graphRect = $this->hit->rect($this->panes->graphViewer())) && $graphRect->contains($mouse->row, $mouse->col)) {
            // The image viewer's contract: plain wheel pans, Ctrl+wheel zooms toward the pointer.
            $rel = $graphRect->toRelative($mouse->row, $mouse->col);
            $mouse->ctrl
                ? $this->panes->graphViewer()->zoomAt($mouse->wheelDelta < 0 ? 1 : -1, $rel['col'], $rel['row'])
                : $this->panes->graphViewer()->scrollBy(0, WheelStep::rows($mouse, factor: 2));
            $this->tui->requestRender();

            return;
        }
        if ($this->hit->contains($treeRect, $mouse)) {
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
}
