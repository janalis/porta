<?php

declare(strict_types=1);

namespace Porta\Search\Everywhere;

/**
 * A single source of Search Everywhere results (files, actions, classes,
 * symbols, text). {@see query()} runs the search and calls `$emit` **once**
 * with the source's hits already in best-first order (sync for local sources,
 * async for LSP/grep). An answer of nothing may carry a note saying why — "No
 * language server" — which the modal shows under the category's header rather
 * than a silence that reads as "not found". {@see cancel()} abandons an
 * in-flight async request so a late response is ignored. The controller wraps
 * `$emit` with a staleness guard.
 */
interface SearchSource
{
    public function category(): SearchCategory;

    /** @param \Closure(list<SearchHit>, ?string=): void $emit hits, and a note when there are none for a reason */
    public function query(string $query, \Closure $emit): void;

    public function cancel(): void;
}
