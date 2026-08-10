<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Workbench\Menu;

use PHPUnit\Framework\TestCase;
use Porta\Workbench\Menu\ContributedMenuSource;
use Porta\Workbench\Menu\MenuContribution;
use Porta\Workbench\Menu\MenuContributions;
use Porta\Workbench\Menu\MenuRegistrar;

/**
 * Draining the plugin-contributed top-level menus, once, after plugins commit.
 *
 * The {@see \Porta\Workbench\View\ViewRegistrar} shape, and for the same
 * reason: a plugin class can never be a container service, so nothing it
 * registers arrives by autowiring. Draining lazily on the first read instead
 * would be a race — the menu bar is built during boot, before plugins load, so
 * a cache populated then would be permanently empty.
 *
 * What is worth pinning is that BOTH halves of a contribution survive the trip.
 * A menu that arrives without its view-safe list greys out its own items the
 * moment its view opens, which is a bug with no error anywhere.
 */
final class MenuRegistrarTest extends TestCase
{
    /** @param list<MenuContribution> $menus */
    private function source(array $menus): ContributedMenuSource
    {
        return new class($menus) implements ContributedMenuSource {
            /** @param list<MenuContribution> $menus */
            public function __construct(
                private readonly array $menus,
            ) {}

            public function contributedMenus(): array
            {
                return $this->menus;
            }
        };
    }

    private function contribution(string $title): MenuContribution
    {
        return new MenuContribution($title, after: 'Run', items: ['i:demo'], viewId: strtolower($title), viewSafe: ['ide_quit']);
    }

    public function testEveryContributionReachesTheModelWithItsViewSafeList(): void
    {
        $menus = new MenuContributions();
        new MenuRegistrar($this->source([$this->contribution('Git'), $this->contribution('Demo')]), $menus)->register();

        $this->assertSame(['Git', 'Demo'], array_map(static fn (MenuContribution $c): string => $c->title, $menus->all()), 'in load order');
        $this->assertSame(['git' => ['ide_quit'], 'demo' => ['ide_quit']], $menus->viewSafe(), 'and both halves travelled');
    }

    public function testNothingContributedIsNotAFailure(): void
    {
        // The ordinary state of a stock install, of a non-git directory, and of
        // every session with no plugin that contributes a menu.
        $menus = new MenuContributions();
        new MenuRegistrar($this->source([]), $menus)->register();

        $this->assertSame([], $menus->all());
        $this->assertSame([], $menus->viewSafe());
    }

    public function testRegisteringTwiceWouldDuplicate_soItIsCalledOnce(): void
    {
        // Not a guard, a fact: `MenuContributions` appends, so this class is
        // safe only because `Ide` calls it exactly once. Pinned because the
        // symptom of a second call is two identical top-level menus, which
        // reads as a rendering bug rather than a wiring one.
        $menus = new MenuContributions();
        $registrar = new MenuRegistrar($this->source([$this->contribution('Git')]), $menus);

        $registrar->register();
        $registrar->register();

        $this->assertCount(2, $menus->all());
    }
}
