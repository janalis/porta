<?php

declare(strict_types=1);

namespace Porta\Search\Everywhere;

/**
 * The modal view the {@see SearchEverywhereController} drives: open it with the
 * query/pick/close callbacks, push the flattened rows as results arrive, and
 * close it. Implemented by the editor widget (which composites the centered box
 * over the editor pane); an interface so the controller is testable with a fake.
 */
interface SearchOverlay
{
    /**
     * @param \Closure(string): void    $onQuery      fired on each query edit
     * @param \Closure(SearchHit): void $onPick       fired when a hit is chosen
     * @param \Closure(): void          $onClose      fired on Esc / dismiss
     * @param string                    $emptyMessage the filler row shown for a no-hit query (pre-translated)
     */
    public function openSearch(string $title, \Closure $onQuery, \Closure $onPick, \Closure $onClose, string $emptyMessage = ''): void;

    /** @param list<SearchRow> $rows */
    public function setSearchRows(array $rows): void;

    public function closeSearch(): void;
}
