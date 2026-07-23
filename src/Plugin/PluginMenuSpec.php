<?php

declare(strict_types=1);

namespace Porta\Plugin;

/**
 * One Plugins-menu entry: the owning plugin (for grouping and live removal),
 * the `i:<id>` spec {@see \Porta\Ui\Menu\MenuModel} builds an item from, and the
 * optional enabled-closure (the item dims while it returns false), already
 * wrapped in the plugin fault barrier.
 */
final class PluginMenuSpec
{
    /** @param (\Closure(): bool)|null $when */
    public function __construct(
        public readonly string $plugin,
        public readonly string $spec,
        public readonly ?\Closure $when = null,
    ) {}
}
