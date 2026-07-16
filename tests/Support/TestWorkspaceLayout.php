<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use Porta\I18n\Translator;
use Porta\Layout\WorkspaceLayout;
use Porta\Widget\BreadcrumbsWidget;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\ContextMenu;
use Porta\Widget\DebugPanelWidget;
use Porta\Widget\FileTreeWidget;
use Porta\Widget\FindBarWidget;
use Porta\Widget\Git\ChangesListWidget;
use Porta\Widget\Git\CommitBoxWidget;
use Porta\Widget\Git\DiffViewWidget;
use Porta\Widget\Git\GitTabsWidget;
use Porta\Widget\Git\HistoryListWidget;
use Porta\Widget\GoToFileWidget;
use Porta\Widget\MenuBarWidget;
use Porta\Widget\ResultsPanelWidget;
use Porta\Widget\Settings\KeybindingsListWidget;
use Porta\Widget\Settings\SettingsFormWidget;
use Porta\Widget\Settings\SettingsNavWidget;
use Porta\Widget\StatusBarWidget;
use Porta\Widget\TabBarWidget;
use Porta\Widget\TerminalPanelWidget;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * Builds a {@see WorkspaceLayout} over throwaway widgets for unit tests, so
 * a constructor change lands here once instead of in every test that used to
 * spell out the full 20-widget construction. Pass named overrides for the
 * widgets a test needs to hold; every widget the factory creates itself gets
 * its production `#[AsWidget]` id (the container applies those in the real
 * boot), while overrides are used untouched.
 */
final class TestWorkspaceLayout
{
    public static function create(
        string $rootDir = '/tmp',
        ?FileTreeWidget $tree = null,
        ?TabBarWidget $tabBar = null,
        ?BreadcrumbsWidget $breadcrumbs = null,
        ?CodeEditorWidget $editor = null,
        ?GitTabsWidget $gitTabs = null,
        ?ChangesListWidget $gitChanges = null,
        ?CommitBoxWidget $gitCommitBox = null,
        ?DiffViewWidget $gitDiff = null,
        ?HistoryListWidget $gitHistory = null,
        ?SettingsNavWidget $settingsNav = null,
        ?SettingsFormWidget $settingsForm = null,
        ?KeybindingsListWidget $settingsKeys = null,
        ?MenuBarWidget $menuBar = null,
        ?ContextMenu $contextMenu = null,
        ?GoToFileWidget $goToFile = null,
        ?FindBarWidget $findBar = null,
        ?ResultsPanelWidget $resultsPanel = null,
        ?DebugPanelWidget $debugPanel = null,
        ?TerminalPanelWidget $terminalPanel = null,
        ?StatusBarWidget $statusBar = null,
    ): WorkspaceLayout {
        $t = new Translator('en');
        $id = static function (AbstractWidget $widget, string $id): AbstractWidget {
            $widget->setId($id);

            return $widget;
        };

        /** @var WorkspaceLayout */
        return new WorkspaceLayout(
            $tree ?? $id(new FileTreeWidget($rootDir), id: 'tree'),
            $tabBar ?? $id(new TabBarWidget(), id: 'tabs'),
            $breadcrumbs ?? $id(new BreadcrumbsWidget(), id: 'breadcrumbs'),
            $editor ?? $id(new CodeEditorWidget(), id: 'editor'),
            $gitTabs ?? $id(new GitTabsWidget($t), id: 'git-tabs'),
            $gitChanges ?? $id(new ChangesListWidget($t), id: 'git-changes'),
            $gitCommitBox ?? $id(new CommitBoxWidget($t), id: 'git-commit'),
            $gitDiff ?? $id(new DiffViewWidget($t), id: 'git-diff'),
            $gitHistory ?? $id(new HistoryListWidget($t), id: 'git-history'),
            $settingsNav ?? $id(new SettingsNavWidget($t), id: 'settings-nav'),
            $settingsForm ?? $id(new SettingsFormWidget($t), id: 'settings-form'),
            $settingsKeys ?? $id(new KeybindingsListWidget($t), id: 'settings-keys'),
            $menuBar ?? $id(new MenuBarWidget(), id: 'menu'),
            $contextMenu ?? $id(new ContextMenu(), id: 'context-menu'),
            $goToFile ?? $id(new GoToFileWidget($t, $rootDir), id: 'goto-file'),
            $findBar ?? $id(new FindBarWidget($t), id: 'find-bar'),
            $resultsPanel ?? $id(new ResultsPanelWidget(), id: 'results'),
            $debugPanel ?? $id(new DebugPanelWidget(), id: 'debug'),
            $terminalPanel ?? $id(new TerminalPanelWidget(), id: 'terminal'),
            $statusBar ?? $id(new StatusBarWidget(), id: 'status'),
        );
    }
}
