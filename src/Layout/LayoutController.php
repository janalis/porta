<?php

declare(strict_types=1);

namespace Porta\Layout;

use Porta\Config\LayoutPrefs;
use Porta\Config\LayoutStore;
use Porta\Container\AsIdeService;
use Porta\I18n\Translator;
use Porta\Input\MouseEvent;
use Porta\Support\IntMath;
use Porta\Widget\DockPanelWidget;
use Porta\Widget\FileTreeWidget;
use Porta\Widget\Render\TabStripRenderer;
use Porta\Workbench\Dock\BottomDock;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Render\WidgetRect;
use Symfony\Component\Tui\Tui;

/**
 * Pane layout: applies saved sidebar/bottom-panel proportions on startup, and
 * drives the mouse-resize of the sidebar↔editor separator and the bottom panels'
 * top edge, persisting the proportions per project via {@see LayoutStore}. The
 * drag lifecycle (arm on press, apply on drag/release) is driven by the mouse
 * router calling armSidebarResize/armPanelResize + continueResizeDrag + cancelDrags.
 */
#[AsIdeService]
final class LayoutController
{
    /** The file tree's resize state (armed drag, start width, persisted proportion). */
    private readonly SidebarResizeState $treeResize;

    /**
     * Each view region's, created on first use from the sidebar the view
     * declared. Keyed by region id rather than named per view: core does not
     * know how many views there are, and a view that declared no resizable
     * sidebar simply has no entry.
     *
     * @var array<string, SidebarResizeState>
     */
    private array $viewResizes = [];

    private bool $panelResizing = false;
    private int $panelResizeBottom = 0; // the dragged panel's anchored bottom row, captured at press
    private int $panelResizeStartHeight = 0;

    /** The armed grab row relative to the panel top (-1 when the dock strip is the grab). */
    private int $panelResizeGrabOffset = 0;

    /**
     * The armed panel's rows that are not its content — the results panel's
     * boxed filter — since a panel is sized by its content rows while the
     * drag measures its whole rect. Without it the first motion of a drag
     * grew the panel by the filter box's three rows.
     */
    private int $panelResizeChrome = 0;
    private ?int $bottomPanelHeightPercent = null;

    /**
     * The height to give the bottom panel back when maximise is toggled off —
     * and, being non-null, the fact that it IS maximised. Not persisted: a
     * maximised panel is a thing you are doing right now, like a full-screen
     * window, not a layout you chose.
     */
    private ?int $panelRestoreRows = null;

    public function __construct(
        private readonly LayoutStore $store,
        private readonly Tui $tui,
        private readonly Renderer $renderer,
        FileTreeWidget $tree,
        private readonly BottomDock $dock,
        private readonly ViewRegions $regions,
        private readonly Translator $translator,
    ) {
        $this->treeResize = new SidebarResizeState($tree);
    }

    /** The resize state for a view's region, or null when it declared no resizable sidebar. */
    private function viewResize(string $id): ?SidebarResizeState
    {
        if (\array_key_exists($id, $this->viewResizes)) {
            return $this->viewResizes[$id];
        }
        $panes = $this->regions->find($id)?->sidebarPanes();

        return null === $panes ? null : ($this->viewResizes[$id] = new SidebarResizeState($panes));
    }

    public function applyLayout(): void
    {
        $layout = $this->store->load();
        if (null === $layout) {
            return;
        }

        $terminal = $this->tui->getTerminal();
        $cols = $terminal->getColumns();
        $rows = $terminal->getRows();
        $this->treeResize->widthPercent = $layout->sidebarWidthPercent;
        foreach ($layout->viewSidebarWidthPercent as $id => $percent) {
            $state = $this->viewResize($id);
            if (null !== $state) {
                $state->widthPercent = $percent;
            }
        }
        $this->bottomPanelHeightPercent = $layout->bottomPanelHeightPercent;

        // Plain width fields: safe to set even while a view's widgets are detached.
        foreach ($this->sidebarResizes() as $state) {
            if (null === $state->widthPercent) {
                continue;
            }

            $this->applyPercent($state->sidebar, $state->widthPercent, $cols);
        }
        if (null !== $this->panelRestoreRows) {
            // Maximised, and the terminal just changed size: re-take the new
            // screen rather than snap back to a stored proportion. The height
            // to restore is left alone — it is still where the panel was.
            $this->resizeBottomPanels($this->maximisedRows());

            return;
        }
        if (null !== $this->bottomPanelHeightPercent) {
            $this->resizeBottomPanels($this->clampPanel((int) \round(($this->bottomPanelHeightPercent * $rows) / 100), $rows));
        }
    }

