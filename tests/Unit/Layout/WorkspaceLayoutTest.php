<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Layout;

use PHPUnit\Framework\TestCase;
use Porta\Layout\WorkspaceLayout;
use Porta\Tests\Support\TestWorkspaceLayout;
use Porta\Widget\TabBarPosition;
use Porta\Widget\TabBarWidget;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\ContainerWidget;

final class WorkspaceLayoutTest extends TestCase
{
    private TabBarWidget $tabBar;

    private function layout(): WorkspaceLayout
    {
        // The factory applies each #[AsWidget] id, as the container does in
        // production; the tab bar is held so the position tests can drive it.
        $this->tabBar = new TabBarWidget();
        $this->tabBar->setId('tabs');

        return TestWorkspaceLayout::create(tabBar: $this->tabBar);
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
