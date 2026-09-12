<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Layout;

use PHPUnit\Framework\TestCase;
use Porta\Layout\WorkspaceLayout;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\TestWorkspaceLayout;
use Porta\Widget\Support\TabBarPosition;
use Porta\Widget\TabBarWidget;
use Symfony\Component\Tui\Style\Direction;
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
        $this->assertSame(['tabs', 'editor-body'], $this->ids(Probe::get($layout, 'editorPane')), 'top position at boot');
        $this->assertSame(['breadcrumbs', 'find-bar', 'editor'], $this->ids($layout->editorBody()), 'the find bar sits below the breadcrumbs, above the content slot');
        // A full-screen view's own sidebar/separator/content is ViewRegions'
        // now, minted at runtime — see ViewRegionsTest.
        $this->assertSame('separator', $layout->separator()->getId());
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
        $this->assertSame(['editor-body', 'tabs'], $this->ids(Probe::get($layout, 'editorPane')), 'bottom: strip after the body');

        $layout->setTabBarPosition(TabBarPosition::Left);
        $this->assertSame(['tabs', 'editor-body'], $this->ids(Probe::get($layout, 'editorPane')), 'left: strip first in the horizontal pane');

        $layout->setTabBarPosition(TabBarPosition::Top);
        $this->assertSame(['tabs', 'editor-body'], $this->ids(Probe::get($layout, 'editorPane')));
    }

    public function testAMirroredWorkspaceSeatsASideStripOnTheOtherEdge(): void
    {
        // Child order is PHYSICAL: first child, leftmost column. So a strip the
        // user put on the RIGHT has to become the FIRST child once the
        // workspace mirrors, or it ends up between the editor and the
        // (now right-hand) file tree instead of at the far edge. Found by
        // sweeping the app's surfaces in Hebrew, where the tab strip sat at
        // columns 175..198 with the tree at 200.
        $layout = $this->layout();
        $layout->setTabBarPosition(TabBarPosition::Right);
        $this->assertSame(['editor-body', 'tabs'], $this->ids(Probe::get($layout, 'editorPane')), 'left-to-right: strip after the body');

        $layout->setMirrored(true);
        $this->assertSame(['tabs', 'editor-body'], $this->ids(Probe::get($layout, 'editorPane')), 'mirrored: the right-hand strip seats first');

        $layout->setMirrored(false);
        $this->assertSame(['editor-body', 'tabs'], $this->ids(Probe::get($layout, 'editorPane')), 'and back again');
    }

    public function testMirroringLeavesATopStripAlone(): void
    {
        // Mirroring is horizontal; top and bottom have no side to swap.
        $layout = $this->layout();
        $layout->setTabBarPosition(TabBarPosition::Bottom);

        $layout->setMirrored(true);

        $this->assertSame(['editor-body', 'tabs'], $this->ids(Probe::get($layout, 'editorPane')));
    }

    public function testTheConstructedContainersCarryTheirExactFlexAndGap(): void
    {
        // Pins the layout constants themselves: every sibling of `main` (the
        // tree, the separator, editor-pane) shares its flex budget by these
        // ratios, and a wrong one only shows up as a visibly wrong split at
        // render time — cheaper to pin here directly.
        $layout = $this->layout();

        $editorBody = Probe::get($layout, 'editorBody');
        $this->assertSame(1, $editorBody->getStyle()?->getFlex());

        $editorPane = Probe::get($layout, 'editorPane');
        $this->assertSame(1, $editorPane->getStyle()?->getFlex());

        $main = Probe::get($layout, 'main');
        $this->assertSame(Direction::Horizontal, $main->getStyle()?->getDirection());
        $this->assertSame(0, $main->getStyle()?->getGap());
    }

    public function testSetTabBarPositionSetsTheSideStyleWithHorizontalDirection(): void
    {
        $layout = $this->layout();

        $layout->setTabBarPosition(TabBarPosition::Left);

        $editorPane = Probe::get($layout, 'editorPane');
        $this->assertSame(1, $editorPane->getStyle()?->getFlex());
        $this->assertSame(Direction::Horizontal, $editorPane->getStyle()?->getDirection(), 'a side position stacks the tab list beside the body, not above it');
    }

    public function testSetTabBarPositionSetsTheTopStyleWithNoDirectionOverride(): void
    {
        $layout = $this->layout();
        // Move it to a side position first so the default (non-side) branch's
        // own style assignment is what's actually observed afterward, not a
        // leftover from construction.
        $layout->setTabBarPosition(TabBarPosition::Left);

        $layout->setTabBarPosition(TabBarPosition::Top);

        $editorPane = Probe::get($layout, 'editorPane');
        $this->assertSame(1, $editorPane->getStyle()?->getFlex());
        $this->assertNull($editorPane->getStyle()?->getDirection(), 'top/bottom stacks vertically — the container default, no direction override');
    }
}