    /** Restore a persisted proportion as columns, clamped like a drag would. */
    private function applyPercent(ResizableSidebar $sidebar, int $percent, int $cols): void
    {
        $sidebar->setWidth($this->clampSidebarWidth($sidebar, (int) \round(($percent * $cols) / 100), $cols));
    }

    /**
     * Continue an armed resize drag: apply the new sidebar width / panel height on
     * each left-drag, persist on release. Returns true when it consumed the event.
     */
    public function continueResizeDrag(MouseEvent $mouse, ?WidgetRect $treeRect, ?WidgetRect $viewLeftRect = null): bool
    {
        // Sidebar resize: dragging a pane's separator sets that pane's width. Only
        // one sidebar is ever armed at a time (see cancelDrags), so the first
        // match wins and the rest fall through untouched.
        if ($this->continueSidebarResize($mouse, $treeRect, $this->treeResize)) {
            return true;
        }
        foreach ($this->viewResizes as $state) {
            if ($this->continueSidebarResize($mouse, $viewLeftRect, $state)) {
                return true;
            }
        }

        // Bottom-panel resize: dragging a panel's top edge sets the bottom-panel height.
        if ($this->panelResizing && ($mouse->isLeftDrag() || $mouse->isLeftRelease())) {
            $rows = $this->tui->getTerminal()->getRows();
            $height = $this->clampPanel($this->panelResizeBottom - $mouse->row + $this->panelResizeGrabOffset - $this->panelResizeChrome, $rows);
            $this->forgetMaximised();
            $this->resizeBottomPanels($height);
            $this->tui->requestRender();
            if ($mouse->isLeftRelease()) {
                if ($height !== $this->panelResizeStartHeight) {
                    $this->commitPercent($height, $rows, $this->bottomPanelHeightPercent);
                }
                $this->panelResizing = false;
            }

            return true;
        }

        return false;
    }

    /**
     * Apply one armed sidebar's drag: resize on left-drag/release, persist on
     * release, and disarm on release. Returns true when it consumed the event.
     */
    private function continueSidebarResize(MouseEvent $mouse, ?WidgetRect $rect, SidebarResizeState $state): bool
    {
        if (ResizeState::Idle === $state->resizing || !($mouse->isLeftDrag() || $mouse->isLeftRelease())) {
            return false;
        }
        if (null !== $rect) {
            $cols = $this->tui->getTerminal()->getColumns();
            $state->sidebar->setWidth($this->clampSidebarWidth($state->sidebar, $this->widthToPointer($mouse, $rect), $cols));
            $this->tui->requestRender();
            if ($mouse->isLeftRelease() && $state->sidebar->width() !== $state->startWidth) {
                $this->commitPercent($state->sidebar->width(), $cols, $state->widthPercent);
            }
        }
        if ($mouse->isLeftRelease()) {
            $state->resizing = ResizeState::Idle;
        }

        return true;
    }

    /**
     * The sidebar width a drag to $mouse implies: the distance from the pane's
     * anchored edge to the pointer. The pane is anchored where the layout pins
     * it — left edge under LTR, right edge under RTL, where
     * {@see \Porta\Layout\PaneLayout::order()} mirrors the pane order and the
     * sidebar's own col moves as it grows.
     */
    private function widthToPointer(MouseEvent $mouse, WidgetRect $rect): int
    {
        return $this->translator->isRtl()
            ? $rect->col + $rect->columns - $mouse->col
            : $mouse->col - $rect->col;
    }

    /**
     * Arm a sidebar resize when a left-press lands anywhere on the tree↔editor
     * separator's one-column rect. Returns true when armed.
     */
    public function armSidebarResize(?WidgetRect $separatorRect, MouseEvent $mouse): bool
    {
        return $this->arm($this->treeResize, $separatorRect, $mouse);
    }

