<?php

declare(strict_types=1);

namespace Porta\Overlay;

use Porta\Layout\WorkspaceLayout;
use Porta\Ui\WorkspaceViews;
use Porta\Widget\BreadcrumbsWidget;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\FileTreeWidget;
use Porta\Widget\Git\ChangesListWidget;
use Porta\Widget\Git\CommitBoxWidget;
use Porta\Widget\Git\DiffViewWidget;
use Porta\Widget\Git\GitTabsWidget;
use Porta\Widget\Git\HistoryListWidget;
use Porta\Widget\HelpViewerWidget;
use Porta\Widget\ImageViewerWidget;
use Porta\Widget\Settings\KeybindingsListWidget;
use Porta\Widget\Settings\SettingsFormWidget;
use Porta\Widget\Settings\SettingsNavWidget;
use Porta\Widget\TabBarWidget;

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
        private readonly TabBarWidget $tabBar,
        private readonly CodeEditorWidget $editor,
        private readonly ImageViewerWidget $imageViewer,
        private readonly HelpViewerWidget $helpViewer,
        private readonly BreadcrumbsWidget $breadcrumbs,
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
            [$this->tree, $this->layout->separator(), $this->tabBar, $this->editor, $this->imageViewer, $this->helpViewer, $this->breadcrumbs],
            [$this->gitTabs, $this->gitChanges, $this->gitCommitBox, $this->gitHistory, $this->layout->gitSeparator(), $this->gitDiff],
            [$this->settingsNav, $this->layout->settingsSeparator(), $this->settingsForm, $this->settingsKeys],
            fn (): bool => $this->views->isGitOpen(),
            fn (): bool => $this->views->isSettingsOpen(),
        );
    }
}
