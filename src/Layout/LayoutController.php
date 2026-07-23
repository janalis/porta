<?php

declare(strict_types=1);

namespace Porta\Layout;

use Porta\Config\LayoutPrefs;
use Porta\Config\LayoutStore;
use Porta\Container\AsIdeService;
use Porta\Git\WhitespaceMode;
use Porta\I18n\Translator;
use Porta\Input\MouseEvent;
use Porta\Support\IntMath;
use Porta\Ui\Dock\BottomDock;
use Porta\Widget\DebugPanelWidget;
use Porta\Widget\FileTreeWidget;
use Porta\Widget\ResultsPanelWidget;
use Porta\Widget\Settings\SettingsNavWidget;
use Porta\Widget\TerminalPanelWidget;
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
    private ResizeState $sidebarResizing = ResizeState::Idle;
    private ResizeState $gitSidebarResizing = ResizeState::Idle;
    private ResizeState $settingsSidebarResizing = ResizeState::Idle;
    private bool $panelResizing = false;
    private int $panelResizeBottom = 0; // the dragged panel's anchored bottom row, captured at press
    private int $sidebarResizeStartWidth = 0; // sidebar/panel sizes at drag start, to skip no-op persists
    private int $gitSidebarResizeStartWidth = 0;
    private int $settingsSidebarResizeStartWidth = 0;
    private int $panelResizeStartHeight = 0;

    /** The armed grab row relative to the panel top (-1 when the dock strip is the grab). */
    private int $panelResizeGrabOffset = 0;
    private ?int $sidebarWidthPercent = null; // persisted pane proportions (null = widget default)
    private ?int $bottomPanelHeightPercent = null;
    private ?int $gitSidebarWidthPercent = null;
    private ?int $settingsSidebarWidthPercent = null;
    private bool $gitDiffSplit = false;
    private bool $gitDiffHideWhitespace = false;

    public function __construct(
        private readonly LayoutStore $store,
        private readonly Tui $tui,
        private readonly Renderer $renderer,
        private readonly FileTreeWidget $tree,
        private readonly BottomDock $dock,
        private readonly GitSidebar $gitSidebar,
        private readonly SettingsNavWidget $settingsSidebar,
        private readonly Translator $translator,
    ) {}

    public function applyLayout(): void
    {
        $layout = $this->store->load();
        if (null === $layout) {
            return;
        }

        $terminal = $this->tui->getTerminal();
        $cols = $terminal->getColumns();
        $rows = $terminal->getRows();
        $this->sidebarWidthPercent = $layout->sidebarWidthPercent;
        $this->bottomPanelHeightPercent = $layout->bottomPanelHeightPercent;
        $this->gitSidebarWidthPercent = $layout->gitSidebarWidthPercent;
        $this->settingsSidebarWidthPercent = $layout->settingsSidebarWidthPercent;
        $this->gitDiffSplit = $layout->gitDiffSplit ?? false;
        $this->gitDiffHideWhitespace = $layout->gitDiffHideWhitespace ?? false;

        if (null !== $this->sidebarWidthPercent) {
            $this->applyPercent($this->tree, $this->sidebarWidthPercent, $cols);
        }
        if (null !== $this->gitSidebarWidthPercent) {
            // Plain width fields: safe to set while the git widgets are detached.
            $this->applyPercent($this->gitSidebar, $this->gitSidebarWidthPercent, $cols);
        }
        if (null !== $this->settingsSidebarWidthPercent) {
            $this->applyPercent($this->settingsSidebar, $this->settingsSidebarWidthPercent, $cols);
        }
        if (null !== $this->bottomPanelHeightPercent) {
            $this->resizeBottomPanels($this->clampPanel((int) round(($this->bottomPanelHeightPercent * $rows) / 100), $rows));
        }
    }

    /** Restore a persisted proportion as columns, clamped like a drag would. */
    private function applyPercent(ResizableSidebar $sidebar, int $percent, int $cols): void
    {
        $sidebar->setWidth($this->clampSidebarWidth($sidebar, (int) round(($percent * $cols) / 100), $cols));
    }

    /**
     * Continue an armed resize drag: apply the new sidebar width / panel height on
     * each left-drag, persist on release. Returns true when it consumed the event.
     */
    public function continueResizeDrag(MouseEvent $mouse, ?WidgetRect $treeRect, ?WidgetRect $gitLeftRect = null, ?WidgetRect $settingsLeftRect = null): bool
    {
        // Sidebar resize: dragging a pane's separator sets that pane's width. Only
        // one sidebar is ever armed at a time (see cancelDrags), so the first
        // match wins and the rest fall through untouched.
        if ($this->continueSidebarResize($mouse, $treeRect, new SidebarResize($this->tree, $this->sidebarResizeStartWidth), $this->sidebarResizing, $this->sidebarWidthPercent)) {
            return true;
        }
        if ($this->continueSidebarResize(
            $mouse,
            $gitLeftRect,
            new SidebarResize($this->gitSidebar, $this->gitSidebarResizeStartWidth),
            $this->gitSidebarResizing,
            $this->gitSidebarWidthPercent,
        )) {
            return true;
        }
        if ($this->continueSidebarResize(
            $mouse,
            $settingsLeftRect,
            new SidebarResize($this->settingsSidebar, $this->settingsSidebarResizeStartWidth),
            $this->settingsSidebarResizing,
            $this->settingsSidebarWidthPercent,
        )) {
            return true;
        }

        // Bottom-panel resize: dragging a panel's top edge sets the bottom-panel height.
        if ($this->panelResizing && ($mouse->isLeftDrag() || $mouse->isLeftRelease())) {
            $rows = $this->tui->getTerminal()->getRows();
            $height = $this->clampPanel($this->panelResizeBottom - $mouse->row + $this->panelResizeGrabOffset, $rows);
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
     * release, and disarm on release. $resizing and $percent are the controller's
     * per-sidebar state, updated in place. Returns true when it consumed the event.
     */
    private function continueSidebarResize(MouseEvent $mouse, ?WidgetRect $rect, SidebarResize $spec, ResizeState &$resizing, ?int &$percent): bool
    {
        if (ResizeState::Idle === $resizing || !($mouse->isLeftDrag() || $mouse->isLeftRelease())) {
            return false;
        }
        if (null !== $rect) {
            $cols = $this->tui->getTerminal()->getColumns();
            $spec->sidebar->setWidth($this->clampSidebarWidth($spec->sidebar, $this->widthToPointer($mouse, $rect), $cols));
            $this->tui->requestRender();
            if ($mouse->isLeftRelease() && $spec->sidebar->width() !== $spec->startWidth) {
                $this->commitPercent($spec->sidebar->width(), $cols, $percent);
            }
        }
        if ($mouse->isLeftRelease()) {
            $resizing = ResizeState::Idle;
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
        if (null !== $separatorRect && $separatorRect->contains($mouse->row, $mouse->col)) {
            $this->sidebarResizeStartWidth = $this->tree->width();
            $this->sidebarResizing = ResizeState::Active;

            return true;
        }

        return false;
    }

    /**
     * Arm a git-sidebar resize when a left-press lands anywhere on the git
     * separator's one-column rect. Returns true when armed.
     */
    public function armGitSidebarResize(?WidgetRect $gitSeparatorRect, MouseEvent $mouse): bool
    {
        if (null !== $gitSeparatorRect && $gitSeparatorRect->contains($mouse->row, $mouse->col)) {
            $this->gitSidebarResizeStartWidth = $this->gitSidebar->width();
            $this->gitSidebarResizing = ResizeState::Active;

            return true;
        }

        return false;
    }

    /**
     * Arm a settings-sidebar resize when a left-press lands on the settings
     * separator's one-column rect. Returns true when armed.
     */
    public function armSettingsSidebarResize(?WidgetRect $settingsSeparatorRect, MouseEvent $mouse): bool
    {
        if (null !== $settingsSeparatorRect && $settingsSeparatorRect->contains($mouse->row, $mouse->col)) {
            $this->settingsSidebarResizeStartWidth = $this->settingsSidebar->width();
            $this->settingsSidebarResizing = ResizeState::Active;

            return true;
        }

        return false;
    }

    /**
     * Arm a bottom-panel (terminal or results) resize when a left-press lands on
     * the panel's top edge. Focuses the panel too, preserving click-to-focus.
     */
    public function armPanelResize(TerminalPanelWidget|ResultsPanelWidget|DebugPanelWidget $panel, MouseEvent $mouse, int $grabRowOffset = 0): bool
    {
        if (!$panel->isVisible()) {
            return false;
        }
        $rect = $this->renderer->getWidgetRect($panel);
        if (null === $rect || $mouse->row !== ($rect->row + $grabRowOffset) || $mouse->col < $rect->col || $mouse->col >= ($rect->col + $rect->columns)) {
            return false;
        }
        $this->panelResizeBottom = $rect->row + $rect->rows;
        $this->panelResizeStartHeight = $rect->rows;
        $this->panelResizeGrabOffset = $grabRowOffset;
        $this->panelResizing = true;
        $this->tui->setFocus($panel);
        $this->tui->requestRender();

        return true;
    }

    /** Keyboard resize of the file-tree sidebar (Shift+F11/F12): widen/narrow by $delta columns. */
    public function nudgeSidebar(int $delta): void
    {
        $this->nudgeSidebarWidth($this->tree, $delta, $this->sidebarWidthPercent);
    }

    /** Keyboard resize of the git sidebar. */
    public function nudgeGitSidebar(int $delta): void
    {
        $this->nudgeSidebarWidth($this->gitSidebar, $delta, $this->gitSidebarWidthPercent);
    }

    /** Keyboard resize of the settings sidebar. */
    public function nudgeSettingsSidebar(int $delta): void
    {
        $this->nudgeSidebarWidth($this->settingsSidebar, $delta, $this->settingsSidebarWidthPercent);
    }

    /**
     * Keyboard resize of the open bottom panel (terminal/results/debug): grow or
     * shrink by $delta rows. A no-op when none of the panels is on screen (their
     * shared height would have nothing to anchor to without a rendered rect).
     */
    public function nudgeBottomPanel(int $delta): void
    {
        $panel = $this->dock->visiblePanel();
        $rect = null !== $panel ? $this->renderer->getWidgetRect($panel) : null;
        if (null === $rect) {
            return;
        }

        $rows = $this->tui->getTerminal()->getRows();
        $height = $this->clampPanel($rect->rows + $delta, $rows);
        $this->resizeBottomPanels($height);
        $this->commitPercent($height, $rows, $this->bottomPanelHeightPercent);
        $this->tui->requestRender();
    }

    /** Shared keyboard-nudge path: clamp like the drags do, persist, repaint. */
    private function nudgeSidebarWidth(ResizableSidebar $sidebar, int $delta, ?int &$percent): void
    {
        $cols = $this->tui->getTerminal()->getColumns();
        $sidebar->setWidth($this->clampSidebarWidth($sidebar, $sidebar->width() + $delta, $cols));
        $this->commitPercent($sidebar->width(), $cols, $percent);
        $this->tui->requestRender();
    }

    /** A fresh left-press ends any armed resize drag. */
    public function cancelDrags(): void
    {
        $this->sidebarResizing = ResizeState::Idle;
        $this->gitSidebarResizing = ResizeState::Idle;
        $this->settingsSidebarResizing = ResizeState::Idle;
        $this->panelResizing = false;
    }

    private function resizeBottomPanels(int $height): void
    {
        $this->dock->setPanelRows($height);
    }

    /**
     * Clamp a sidebar width to keep both the sidebar and the main pane usable.
     * The pane's own bounds come from the pane; the $cols-20 floor is the one
     * bound this owns, because it is about the NEIGHBOUR — whatever sits
     * beside the sidebar must stay usable however narrow the terminal gets.
     */
    private function clampSidebarWidth(ResizableSidebar $sidebar, int $width, int $cols): int
    {
        return max($sidebar->minWidth(), min($width, $sidebar->maxWidth(), (int) round($cols * $sidebar->maxRatio()), $cols - 20));
    }

    /** Clamp a bottom-panel height so the editor keeps room above it. */
    private function clampPanel(int $height, int $rows): int
    {
        return IntMath::clamp($height, 3, $rows - 8);
    }

    /**
     * Persist a pane's size as a percentage of the space available to it,
     * updating $target and writing the layout only when the rounded percent
     * changes. Shared by every sidebar width and the bottom-panel height.
     */
    private function commitPercent(int $value, int $total, ?int &$target): void
    {
        $pct = $total > 0 ? (int) round(($value * 100) / $total) : null;
        if ($pct !== $target) {
            $target = $pct;
            $this->persistLayout();
        }
    }

    /**
     * The persisted git-diff display toggles (side-by-side, hide whitespace).
     *
     * @return array{layout: DiffLayout, whitespace: WhitespaceMode}
     */
    public function gitDiffPrefs(): array
    {
        return [
            'layout' => $this->gitDiffSplit ? DiffLayout::Split : DiffLayout::Unified,
            'whitespace' => $this->gitDiffHideWhitespace ? WhitespaceMode::Hidden : WhitespaceMode::Shown,
        ];
    }

    public function setGitDiffPrefs(DiffLayout $layout, WhitespaceMode $whitespace): void
    {
        $this->gitDiffSplit = DiffLayout::Split === $layout;
        $this->gitDiffHideWhitespace = WhitespaceMode::Hidden === $whitespace;
        $this->persistLayout();
    }

    private function persistLayout(): void
    {
        $this->store->save(new LayoutPrefs(
            sidebarWidthPercent: $this->sidebarWidthPercent,
            bottomPanelHeightPercent: $this->bottomPanelHeightPercent,
            gitSidebarWidthPercent: $this->gitSidebarWidthPercent,
            settingsSidebarWidthPercent: $this->settingsSidebarWidthPercent,
            gitDiffSplit: $this->gitDiffSplit ? true : null,
            gitDiffHideWhitespace: $this->gitDiffHideWhitespace ? true : null,
        ));
    }
}