    /** Arm a view sidebar's resize (a left-press on that region's separator rect). */
    public function armViewSidebarResize(string $id, ?WidgetRect $separatorRect, MouseEvent $mouse): bool
    {
        $state = $this->viewResize($id);

        return null !== $state && $this->arm($state, $separatorRect, $mouse);
    }

    /** The shared arm path: capture the start width, mark the drag active. */
    private function arm(SidebarResizeState $state, ?WidgetRect $separatorRect, MouseEvent $mouse): bool
    {
        if (null !== $separatorRect && $separatorRect->contains($mouse->row, $mouse->col)) {
            $state->startWidth = $state->sidebar->width();
            $state->resizing = ResizeState::Active;

            return true;
        }

        return false;
    }

    /**
     * Arm a bottom-panel (terminal or results) resize when a left-press lands on
     * the panel's top edge. Focuses the panel too, preserving click-to-focus.
     */
    public function armPanelResize(DockPanelWidget $panel, MouseEvent $mouse, int $grabRowOffset = 0): bool
    {
        if (!$panel->isVisible()) {
            return false;
        }
        $rect = $this->renderer->getWidgetRect($panel);
        if (null === $rect || $mouse->row !== ($rect->row + $grabRowOffset) || $mouse->col < $rect->col || $mouse->col >= ($rect->col + $rect->columns)) {
            return false;
        }
        $this->panelResizeBottom = $rect->row + $rect->rows;
        $this->panelResizeStartHeight = $panel->visibleRows();
        $this->panelResizeChrome = $rect->rows - $panel->visibleRows();
        $this->panelResizeGrabOffset = $grabRowOffset;
        $this->panelResizing = true;
        $this->tui->setFocus($panel);
        $this->tui->requestRender();

        return true;
    }

    /** Keyboard resize of the file-tree sidebar (Shift+F11/F12): widen/narrow by $delta columns. */
    public function nudgeSidebar(int $delta): void
    {
        $this->nudgeSidebarWidth($this->treeResize, $delta);
    }

    /** Keyboard resize of a view's sidebar. */
    public function nudgeViewSidebar(string $id, int $delta): void
    {
        $state = $this->viewResize($id);
        if (null !== $state) {
            $this->nudgeSidebarWidth($state, $delta);
        }
    }

    /**
     * Keyboard resize of the open bottom panel (terminal/results/debug): grow or
     * shrink by $delta rows. A no-op when none of the panels is on screen (their
     * shared height would have nothing to anchor to without a rendered rect).
     */
    public function nudgeBottomPanel(int $delta): void
    {
        $panel = $this->dock->visiblePanel();
        if (null === $panel) {
            return;
        }

        // The panel's own count, not the renderer's rect. The rect is not a
        // different unit — it reports the same number — but it only refreshes
        // on a frame, so two presses inside one input burst both read the
        // pre-press height and the panel moved one row for two presses.
        $rows = $this->tui->getTerminal()->getRows();
        $height = $this->clampPanel($panel->visibleRows() + $delta, $rows);
        $this->forgetMaximised();
        $this->resizeBottomPanels($height);
        $this->commitPercent($height, $rows, $this->bottomPanelHeightPercent);
        $this->tui->requestRender();
    }

    /**
     * Maximise the open bottom panel, or put it back where it was.
     *
     * The ceiling is deliberately past {@see clampPanel()}'s: that one keeps
     * the editor comfortable, which is the wrong policy for a panel you have
     * just asked to take over. Six rows is what the frame itself needs — the
     * menu bar, the tab strip, the dock strip and the status bar — plus a
     * couple of editor rows, so you can still see which file you are coming
     * back to; and two more where the strips are folder tabs.
     *
     * A no-op when no panel is open: there is nothing to maximise, and
     * inventing a panel to fill the screen is not what the key asked for.
     */
    public function toggleBottomPanelMaximised(): void
    {
        $panel = $this->dock->visiblePanel();
        if (null === $panel) {
            return;
        }

        if (null !== $this->panelRestoreRows) {
            $this->resizeBottomPanels($this->panelRestoreRows);
            $this->panelRestoreRows = null;
            $this->tui->requestRender();

            return;
        }

        $this->panelRestoreRows = $panel->visibleRows();
        $this->resizeBottomPanels($this->maximisedRows());
        $this->tui->requestRender();
    }

