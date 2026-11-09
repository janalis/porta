<?php

declare(strict_types=1);

namespace Porta\Plugin\Store;

/**
 * One entry as the store draws it: the plugin, its button, and — when it is
 * installed — the version that is, and the one an Update would bring.
 */
final readonly class StoreRow
{
    public function __construct(
        public StoreEntry $entry,
        public StoreAction $action,
        public string $installed = '',
        public ?PluginUpdate $update = null,
    ) {}
}
