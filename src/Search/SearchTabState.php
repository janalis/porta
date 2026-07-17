<?php

declare(strict_types=1);

namespace Porta\Search;

use Porta\Ui\ScopeKind;

/**
 * The find-in-project definition behind one Search dock tab, frozen when the
 * result set is promoted to the pane: switching back to the tab re-hydrates
 * the controller with it, so F5/replace/export always act on the tab's own
 * query — not whatever was searched last.
 */
final readonly class SearchTabState
{
    /**
     * @param list<string> $scope
     */
    public function __construct(
        public string $query,
        public SearchOptions $options,
        public array $scope,
        public string $mask,
        public ScopeKind $scopeKind,
    ) {}
}
