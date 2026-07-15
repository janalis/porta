<?php

declare(strict_types=1);

namespace Porta\Layout;

use Porta\Config\LayoutPrefs;
use Porta\Config\LayoutStore;
use Porta\Git\WhitespaceMode;
use Porta\Input\MouseEvent;
use Porta\Support\IntMath;
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
        private readonly TerminalPanelWidget $terminalPanel,
        private readonly ResultsPanelWidget $resultsPanel,
        private readonly DebugPanelWidget $debugPanel,
        private readonly GitSidebar $gitSidebar,
        private readonly SettingsNavWidget $settingsSidebar,
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
            $this->tree->setWidth($this->clampSidebarWidth((int) round(($this->sidebarWidthPercent * $cols) / 100), $cols, 12, 0.6));
        }
        if (null !== $this->gitSidebarWidthPercent) {
            // Plain width fields: safe to set while the git widgets are detached.
            $this->gitSidebar->setWidth($this->clampSidebarWidth((int) round(($this->gitSidebarWidthPercent * $cols) / 100), $cols, 30, 0.6));
        }
        if (null !== $this->settingsSidebarWidthPercent) {
            $this->settingsSidebar->setWidth($this->clampSidebarWidth((int) round(($this->settingsSidebarWidthPercent * $cols) / 100), $cols, 14, 0.5));
        }
        if (null !== $this->bottomPanelHeightPercent) {
            $this->resizeBottomPanels($this->clampPanel((int) round(($this->bottomPanelHeightPercent * $rows) / 100), $rows));
        }
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
        if ($this->continueSidebarResize($mouse, $treeRect, new SidebarResize($this->tree, 12, 0.6, $this->sidebarResizeStartWidth), $this->sidebarResizing, $this->sidebarWidthPercent)) {
            return true;
        }
        if ($this->continueSidebarResize(
            $mouse,
            $gitLeftRect,
            new SidebarResize($this->gitSidebar, 30, 0.6, $this->gitSidebarResizeStartWidth),
            $this->gitSidebarResizing,
            $this->gitSidebarWidthPercent,
        )) {
            return true;
        }
        if ($this->continueSidebarResize(
            $mouse,
            $settingsLeftRect,
            new SidebarResize($this->settingsSidebar, 14, 0.5, $this->settingsSidebarResizeStartWidth),
            $this->settingsSidebarResizing,
            $this->settingsSidebarWidthPercent,
        )) {
            return true;
        }

        // Bottom-panel resize: dragging a panel's top edge sets the bottom-panel height.
        if ($this->panelResizing && ($mouse->isLeftDrag() || $mouse->isLeftRelease())) {
            $rows = $this->tui->getTerminal()->getRows();
            $height = $this->clampPanel($this->panelResizeBottom - $mouse->row, $rows);
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
            $spec->sidebar->setWidth($this->clampSidebarWidth($mouse->col - $rect->col, $cols, $spec->min, $spec->ratio));
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
     * Arm a sidebar resize when a left-press lands on the tree↔editor separator
     * column. Returns true when armed.
     */
    public function armSidebarResize(?WidgetRect $treeRect, MouseEvent $mouse): bool
    {
        if (null !== $treeRect && $mouse->col === ($treeRect->col + $treeRect->columns) && $mouse->row >= $treeRect->row && $mouse->row < ($treeRect->row + $treeRect->rows)) {
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
    public function armPanelResize(TerminalPanelWidget|ResultsPanelWidget|DebugPanelWidget $panel, MouseEvent $mouse): bool
    {
        if (!$panel->isOpen()) {
            return false;
        }
        $rect = $this->renderer->getWidgetRect($panel);
        if (null === $rect || $mouse->row !== $rect->row || $mouse->col < $rect->col || $mouse->col >= ($rect->col + $rect->columns)) {
            return false;
        }
        $this->panelResizeBottom = $rect->row + $rect->rows;
        $this->panelResizeStartHeight = $rect->rows;
        $this->panelResizing = true;
        $this->tui->setFocus($panel);
        $this->tui->requestRender();

        return true;
    }

    /** Keyboard resize of the file-tree sidebar (Shift+F11/F12): widen/narrow by $delta columns. */
    public function nudgeSidebar(int $delta): void
    {
        $this->nudgeSidebarWidth($this->tree, $delta, 12, 0.6, $this->sidebarWidthPercent);
    }

    /** Keyboard resize of the git sidebar. */
    public function nudgeGitSidebar(int $delta): void
    {
        $this->nudgeSidebarWidth($this->gitSidebar, $delta, 30, 0.6, $this->gitSidebarWidthPercent);
    }

    /** Keyboard resize of the settings sidebar. */
    public function nudgeSettingsSidebar(int $delta): void
    {
        $this->nudgeSidebarWidth($this->settingsSidebar, $delta, 14, 0.5, $this->settingsSidebarWidthPercent);
    }

    /**
     * Keyboard resize of the open bottom panel (terminal/results/debug): grow or
     * shrink by $delta rows. A no-op when none of the panels is on screen (their
     * shared height would have nothing to anchor to without a rendered rect).
     */
    public function nudgeBottomPanel(int $delta): void
    {
        $rect = null;
        foreach ([$this->terminalPanel, $this->resultsPanel, $this->debugPanel] as $panel) {
            if (!$panel->isOpen()) {
                continue;
            }

            $rect = $this->renderer->getWidgetRect($panel);
            break;
        }
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
    private function nudgeSidebarWidth(ResizableSidebar $sidebar, int $delta, int $min, float $ratio, ?int &$percent): void
    {
        $cols = $this->tui->getTerminal()->getColumns();
        $sidebar->setWidth($this->clampSidebarWidth($sidebar->width() + $delta, $cols, $min, $ratio));
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
        $this->terminalPanel->setVisibleRows($height);
        $this->resultsPanel->setVisibleRows($height);
        $this->debugPanel->setVisibleRows($height);
    }

    /**
     * Clamp a sidebar width to keep both the sidebar and the main pane usable:
     * at least $min columns, at most $ratio of the terminal (and $cols-20 so the
     * pane beside it never collapses).
     */
    private function clampSidebarWidth(int $width, int $cols, int $min, float $ratio): int
    {
        return max($min, min($width, (int) round($cols * $ratio), $cols - 20));
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
