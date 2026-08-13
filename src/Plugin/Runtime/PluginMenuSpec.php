<?php

declare(strict_types=1);

namespace Porta\Plugin\Runtime;

use Porta\Workbench\Menu\MenuSurface;

/**
 * One plugin menu entry: the owning plugin (for grouping and live removal),
 * the `i:<id>` spec {@see \Porta\Workbench\Menu\MenuModel} builds an item from, the
 * SURFACE it belongs to, and the optional enabled-closure (the item dims while
 * it returns false), already wrapped in the plugin fault barrier.
 */
final readonly class PluginMenuSpec
{
    /** @param (\Closure(): bool)|null $when */
    public function __construct(
        public string $plugin,
        public string $spec,
        public ?\Closure $when = null,
        public MenuSurface $surface = MenuSurface::Plugins,
    ) {}
}
