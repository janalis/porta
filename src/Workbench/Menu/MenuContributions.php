<?php

declare(strict_types=1);

namespace Porta\Workbench\Menu;

use Porta\Container\AsIdeService;

/** The contributed top-level menus, in registration order. */
#[AsIdeService]
final class MenuContributions
{
    /** @var list<MenuContribution> */
    private array $contributions = [];

    public function register(MenuContribution $contribution): void
    {
        $this->contributions[] = $contribution;
    }

    /** @return list<MenuContribution> */
    public function all(): array
    {
        return $this->contributions;
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
        foreach ($this->contributions as $contribution) {
            if ('' === $contribution->viewId) {
                continue;
            }
            $map[$contribution->viewId] = [...($map[$contribution->viewId] ?? []), ...$contribution->viewSafe];
        }

        return $map;
    }
}
