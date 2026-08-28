<?php

declare(strict_types=1);

namespace Porta\Workbench\Menu;

use Porta\Container\AsIdeService;

/**
 * Puts every plugin-contributed top-level menu into {@see MenuContributions} —
 * the full drain after plugins commit at boot, and one plugin's slice on a
 * live enable ({@see registerFor()}) or disable ({@see forget()}).
 *
 * The {@see \Porta\Workbench\View\ViewRegistrar} shape, for the same reason: a
 * plugin class can never be a container service, so nothing a plugin registers
 * can arrive by autowiring. Draining lazily on the first read instead would be
 * a race — the menu bar is built during boot, before plugins load, and a cache
 * populated then would be permanently empty.
 *
 * No fault barrier here, unlike the view registrar: a contribution is a VALUE,
 * already built by `PluginApi::registerMenu()` inside the registering plugin's
 * own barrier, and putting it in a list cannot fail. A view is a closure this
 * calls, which is the difference.
 */
#[AsIdeService]
final readonly class MenuRegistrar
{
    public function __construct(
        private ContributedMenuSource $source,
        private MenuContributions $menus,
    ) {}

    public function register(): void
    {
        foreach ($this->source->contributedMenus() as $entry) {
            $this->menus->register($entry['plugin'], $entry['contribution']);
        }
    }

    /**
     * Re-drain one plugin's menus — forget-then-add, so it is idempotent by
     * construction and a re-enable cannot double a title.
     */
    public function registerFor(string $plugin): void
    {
        $this->menus->forget($plugin);
        foreach ($this->source->contributedMenus() as $entry) {
            if ($plugin !== $entry['plugin']) {
                continue;
            }

            $this->menus->register($entry['plugin'], $entry['contribution']);
        }
    }

    /**
     * Retract one plugin's menus. Here rather than at the call site so the
     * lifecycle only ever talks to the two registrars; the caller's menu
     * rebuild is what repaints the bar without them.
     */
    public function forget(string $plugin): void
    {
        $this->menus->forget($plugin);
    }
}
