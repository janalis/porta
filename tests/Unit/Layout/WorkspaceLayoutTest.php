<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Layout;

use PHPUnit\Framework\TestCase;
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
use Porta\Widget\TabBarPosition;
use Porta\Widget\TabBarWidget;
use Porta\Widget\TerminalPanelWidget;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\ContainerWidget;

final class WorkspaceLayoutTest extends TestCase
{
    private TabBarWidget $tabBar;

    private function layout(): WorkspaceLayout
    {
        $t = new Translator('en');
        // In production the container applies each #[AsWidget] id; bare
        // constructions here set the ones the assertions read.
        $this->tabBar = new TabBarWidget();
        $this->tabBar->setId('tabs');
        $id = static function (AbstractWidget $widget, string $id): AbstractWidget {
            $widget->setId($id);

            return $widget;
        };

        /** @var WorkspaceLayout */
        return new WorkspaceLayout(
            $id(new FileTreeWidget('/tmp'), id: 'tree'),
            $this->tabBar,
            $id(new BreadcrumbsWidget(), id: 'breadcrumbs'),
            $id(new CodeEditorWidget(), id: 'editor'),
            $id(new GitTabsWidget($t), id: 'git-tabs'),
            $id(new ChangesListWidget($t), id: 'git-changes'),
            $id(new CommitBoxWidget($t), id: 'git-commit'),
            $id(new DiffViewWidget($t), id: 'git-diff'),
            $id(new HistoryListWidget($t), id: 'git-history'),
            $id(new SettingsNavWidget($t), id: 'settings-nav'),
            $id(new SettingsFormWidget($t), id: 'settings-form'),
            $id(new KeybindingsListWidget($t), id: 'settings-keys'),
            $id(new MenuBarWidget(), id: 'menu'),
            $id(new ContextMenu(), id: 'context-menu'),
            $id(new GoToFileWidget($t, '/tmp'), id: 'goto-file'),
            $id(new FindBarWidget($t), id: 'find-bar'),
            $id(new ResultsPanelWidget(), id: 'results'),
            $id(new DebugPanelWidget(), id: 'debug'),
            $id(new TerminalPanelWidget(), id: 'terminal'),
            $id(new StatusBarWidget(), id: 'status'),
        );
    }

    /** @return list<string> */
    private function ids(ContainerWidget $container): array
    {
        return array_map(static fn (AbstractWidget $w): string => $w->getId(), $container->all());
    }

    public function testAssemblesTheCompositeTreeWithStableIds(): void
    {
        $layout = $this->layout();

        $this->assertSame('main', $layout->main()->getId());
        $this->assertSame(['tabs', 'editor-body'], $this->ids($layout->editorPane()), 'top position at boot');
        $this->assertSame(['breadcrumbs', 'editor'], $this->ids($layout->editorBody()), 'breadcrumbs above the content slot');
        $this->assertSame(['git-tabs', 'git-changes', 'git-commit'], $this->ids($layout->gitLeft()));
        $this->assertSame([], $layout->gitArea()->all(), 'horizontal areas are filled by the RTL-aware pane ordering');
        $this->assertSame([], $layout->settingsArea()->all());
        $this->assertSame('settings-content', $layout->settingsContent()->getId());
        $this->assertSame(['separator', 'git-separator', 'settings-separator'], [
            $layout->separator()->getId(),
            $layout->gitSeparator()->getId(),
            $layout->settingsSeparator()->getId(),
        ]);
        $this->assertSame(['tree', 'separator', 'editor-pane'], array_map(static fn (AbstractWidget $w): string => $w->getId(), $layout->editorPanes()));
    }

    public function testMountAddsTheChromeInZOrder(): void
    {
        $layout = $this->layout();
        $tui = new Tui(terminal: new VirtualTerminal(columns: 80, rows: 24));

        $layout->mount($tui);

        $this->assertSame('main', $tui->getById('main')->getId(), 'the tree is reachable through the Tui');
        $this->assertSame('status', $tui->getById('status')->getId(), 'the status bar mounted last');
    }

    public function testSetTabBarPositionReSeatsTheStrip(): void
    {
        $layout = $this->layout();

        $layout->setTabBarPosition(TabBarPosition::Bottom);
        $this->assertSame(['editor-body', 'tabs'], $this->ids($layout->editorPane()), 'bottom: strip after the body');

        $layout->setTabBarPosition(TabBarPosition::Left);
        $this->assertSame(['tabs', 'editor-body'], $this->ids($layout->editorPane()), 'left: strip first in the horizontal pane');

        $layout->setTabBarPosition(TabBarPosition::Top);
        $this->assertSame(['tabs', 'editor-body'], $this->ids($layout->editorPane()));
    }
}