    /** Whether the bottom panel is currently maximised — the status bar's read. */
    public function isBottomPanelMaximised(): bool
    {
        return null !== $this->panelRestoreRows;
    }

    private function maximisedRows(): int
    {
        $rows = $this->tui->getTerminal()->getRows();

        return \max(3, $rows - 6 - self::folderRows($rows));
    }

    /**
     * The rows the two tab strips take beyond one each, on a terminal tall
     * enough for folder tabs ({@see TabStripRenderer::rowsFor()}).
     */
    private static function folderRows(int $rows): int
    {
        return 2 * (TabStripRenderer::rowsFor($rows) - 1);
    }

    /** Shared keyboard-nudge path: clamp like the drags do, persist, repaint. */
    private function nudgeSidebarWidth(SidebarResizeState $state, int $delta): void
    {
        $cols = $this->tui->getTerminal()->getColumns();
        $state->sidebar->setWidth($this->clampSidebarWidth($state->sidebar, $state->sidebar->width() + $delta, $cols));
        $this->commitPercent($state->sidebar->width(), $cols, $state->widthPercent);
        $this->tui->requestRender();
    }

    /**
     * Drop a retracted view's cached resize state: after an un-mint + re-mint
     * it would resize the DEAD region's widgets. The persisted
     * `viewSidebarWidthPercent` entry stays, so a re-enable restores the width.
     */
    public function forgetView(string $id): void
    {
        unset($this->viewResizes[$id]);
    }

    /** A fresh left-press ends any armed resize drag. */
    public function cancelDrags(): void
    {
        foreach ($this->sidebarResizes() as $state) {
            $state->resizing = ResizeState::Idle;
        }
        $this->panelResizing = false;
    }

    /** @return list<SidebarResizeState> */
    private function sidebarResizes(): array
    {
        return [$this->treeResize, ...\array_values($this->viewResizes)];
    }

    private function resizeBottomPanels(int $height): void
    {
        $this->dock->setPanelRows($height);
    }

    /**
     * Any hand-chosen height ends maximised mode — a drag, a nudge, a restored
     * proportion. Keeping the flag would make the next toggle snap the panel
     * back to a size the user has since moved away from.
     */
    private function forgetMaximised(): void
    {
        $this->panelRestoreRows = null;
    }

    /**
     * Clamp a sidebar width to keep both the sidebar and the main pane usable.
     * The pane's own bounds come from the pane; the $cols-20 floor is the one
     * bound this owns, because it is about the NEIGHBOUR — whatever sits
     * beside the sidebar must stay usable however narrow the terminal gets.
     */
    private function clampSidebarWidth(ResizableSidebar $sidebar, int $width, int $cols): int
    {
        return \max($sidebar->minWidth(), \min($width, $sidebar->maxWidth(), (int) \round($cols * $sidebar->maxRatio()), $cols - 20));
    }

    /** Clamp a bottom-panel height so the editor keeps room above it. */
    private function clampPanel(int $height, int $rows): int
    {
        return IntMath::clamp($height, 3, $rows - 8 - self::folderRows($rows));
    }

    /**
     * Persist a pane's size as a percentage of the space available to it,
     * updating $target and writing the layout only when the rounded percent
     * changes. Shared by every sidebar width and the bottom-panel height.
     */
    private function commitPercent(int $value, int $total, ?int &$target): void
    {
        $pct = $total > 0 ? (int) \round(($value * 100) / $total) : null;
        if ($pct !== $target) {
            $target = $pct;
            $this->persistLayout();
        }
    }

    /**
     * Every minted region's sidebar width, keyed by view id.
     *
     * Reads the regions rather than naming views: the ids are whatever
     * registered, which is the whole point of a contributed view.
     *
     * @return array<string, int>
     */
    private function viewWidths(): array
    {
        $widths = [];
        foreach ($this->regions->all() as $region) {
            $percent = $this->viewResize($region->id)?->widthPercent;
            if (null !== $percent) {
                $widths[$region->id] = $percent;
            }
        }

        return $widths;
    }

    private function persistLayout(): void
    {
        $this->store->save(new LayoutPrefs(
            sidebarWidthPercent: $this->treeResize->widthPercent,
            bottomPanelHeightPercent: $this->bottomPanelHeightPercent,
            viewSidebarWidthPercent: $this->viewWidths(),
        ));
    }
}
