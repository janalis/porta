<?php

declare(strict_types=1);

namespace Porta\Overlay\State;

use Porta\Editor\EditorPanes;
use Porta\Layout\ViewRegions;
use Porta\Layout\WorkspaceLayout;
use Porta\Widget\BreadcrumbsWidget;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\DebugPanelWidget;
use Porta\Widget\DockTabsWidget;
use Porta\Widget\FileTreeWidget;
use Porta\Widget\Git\ChangesListWidget;
use Porta\Widget\Git\CommitBoxWidget;
use Porta\Widget\Git\DiffViewWidget;
use Porta\Widget\Git\GitTabsWidget;
use Porta\Widget\Git\HistoryListWidget;
use Porta\Widget\ResultsPanelWidget;
use Porta\Widget\Settings\KeybindingsListWidget;
use Porta\Widget\Settings\SettingsFormWidget;
use Porta\Widget\Settings\SettingsNavWidget;
use Porta\Widget\TabBarWidget;
use Porta\Widget\TerminalPanelWidget;
use Porta\Workbench\WorkspaceView;
use Porta\Workbench\WorkspaceViews;

/**
 * Assembles the {@see MenuOverlayPanes} value from the autowired widgets, the
 * layout's separators and the view registry — the pane lists and view-open
 * closures a dumped container definition cannot express directly.
 */
final class MenuOverlayPanesFactory
{
    public function __construct(
        private readonly FileTreeWidget $tree,
        private readonly WorkspaceLayout $layout,
        private readonly ViewRegions $regions,
        private readonly TabBarWidget $tabBar,
        private readonly CodeEditorWidget $editor,
        private readonly EditorPanes $panes,
        private readonly BreadcrumbsWidget $breadcrumbs,
        private readonly DockTabsWidget $dockTabs,
        private readonly ResultsPanelWidget $resultsPanel,
        private readonly TerminalPanelWidget $terminalPanel,
        private readonly DebugPanelWidget $debugPanel,
        private readonly GitTabsWidget $gitTabs,
        private readonly ChangesListWidget $gitChanges,
        private readonly CommitBoxWidget $gitCommitBox,
        private readonly HistoryListWidget $gitHistory,
        private readonly DiffViewWidget $gitDiff,
        private readonly SettingsNavWidget $settingsNav,
        private readonly SettingsFormWidget $settingsForm,
        private readonly KeybindingsListWidget $settingsKeys,
        private readonly WorkspaceViews $views,
    ) {}

    public function create(): MenuOverlayPanes
    {
        return new MenuOverlayPanes(
            // The dock widgets host too, so a dock-strip tab's context menu
            // can composite over the strip and the visible bottom panel.
            [
                $this->tree,
                $this->layout->separator(),
                $this->tabBar,
                $this->editor,
                $this->breadcrumbs,
                $this->dockTabs,
                $this->resultsPanel,
                $this->terminalPanel,
                $this->debugPanel,
            ],
            [$this->gitTabs, $this->gitChanges, $this->gitCommitBox, $this->gitHistory, $this->regions->mint('git')->separator(), $this->gitDiff],
            [$this->settingsNav, $this->regions->mint('settings')->separator(), $this->settingsForm, $this->settingsKeys],
            fn (): bool => $this->views->isOpen(WorkspaceView::Git),
            fn (): bool => $this->views->isOpen(WorkspaceView::Settings),
            $this->panes,
        );
    }
}
