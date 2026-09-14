<?php

declare(strict_types=1);

namespace Porta\Workbench\Menu;

use Porta\Container\AsIdeService;

/**
 * The contributed top-level menus, in registration order — keyed to the plugin
 * that contributed each one, so a live disable can retract exactly its menus.
 */
#[AsIdeService]
final class MenuContributions
{
    /** @var list<array{plugin: string, contribution: MenuContribution}> */
    private array $contributions = [];

    public function register(string $plugin, MenuContribution $contribution): void
    {
        $this->contributions[] = ['plugin' => $plugin, 'contribution' => $contribution];
    }

    /** Drop everything $plugin contributed — the disable half of the symmetry. */
    public function forget(string $plugin): void
    {
        $this->contributions = \array_values(\array_filter(
            $this->contributions,
            static fn (array $entry): bool => $plugin !== $entry['plugin'],
        ));
    }

    /** @return list<MenuContribution> */
    public function all(): array
    {
        return \array_column($this->contributions, 'contribution');
    }

    /**
     * The view-safe command lists, keyed by view id — merged from every
     * contribution that named a view.
     *
     * @return array<string, list<string>>
     */
    public function viewSafe(): array
    {
        $map = [];
        foreach ($this->all() as $contribution) {
            if ('' === $contribution->viewId) {
                continue;
            }
            $map[$contribution->viewId] = [...($map[$contribution->viewId] ?? []), ...$contribution->viewSafe];
        }

        return $map;
    }
}
