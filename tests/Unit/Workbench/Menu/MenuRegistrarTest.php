<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Workbench\Menu;

use PHPUnit\Framework\TestCase;
use Porta\Workbench\Menu\ContributedMenuSource;
use Porta\Workbench\Menu\MenuContribution;
use Porta\Workbench\Menu\MenuContributions;
use Porta\Workbench\Menu\MenuRegistrar;

/**
 * Draining the plugin-contributed top-level menus: everything after plugins
 * commit at boot, one plugin's slice on a live toggle.
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
    /** @param list<array{plugin: string, contribution: MenuContribution}> $menus */
    private function source(array $menus): ContributedMenuSource
    {
        return new class($menus) implements ContributedMenuSource {
            /** @param list<array{plugin: string, contribution: MenuContribution}> $menus */
            public function __construct(
                private readonly array $menus,
            ) {}

            public function contributedMenus(): array
            {
                return $this->menus;
            }
        };
    }

    /** @return array{plugin: string, contribution: MenuContribution} */
    private function contribution(string $plugin, string $title): array
    {
        return [
            'plugin' => $plugin,
            'contribution' => new MenuContribution($title, after: 'Run', items: ['i:demo'], viewId: strtolower($title), viewSafe: ['ide_quit']),
        ];
    }

    public function testEveryContributionReachesTheModelWithItsViewSafeList(): void
    {
        $menus = new MenuContributions();
        new MenuRegistrar($this->source([$this->contribution('git', 'Git'), $this->contribution('demo', 'Demo')]), $menus)->register();

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
        // Not a guard, a fact: `MenuContributions` appends, so the FULL drain
        // is safe only because `Ide` calls it exactly once. Pinned because the
        // symptom of a second call is two identical top-level menus, which
        // reads as a rendering bug rather than a wiring one. The live path is
        // registerFor(), which is idempotent below.
        $menus = new MenuContributions();
        $registrar = new MenuRegistrar($this->source([$this->contribution('git', 'Git')]), $menus);

        $registrar->register();
        $registrar->register();

        $this->assertCount(2, $menus->all());
    }

    public function testRegisterForDrainsOnlyTheNamedPluginAndIsIdempotent(): void
    {
        // Forget-then-add is what makes a re-enable safe: without it, every
        // toggle would append a second copy of the same title.
        $menus = new MenuContributions();
        $registrar = new MenuRegistrar($this->source([$this->contribution('git', 'Git'), $this->contribution('demo', 'Demo')]), $menus);

        $registrar->registerFor('git');
        $registrar->registerFor('git');

        $this->assertSame(['Git'], array_map(static fn (MenuContribution $c): string => $c->title, $menus->all()), 'one copy, only the named plugin');
    }

    public function testForgetRetractsThePluginsMenus(): void
    {
        $menus = new MenuContributions();
        $registrar = new MenuRegistrar($this->source([$this->contribution('git', 'Git'), $this->contribution('demo', 'Demo')]), $menus);
        $registrar->register();

        $registrar->forget('git');

        $this->assertSame(['Demo'], array_map(static fn (MenuContribution $c): string => $c->title, $menus->all()));
    }
}
