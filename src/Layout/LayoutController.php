<?php

declare(strict_types=1);

namespace Porta\Layout;

use Porta\Config\LayoutPrefs;
use Porta\Config\LayoutStore;
use Porta\Input\MouseEvent;
use Porta\Support\IntMath;
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
    private bool $sidebarResizing = false;
    private bool $gitSidebarResizing = false;
    private bool $settingsSidebarResizing = false;
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
            $this->tree->setWidth($this->clampSidebar((int) round(($this->sidebarWidthPercent * $cols) / 100), $cols));
        }
        if (null !== $this->gitSidebarWidthPercent) {
            // Plain width fields: safe to set while the git widgets are detached.
            $this->gitSidebar->setWidth($this->clampGitSidebar((int) round(($this->gitSidebarWidthPercent * $cols) / 100), $cols));
        }
        if (null !== $this->settingsSidebarWidthPercent) {
            $this->settingsSidebar->setWidth($this->clampSettingsSidebar((int) round(($this->settingsSidebarWidthPercent * $cols) / 100), $cols));
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
        // Sidebar resize: dragging the tree↔editor separator sets the sidebar width.
        if ($this->sidebarResizing && ($mouse->isLeftDrag() || $mouse->isLeftRelease())) {
            if (null !== $treeRect) {
                $cols = $this->tui->getTerminal()->getColumns();
                $this->tree->setWidth($this->clampSidebar($mouse->col - $treeRect->col, $cols));
                $this->tui->requestRender();
                if ($mouse->isLeftRelease() && $this->tree->width() !== $this->sidebarResizeStartWidth) {
                    $this->commitSidebarWidth($cols);
                }
            }
            if ($mouse->isLeftRelease()) {
                $this->sidebarResizing = false;
            }

            return true;
        }

        // Git-sidebar resize: dragging the git separator sets the left column's width.
        if ($this->gitSidebarResizing && ($mouse->isLeftDrag() || $mouse->isLeftRelease())) {
            if (null !== $gitLeftRect) {
                $cols = $this->tui->getTerminal()->getColumns();
                $this->gitSidebar->setWidth($this->clampGitSidebar($mouse->col - $gitLeftRect->col, $cols));
                $this->tui->requestRender();
                if ($mouse->isLeftRelease() && $this->gitSidebar->width() !== $this->gitSidebarResizeStartWidth) {
                    $this->commitGitSidebarWidth($cols);
                }
            }
            if ($mouse->isLeftRelease()) {
                $this->gitSidebarResizing = false;
            }

            return true;
        }

        // Settings-sidebar resize: dragging the settings separator sets the nav width.
        if ($this->settingsSidebarResizing && ($mouse->isLeftDrag() || $mouse->isLeftRelease())) {
            if (null !== $settingsLeftRect) {
                $cols = $this->tui->getTerminal()->getColumns();
                $this->settingsSidebar->setWidth($this->clampSettingsSidebar($mouse->col - $settingsLeftRect->col, $cols));
                $this->tui->requestRender();
                if ($mouse->isLeftRelease() && $this->settingsSidebar->width() !== $this->settingsSidebarResizeStartWidth) {
                    $this->commitSettingsSidebarWidth($cols);
                }
            }
            if ($mouse->isLeftRelease()) {
                $this->settingsSidebarResizing = false;
            }

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
                    $this->commitPanelHeight($height, $rows);
                }
                $this->panelResizing = false;
            }

            return true;
        }

        return false;
    }

    /**
     * Arm a sidebar resize when a left-press lands on the tree↔editor separator
     * column. Returns true when armed.
     */
    public function armSidebarResize(?WidgetRect $treeRect, MouseEvent $mouse): bool
    {
        if (null !== $treeRect && $mouse->col === ($treeRect->col + $treeRect->columns) && $mouse->row >= $treeRect->row && $mouse->row < ($treeRect->row + $treeRect->rows)) {
            $this->sidebarResizeStartWidth = $this->tree->width();
            $this->sidebarResizing = true;

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
            $this->gitSidebarResizing = true;

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
            $this->settingsSidebarResizing = true;

            return true;
        }

        return false;
    }

    /**
     * Arm a bottom-panel resize when a left-press lands on the panel's top edge.
     * Focuses the panel too, preserving the click-to-focus behaviour.
     */
    public function armPanelResize(TerminalPanelWidget $panel, MouseEvent $mouse): bool
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

    /** A fresh left-press ends any armed resize drag. */
    public function cancelDrags(): void
    {
        $this->sidebarResizing = false;
        $this->gitSidebarResizing = false;
        $this->settingsSidebarResizing = false;
        $this->panelResizing = false;
    }

    private function resizeBottomPanels(int $height): void
    {
        $this->terminalPanel->setVisibleRows($height);
        $this->resultsPanel->setVisibleRows($height);
    }

    /** Clamp a sidebar width to keep both the tree and the editor usable. */
    private function clampSidebar(int $width, int $cols): int
    {
        return max(12, min($width, (int) round($cols * 0.6), $cols - 20));
    }

    /** Clamp the git sidebar so the tabs/commit button and the diff stay usable. */
    private function clampGitSidebar(int $width, int $cols): int
    {
        return max(30, min($width, (int) round($cols * 0.6), $cols - 20));
    }

    /** Clamp the settings sidebar so the nav and the content pane stay usable. */
    private function clampSettingsSidebar(int $width, int $cols): int
    {
        return max(14, min($width, (int) round($cols * 0.5), $cols - 20));
    }

    /** Clamp a bottom-panel height so the editor keeps room above it. */
    private function clampPanel(int $height, int $rows): int
    {
        return IntMath::clamp($height, 3, $rows - 8);
    }

    private function commitSidebarWidth(int $cols): void
    {
        $pct = $cols > 0 ? (int) round(($this->tree->width() * 100) / $cols) : null;
        if ($pct !== $this->sidebarWidthPercent) {
            $this->sidebarWidthPercent = $pct;
            $this->persistLayout();
        }
    }

    private function commitGitSidebarWidth(int $cols): void
    {
        $pct = $cols > 0 ? (int) round(($this->gitSidebar->width() * 100) / $cols) : null;
        if ($pct !== $this->gitSidebarWidthPercent) {
            $this->gitSidebarWidthPercent = $pct;
            $this->persistLayout();
        }
    }

    private function commitSettingsSidebarWidth(int $cols): void
    {
        $pct = $cols > 0 ? (int) round(($this->settingsSidebar->width() * 100) / $cols) : null;
        if ($pct !== $this->settingsSidebarWidthPercent) {
            $this->settingsSidebarWidthPercent = $pct;
            $this->persistLayout();
        }
    }

    private function commitPanelHeight(int $height, int $rows): void
    {
        $pct = $rows > 0 ? (int) round(($height * 100) / $rows) : null;
        if ($pct !== $this->bottomPanelHeightPercent) {
            $this->bottomPanelHeightPercent = $pct;
            $this->persistLayout();
        }
    }

    /**
     * The persisted git-diff display toggles (side-by-side, hide whitespace).
     *
     * @return array{split: bool, hideWhitespace: bool}
     */
    public function gitDiffPrefs(): array
    {
        return ['split' => $this->gitDiffSplit, 'hideWhitespace' => $this->gitDiffHideWhitespace];
    }

    public function setGitDiffPrefs(bool $split, bool $hideWhitespace): void
    {
        $this->gitDiffSplit = $split;
        $this->gitDiffHideWhitespace = $hideWhitespace;
        $this->persistLayout();
    }

    private function persistLayout(): void
    {
        $this->store->save(new LayoutPrefs(
            sidebarWidthPercent: $this->sidebarWidthPercent,
            bottomPanelHeightPercent: $this->bottomPanelHeightPercent,
            gitSidebarWidthPercent: $this->gitSidebarWidthPercent,
            settingsSidebarWidthPercent: $this->settingsSidebarWidthPercent,
            gitDiffSplit: $this->gitDiffSplit ?: null,
            gitDiffHideWhitespace: $this->gitDiffHideWhitespace ?: null,
        ));
    }
}
