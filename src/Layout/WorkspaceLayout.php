<?php

declare(strict_types=1);

namespace Porta\Layout;

use Porta\Ui\Theme\Styles;
use Porta\Widget\BreadcrumbsWidget;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\ContextMenu;
use Porta\Widget\DebugPanelWidget;
use Porta\Widget\DockTabsWidget;
use Porta\Widget\FileTreeWidget;
use Porta\Widget\FindBarWidget;
use Porta\Widget\Git\ChangesListWidget;
use Porta\Widget\Git\CommitBoxWidget;
use Porta\Widget\Git\DiffViewWidget;
use Porta\Widget\Git\GitTabsWidget;
use Porta\Widget\Git\HistoryListWidget;
use Porta\Widget\MenuBarWidget;
use Porta\Widget\ResultsPanelWidget;
use Porta\Widget\SeparatorWidget;
use Porta\Widget\Settings\KeybindingsListWidget;
use Porta\Widget\Settings\SettingsFormWidget;
use Porta\Widget\Settings\SettingsNavWidget;
use Porta\Widget\StatusBarWidget;
use Porta\Widget\TabBarPosition;
use Porta\Widget\TabBarWidget;
use Porta\Widget\TerminalPanelWidget;
use Symfony\Component\Tui\Style\Direction;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\ContainerWidget;

/**
 * Owns the workspace's composite widget tree: the generic layout containers
 * and separators (duplicate-typed, so the container cannot autowire them),
 * their assembly, the Tui Z-order, and the tab-strip position surgery. The
 * horizontal areas' children (main/git/settings pane order) are set by the
 * RTL-aware pane ordering, not here.
 */
final class WorkspaceLayout
{
    private readonly ContainerWidget $main;

    private readonly ContainerWidget $editorPane;

    private readonly ContainerWidget $editorMain;

    private readonly ContainerWidget $editorBody;

    private readonly ContainerWidget $gitLeft;

    private readonly ContainerWidget $gitArea;

    private readonly ContainerWidget $settingsContent;

    private readonly ContainerWidget $settingsArea;

    private readonly SeparatorWidget $separator;

    private readonly SeparatorWidget $gitSeparator;

    private readonly SeparatorWidget $settingsSeparator;

    public function __construct(
        private readonly FileTreeWidget $tree,
        private readonly TabBarWidget $tabBar,
        BreadcrumbsWidget $breadcrumbs,
        CodeEditorWidget $editor,
        GitTabsWidget $gitTabs,
        ChangesListWidget $gitChanges,
        CommitBoxWidget $gitCommitBox,
        DiffViewWidget $gitDiff,
        HistoryListWidget $gitHistory,
        SettingsNavWidget $settingsNav,
        SettingsFormWidget $settingsForm,
        KeybindingsListWidget $settingsKeys,
        private readonly MenuBarWidget $menuBar,
        private readonly ContextMenu $contextMenu,
        private readonly FindBarWidget $findBar,
        private readonly DockTabsWidget $dockTabs,
        private readonly ResultsPanelWidget $resultsPanel,
        private readonly DebugPanelWidget $debugPanel,
        private readonly TerminalPanelWidget $terminalPanel,
        private readonly StatusBarWidget $statusBar,
        private readonly Styles $styles,
    ) {
        // The body (breadcrumbs + content slot) is a fixed inner container so
        // BufferService's content swaps (remove + append) land after the
        // breadcrumbs regardless of where the tab strip sits around it.
        $this->editorBody = $this->container('editor-body', new Style(flex: 1));
        $this->editorBody->add($breadcrumbs)->add($editor);

        // The find bar sits at the top of the editing area (below the tab strip,
        // above the body). It lives in this inner column rather than at the Tui
        // root so it spans only the editor pane and stays put across the editor↔
        // help↔image content swaps BufferService makes inside the body.
        $this->editorMain = $this->container('editor-main', new Style(flex: 1));
        $this->editorMain->add($this->findBar)->add($this->editorBody);

        $this->editorPane = $this->container('editor-pane', new Style(flex: 1));
        $this->editorPane->add($tabBar)->add($this->editorMain);

        $this->separator = $this->separatorWidget('separator');
        $this->main = $this->container('main', new Style(direction: Direction::Horizontal, gap: 0));

        $this->gitLeft = $this->container('git-left', new Style(flex: 0));
        $this->gitLeft->add($gitTabs)->add($gitChanges)->add($gitCommitBox);
        $this->gitSeparator = $this->separatorWidget('git-separator');
        $this->gitArea = $this->container('git-area', new Style(direction: Direction::Horizontal, gap: 0));

        $this->settingsSeparator = $this->separatorWidget('settings-separator');
        $this->settingsContent = $this->container('settings-content', new Style(flex: 1));
        $this->settingsArea = $this->container('settings-area', new Style(direction: Direction::Horizontal, gap: 0));
    }

    /** The floating chrome Z-order: later adds composite over earlier ones. */
    public function mount(Tui $tui): void
    {
        $tui
            ->add($this->menuBar)
            ->add($this->main)
            ->add($this->dockTabs)
            ->add($this->resultsPanel)
            ->add($this->debugPanel)
            ->add($this->terminalPanel)
            ->add($this->contextMenu)
            ->add($this->statusBar);
    }

    /**
     * Re-seat the tab strip around the editor body: a one-row bar above/below
     * (vertical pane) or a fixed-width list beside it (horizontal pane).
     */
    public function setTabBarPosition(TabBarPosition $position): void
    {
        $this->editorPane->setStyle($position->isSide() ? new Style(flex: 1, direction: Direction::Horizontal) : new Style(flex: 1));
        $this->editorPane->clear();
        foreach ($position->tabsFirst() ? [$this->tabBar, $this->editorMain] : [$this->editorMain, $this->tabBar] as $pane) {
            $this->editorPane->add($pane);
        }
    }

    /**
     * The editor area's LTR pane order (tree, drag separator, editor pane).
     *
     * @return list<AbstractWidget>
     */
    public function editorPanes(): array
    {
        return [$this->tree, $this->separator, $this->editorPane];
    }

    public function main(): ContainerWidget
    {
        return $this->main;
    }

    public function editorPane(): ContainerWidget
    {
        return $this->editorPane;
    }

    public function editorMain(): ContainerWidget
    {
        return $this->editorMain;
    }

    public function editorBody(): ContainerWidget
    {
        return $this->editorBody;
    }

    public function gitLeft(): ContainerWidget
    {
        return $this->gitLeft;
    }

    public function gitArea(): ContainerWidget
    {
        return $this->gitArea;
    }

    public function settingsContent(): ContainerWidget
    {
        return $this->settingsContent;
    }

    public function settingsArea(): ContainerWidget
    {
        return $this->settingsArea;
    }

    public function separator(): SeparatorWidget
    {
        return $this->separator;
    }

    public function gitSeparator(): SeparatorWidget
    {
        return $this->gitSeparator;
    }

    public function settingsSeparator(): SeparatorWidget
    {
        return $this->settingsSeparator;
    }

    private function container(string $id, Style $style): ContainerWidget
    {
        $container = new ContainerWidget();
        $container->setId($id);
        $container->setStyle($style);
        $container->expandVertically(true);

        return $container;
    }

    private function separatorWidget(string $id): SeparatorWidget
    {
        $separator = new SeparatorWidget($this->styles);
        $separator->setId($id)->setStyle(new Style(flex: 0));

        return $separator;
    }
}
