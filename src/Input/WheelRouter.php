<?php

declare(strict_types=1);

namespace Porta\Input;

use Porta\Overlay\State\MenuOverlayController;
use Porta\Ui\BidiTui;
use Porta\Widget\ContextMenu;
use Porta\Widget\EditorPaneWidget;
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
 * HoverRouter, SettingsMouseRouter, ModalWindowRouter; the git view's own router left with it, for plugins/git).
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
        $moved = 0 !== $mouse->wheelHDelta
            ? $this->horizontal($mouse, $tabsRect, $editorRect)
            : $this->overSurfaces($mouse, $tabsRect) || $this->overPanes($mouse, $treeRect, $editorRect);
        if ($moved) {
            $this->tui->requestRender();
        }
    }

    /**
     * Horizontal wheel (trackpad swipe or shift+wheel): only the panes with
     * long lines scroll sideways; everything else ignores it. True when
     * something moved.
     */
    private function horizontal(MouseEvent $mouse, ?WidgetRect $tabsRect, ?WidgetRect $editorRect): bool
    {
        // A floating picker owns every wheel, as on the vertical route, and
        // the Find modal the ones inside its box; neither scrolls sideways, so
        // the notch is spent on nothing rather than the workspace beneath.
        $editor = $this->panes->editor;
        $findBox = $editor->isFindModalOpen() ? $editor->findModal->lastBox() : null;
        if ($editor->isAnyPickerOpen() || true === $findBox?->contains($mouse->row, $mouse->col)) {
            return false;
        }

        return $this->pointers->of($this->views->activeId())->wheel(new WheelGesture($mouse, WheelAxis::Horizontal, WheelStep::cols($mouse))) || $this->scrollSideways($mouse, $tabsRect, $editorRect);
    }

    /** The horizontal tab strip, the editor, or a navigable pane: the first under the pointer scrolls sideways. */
    private function scrollSideways(MouseEvent $mouse, ?WidgetRect $tabsRect, ?WidgetRect $editorRect): bool
    {
        $scroll = match (true) {
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
            $this->hit->contains($tabsRect, $mouse) && !$this->panes->tabBar->position()->isSide() => fn () => $this->panes->tabBar->scrollBy(
                $this->bidi->isMirrored() ? -$mouse->wheelHDelta : $mouse->wheelHDelta,
            ),
            $this->hit->contains($editorRect, $mouse) => fn () => $this->panes->editor->scrollHBy(WheelStep::cols($mouse)),
            null !== ($navigable = $this->panes->navigablePane()) && $this->hit->contains($this->hit->rect($navigable), $mouse) => static fn () => $navigable->scrollBy(WheelStep::cols($mouse), 0),
            default => null,
        };
        if (null !== $scroll) {
            $scroll();
        }

        return null !== $scroll;
    }

    /**
     * What floats over the workspace owns the wheel first: an open menu
     * inside its dropdown, a picker wherever the pointer is, the tab strip,
     * the Find modal. True when one of them took the notch.
     */
    private function overSurfaces(MouseEvent $mouse, ?WidgetRect $tabsRect): bool
    {
        $step = WheelStep::rows($mouse);
        $scroll = match (true) {
            // An open menu owns the wheel inside its dropdown region, at the shared
            // WheelStep rate like every other list — separator-skipping, so a notch
            // lands only on selectable items.
            $this->contextMenu->isOpen() && true === $this->overlay->contextMenuDropdown()?->contains($mouse->row, $mouse->col) => fn () => $this->contextMenu->moveSelection($step),
            $this->menuBar->isOpen() && true === $this->overlay->menuDropdown()?->contains($mouse->row, $mouse->col) => fn () => $this->menuBar->moveSelection($step),
            // A floating picker owns the wheel wherever the pointer is — the
            // workspace beneath is dimmed and must not scroll away under it.
            // Only the menu dropdowns (which paint above it) win first.
            $this->panes->editor->isAnyPickerOpen() => fn (): bool => $this->panes->editor->movePopupSelection($step),
            // The tab strip: a wheel over it pages through overflowing tabs — one
            // wide tab per notch on the horizontal strip, the panel-list three
            // rows per notch on the vertical one — one folder tab, where they are.
            // The side strip is a list of rows; the horizontal one moves one
            // whole TAB per notch — a different unit, not the same constant.
            $this->hit->contains($tabsRect, $mouse) => fn () => $this->panes->tabBar->position()->isSide()
                ? $this->panes->tabBar->scrollByRows($step)
                : $this->panes->tabBar->scrollBy($mouse->wheelDelta),
            // The Find modal floats over the workspace and owns input while
            // open — scroll its preview instead of the editor behind it.
            $this->panes->editor->isFindModalOpen() && true === $this->panes->editor->findModal->lastBox()?->contains($mouse->row, $mouse->col) => fn () => $this->panes->editor->scrollFindModal(
                $step,
            ),
            default => null,
        };
        if (null !== $scroll) {
            $scroll();
        }

        return null !== $scroll;
    }

    /**
     * The panes beneath: the dock strip and panel, the editor, whatever pane
     * holds the slot, the tree, then the active view's own. True when one of
     * them took the notch.
     */
    private function overPanes(MouseEvent $mouse, ?WidgetRect $treeRect, ?WidgetRect $editorRect): bool
    {
        // Wheel over the dock strip cycles the tool tabs — an explicit panel
        // switch, so the focus follows like a strip click would.
        $step = WheelStep::rows($mouse);
        $scroll = match (true) {
            null !== $this->hover->dockStripRelCol($mouse) => fn () => $this->dock->cycle($mouse->wheelDelta),
            // The visible dock panel (results / debug / terminal) scrolls its
            // list — the terminal scrolls its scrollback (wheel up → into history).
            null !== ($panel = $this->dock->visiblePanel()) && $this->hit->contains($this->hit->rect($panel), $mouse) => static fn () => $panel->wheelBy($step),
            // An open editor popup (completion, list overlay, search modal)
            // owns the wheel — its selection moves instead of the buffer
            // scrolling away underneath it.
            $this->hit->contains($editorRect, $mouse) => function () use ($step): void {
                if (!$this->panes->editor->movePopupSelection($step)) {
                    $this->panes->editor->scrollRows($step);
                }
            },
            default => null,
        };
        if (null !== $scroll) {
            $scroll();
        }

        return (
            null !== $scroll
            || $this->overSlotPane($mouse, $step)
            || $this->overTree($mouse, $treeRect, $step)
            // No view pane under the pointer: nothing moved.
            || $this->pointers->of($this->views->activeId())->wheel(new WheelGesture($mouse, WheelAxis::Vertical, $step))
        );
    }

    /**
     * Whatever pane holds the slot: a plain notch is its own wheel verb (the
     * help pane scrolls rows, a navigable one pans by half-rows), and
     * Ctrl+wheel zooms toward the pointer for the panes that can.
     */
    private function overSlotPane(MouseEvent $mouse, int $step): bool
    {
        $pane = \array_find($this->panes->shownPanes(), fn (EditorPaneWidget $shown): bool => $this->hit->contains($this->hit->rect($shown), $mouse));
        $paneRect = null === $pane ? null : $this->hit->rect($pane);
        if (null === $pane || null === $paneRect) {
            return false;
        }
        $navigable = $pane instanceof NavigablePaneWidget ? $pane : null;
        $mouse->ctrl && null !== $navigable
            ? $this->zoomToward($navigable, $mouse, $paneRect)
            : $pane->wheelBy($step);

        return true;
    }

    private function overTree(MouseEvent $mouse, ?WidgetRect $treeRect, int $step): bool
    {
        if (!$this->hit->contains($treeRect, $mouse)) {
            return false;
        }
        $this->panes->tree->scrollBy($step);

        return true;
    }
}
