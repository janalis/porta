<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Workbench\Menu;

use PHPUnit\Framework\TestCase;
use Porta\Workbench\Menu\MenuContribution;
use Porta\Workbench\Menu\MenuContributions;

/**
 * The registry that replaced `DEFS['Git']`, `VIEW_SAFE['git']` and the
 * rebuilder's one-group visibility closure — plugin-keyed, so a live disable
 * retracts exactly its contributor's menus.
 */
final class MenuContributionsTest extends TestCase
{
    public function testAMenuNeedNotBelongToAView(): void
    {
        // A top-level menu is not the same thing as a full-screen view. A
        // contributor with no view of its own contributes no view-safe list,
        // and must not appear in the map keyed by '' — which would then gate
        // every menu item the moment ANY unnamed view was open.
        $registry = new MenuContributions();
        $registry->register('demo', new MenuContribution(title: 'Tools', after: 'Run', items: ['i:x']));

        $this->assertSame([], $registry->viewSafe());
        $this->assertCount(1, $registry->all(), 'the menu itself is still contributed');
    }

    public function testTwoContributionsForOneViewMergeTheirSafeLists(): void
    {
        // Nothing does this today, but the map is keyed by view id and two
        // menus may name the same view; silently keeping the last would
        // disable the other's items while that view is open.
        $registry = new MenuContributions();
        $registry->register('a', new MenuContribution('A', 'Run', ['i:a'], viewId: 'vcs', viewSafe: ['a']));
        $registry->register('b', new MenuContribution('B', 'Run', ['i:b'], viewId: 'vcs', viewSafe: ['b']));

        $this->assertSame(['vcs' => ['a', 'b']], $registry->viewSafe());
    }

    public function testAViewlessContributionDoesNotStopLaterOnesFromContributingTheirSafeList(): void
    {
        // The loop must skip a viewless entry and keep scanning, not stop at
        // it — otherwise any menu registered without a view would silently
        // blind every later contribution's view-safe list too.
        $registry = new MenuContributions();
        $registry->register('demo', new MenuContribution('Tools', 'Run', ['i:x']));
        $registry->register('vcs-plugin', new MenuContribution('A', 'Run', ['i:a'], viewId: 'vcs', viewSafe: ['a']));

        $this->assertSame(['vcs' => ['a']], $registry->viewSafe());
    }

    public function testRegistrationOrderIsPreserved(): void
    {
        // The order menus appear in, after their shared `after` title.
        $registry = new MenuContributions();
        $registry->register('a', $first = new MenuContribution('A', 'Run', []));
        $registry->register('b', $second = new MenuContribution('B', 'Run', []));

        $this->assertSame([$first, $second], $registry->all());
    }

    public function testForgettingAPluginDropsOnlyItsMenus(): void
    {
        // The disable half: a disabled plugin's top-level menu used to keep
        // rendering forever, because nothing could remove it.
        $registry = new MenuContributions();
        $registry->register('mine', new MenuContribution('Mine', 'Run', [], viewId: 'mine', viewSafe: ['x']));
        $registry->register('theirs', $kept = new MenuContribution('Theirs', 'Run', []));
        $registry->register('mine', new MenuContribution('Mine Too', 'Run', []));

        $registry->forget('mine');

        $this->assertSame([$kept], $registry->all(), 'both of the plugin\'s menus went, the other plugin\'s stayed');
        $this->assertSame([], $registry->viewSafe(), 'its view-safe list went with it');
    }
}
