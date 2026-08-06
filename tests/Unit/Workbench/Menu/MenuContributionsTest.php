<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Workbench\Menu;

use PHPUnit\Framework\TestCase;
use Porta\Workbench\Menu\MenuContribution;
use Porta\Workbench\Menu\MenuContributions;

/**
 * The registry that replaced `DEFS['Git']`, `VIEW_SAFE['git']` and the
 * rebuilder's one-group visibility closure.
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
        $registry->register(new MenuContribution(title: 'Tools', after: 'Run', items: ['i:x']));

        $this->assertSame([], $registry->viewSafe());
        $this->assertCount(1, $registry->all(), 'the menu itself is still contributed');
    }

    public function testTwoContributionsForOneViewMergeTheirSafeLists(): void
    {
        // Nothing does this today, but the map is keyed by view id and two
        // menus may name the same view; silently keeping the last would
        // disable the other's items while that view is open.
        $registry = new MenuContributions();
        $registry->register(new MenuContribution('A', 'Run', ['i:a'], viewId: 'vcs', viewSafe: ['a']));
        $registry->register(new MenuContribution('B', 'Run', ['i:b'], viewId: 'vcs', viewSafe: ['b']));

        $this->assertSame(['vcs' => ['a', 'b']], $registry->viewSafe());
    }

    public function testRegistrationOrderIsPreserved(): void
    {
        // The order menus appear in, after their shared `after` title.
        $registry = new MenuContributions();
        $registry->register($first = new MenuContribution('A', 'Run', []));
        $registry->register($second = new MenuContribution('B', 'Run', []));

        $this->assertSame([$first, $second], $registry->all());
    }
}
