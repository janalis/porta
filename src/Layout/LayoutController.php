<?php

declare(strict_types=1);

namespace Porta\Layout;

use Porta\Config\LayoutPrefs;
use Porta\Config\LayoutStore;
use Porta\Container\AsIdeService;
use Porta\I18n\Translator;
use Porta\Input\MouseEvent;
use Porta\Support\IntMath;
use Porta\Ui\Density;
use Porta\Widget\DockPanelWidget;
use Porta\Widget\FileTreeWidget;
use Porta\Widget\Render\TabStripRenderer;
use Porta\Widget\TabBarWidget;
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

    /** True while a drag on the preview split's divider is armed. */
    private bool $previewResizing = false;

    /** The split's share when the drag was armed, so a press that never moved persists nothing. */
    private int $previewResizeStart = 0;

    /** The editor's persisted share of a preview split, in percent (null = never dragged). */
    private ?int $previewSplitPercent = null;

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
        private readonly TabBarWidget $tabBar,
        private readonly ViewRegions $regions,
        private readonly Translator $translator,
        private readonly PreviewSplit $previewSplit,
    ) {
        $this->treeResize = new SidebarResizeState($tree);
    }

    /**
     * The width each view's sidebar percentage is a share of: its window's
     * inner width, once the window has told us ({@see fitViewSidebar()}).
     * A view's sidebar sits in a window, not across the terminal, so a
     * share of the terminal would overflow a narrowed window.
     *
     * @var array<string, int>
     */
    private array $viewBasis = [];

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
        $this->previewSplitPercent = $layout->previewSplitPercent;
        if (null !== $this->previewSplitPercent) {
            // A share, not a width: it holds across a terminal resize by
            // itself, so there is nothing to recompute here.
            $this->previewSplit->setPercent($this->previewSplitPercent);
        }

        // Plain width fields: safe to set even while a view's widgets are detached.
        if (null !== $this->treeResize->widthPercent) {
            $this->applyPercent($this->treeResize->sidebar, $this->treeResize->widthPercent, $cols);
        }
        foreach ($this->viewResizes as $id => $state) {
            if (null === $state->widthPercent) {
                continue;
            }
            $this->applyPercent($state->sidebar, $state->widthPercent, $this->viewBasis[$id] ?? $cols);
        }
        if (null !== $this->panelRestoreRows) {
            // Maximised, and the terminal just changed size: re-take the new
            // screen rather than snap back to a stored proportion. The height
            // to restore is left alone — it is still where the panel was.
            $this->resizeBottomPanels($this->maximisedRows());

            return;
        }
        if (null !== $this->bottomPanelHeightPercent) {
            $this->resizeBottomPanels($this->clampPanel((int) \round(($this->bottomPanelHeightPercent * $rows) / 100), $rows, $this->panelChrome()));
        }
    }

    /**
     * View $id's window now has $innerWidth columns inside: re-take its
     * sidebar's share of them. The window presenter calls this as the window
     * opens and whenever a resize changes its width.
     */
    public function fitViewSidebar(string $id, int $innerWidth): void
    {
        $this->viewBasis[$id] = $innerWidth;
        $state = $this->viewResize($id);
        if (null !== $state && null !== $state->widthPercent) {
            $this->applyPercent($state->sidebar, $state->widthPercent, $innerWidth);
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
        return $this->continueSidebarResize($mouse, $treeRect, $this->treeResize) || \array_any($this->viewResizes, fn (SidebarResizeState $state, string $id): bool => $this->continueSidebarResize(
            $mouse,
            $viewLeftRect,
            $state,
            $this->viewBasis[$id] ?? null,
        )) || $this->continuePreviewResize($mouse) || $this->continuePanelResize($mouse);
    }

    /**
     * Bottom-panel resize: dragging a panel's top edge sets the bottom-panel
     * height. Returns true when it consumed the event.
     */
    private function continuePanelResize(MouseEvent $mouse): bool
    {
        if ($this->panelResizing && ($mouse->isLeftDrag() || $mouse->isLeftRelease())) {
            $rows = $this->tui->getTerminal()->getRows();
            // The chrome measured at the press: mid-drag the panel's content
            // rows have moved on and its drawn rect has not caught up.
            $height = $this->clampPanel($this->panelResizeBottom - $mouse->row + $this->panelResizeGrabOffset - $this->panelResizeChrome, $rows, $this->panelResizeChrome);
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
    private function continueSidebarResize(MouseEvent $mouse, ?WidgetRect $rect, SidebarResizeState $state, ?int $basis = null): bool
    {
        if (ResizeState::Idle === $state->resizing || !($mouse->isLeftDrag() || $mouse->isLeftRelease())) {
            return false;
        }
        if (null !== $rect) {
            $cols = $basis ?? $this->tui->getTerminal()->getColumns();
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
     * Apply an armed drag on the preview split's divider, and persist the
     * share on release. The split measures the pointer against its own area,
     * so a drag that wanders over the tree or the tab strip still resolves.
     */
    private function continuePreviewResize(MouseEvent $mouse): bool
    {
        if (!$this->previewResizing || !($mouse->isLeftDrag() || $mouse->isLeftRelease())) {
            return false;
        }
        $rect = $this->previewSplit->rect();
        if (null !== $rect) {
            $this->previewSplit->dragTo($mouse->col, $rect);
            $this->tui->requestRender();
        }
        if ($mouse->isLeftRelease()) {
            $this->previewResizing = false;
            if ($this->previewSplit->percent() !== $this->previewResizeStart) {
                $this->commitPreviewSplit();
            }
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

    /** Arm a drag when a left-press lands on the preview split's divider, while it is on screen. */
    public function armPreviewResize(MouseEvent $mouse): bool
    {
        $separator = $this->previewSplit->separator;
        $rect = null === $separator->getContext() ? null : $this->renderer->getWidgetRect($separator);
        if (null === $rect || !$rect->contains($mouse->row, $mouse->col)) {
            return false;
        }
        $this->previewResizeStart = $this->previewSplit->percent();
        $this->previewResizing = true;

        return true;
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
        $rect = $panel->isVisible() ? $this->renderer->getWidgetRect($panel) : null;
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

    /** Keyboard resize of the preview split (Shift+F11/F12 from either half): the editor side by $delta columns. */
    public function nudgePreviewSplit(int $delta): void
    {
        $rect = $this->previewSplit->rect();
        if (null === $rect) {
            return;
        }
        $this->previewSplit->nudge($delta, $rect);
        $this->commitPreviewSplit();
        $this->tui->requestRender();
    }

    /** Persist the split's share when it moved — one global proportion, for every previewed file. */
    private function commitPreviewSplit(): void
    {
        $percent = $this->previewSplit->percent();
        if ($percent !== $this->previewSplitPercent) {
            $this->previewSplitPercent = $percent;
            $this->persistLayout();
        }
    }

    /** Keyboard resize of a view's sidebar. */
    public function nudgeViewSidebar(string $id, int $delta): void
    {
        $state = $this->viewResize($id);
        if (null !== $state) {
            $this->nudgeSidebarWidth($state, $delta, $this->viewBasis[$id] ?? null);
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
        $height = $this->clampPanel($panel->visibleRows() + $delta, $rows, $this->panelChrome());
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
     * back to; and four more on a roomy screen, where the strips are folder
     * tabs and rules close the workspace's top and bottom edges.
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

        return \max(3, $rows - 6 - $this->chromeRows($rows) - $this->panelChrome());
    }

    /**
     * The visible panel's rows that are not content — the Results panel's
     * boxed filter. A panel is sized in content rows, so every ceiling that
     * counts the frame's rows has to count these too: uncounted, a maximised
     * Results panel took the editor rows its ceiling promised to leave. Read
     * off the last frame, since the header is the panel's own business.
     */
    private function panelChrome(): int
    {
        $panel = $this->dock->visiblePanel();
        $rect = null === $panel ? null : $this->renderer->getWidgetRect($panel);

        return null === $panel || null === $rect ? 0 : \max(0, $rect->rows - $panel->visibleRows());
    }

    /**
     * The chrome rows a roomy screen adds: the horizontal tab strips' rows
     * past their first — the dock's ({@see TabStripRenderer::edgedRowsFor()}),
     * and the editor's ({@see TabStripRenderer::rowsFor()}) unless it sits at
     * the side, where it takes columns instead — the rules at the workspace's top and bottom edges
     * ({@see \Porta\Widget\EdgeRuleWidget}), and the rule under the
     * navigation bar ({@see \Porta\Widget\BreadcrumbsWidget}); none on a
     * compact one. Plus, on any screen, the navigation bar's own row: every
     * open file has one, so a ceiling that leaves the editor its rows has to
     * leave that one too.
     */
    private function chromeRows(int $rows): int
    {
        $density = Density::of($rows);
        $editor = $this->tabBar->position()->isSide() ? 0 : TabStripRenderer::rowsFor($density) - 1;

        return $editor + TabStripRenderer::edgedRowsFor($density) - 1 + (Density::Roomy === $density ? 3 : 0) + 1;
    }

    /** Shared keyboard-nudge path: clamp like the drags do, persist, repaint. */
    private function nudgeSidebarWidth(SidebarResizeState $state, int $delta, ?int $basis = null): void
    {
        $cols = $basis ?? $this->tui->getTerminal()->getColumns();
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
        unset($this->viewResizes[$id], $this->viewBasis[$id]);
    }

    /** A fresh left-press ends any armed resize drag. */
    public function cancelDrags(): void
    {
        foreach ($this->sidebarResizes() as $state) {
            $state->resizing = ResizeState::Idle;
        }
        $this->panelResizing = false;
        $this->previewResizing = false;
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
    private function clampPanel(int $height, int $rows, int $chrome): int
    {
        return IntMath::clamp($height, 3, $rows - 8 - $this->chromeRows($rows) - $chrome);
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
            previewSplitPercent: $this->previewSplitPercent,
        ));
    }
}
