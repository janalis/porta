<?php

declare(strict_types=1);

namespace Porta\Plugin\Store;

use Porta\Plugin\Lifecycle\InstallScope;

/** One installed plugin the store has a newer release of: which copy, where, and from what to what. */
final readonly class PluginUpdate
{
    public function __construct(
        public string $name,
        public InstallScope $scope,
        public string $package,
        public string $from,
        public string $to,
        public string $dir = '',
    ) {}
}
