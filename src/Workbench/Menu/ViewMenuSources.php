<?php

declare(strict_types=1);

namespace Porta\Workbench\Menu;

use Porta\Container\AsIdeService;

/**
 * Which {@see ViewMenuSource} answers for which view id.
 *
 * A view that offers no context menus registers nothing, and both callers get
 * null — so neither grows a branch per view, which is how the context-menu
 * router and opener came to name git in the first place.
 */
#[AsIdeService]
final class ViewMenuSources
{
    /** @var array<string, ViewMenuSource> */
    private array $sources = [];

    public function register(string $viewId, ViewMenuSource $source): void
    {
        $this->sources[$viewId] = $source;
    }

    /** Drop a retracted view's context menus; {@see of()} already answers null. */
    public function forget(string $viewId): void
    {
        unset($this->sources[$viewId]);
    }

    public function of(?string $viewId): ?ViewMenuSource
    {
        return null === $viewId ? null : $this->sources[$viewId] ?? null;
    }
}
