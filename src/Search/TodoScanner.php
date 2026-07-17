<?php

declare(strict_types=1);

namespace Porta\Search;

use Porta\Ui\BottomDock;
use Porta\Ui\ResultsPanelMode;

/**
 * The TODO dock tool: one project-wide `TODO|FIXME` scan over the shared
 * search engine, surfaced as a jumpable list. Case-sensitive so prose
 * "todo" stays out; the regex ranges highlight the keyword in each row.
 * Deliberately NOT the find-in-project controller — the scan must not
 * disturb its query state (the engine's one process slot is shared though:
 * starting either search cancels the other, latest wins).
 */
final class TodoScanner
{
    public function __construct(
        private readonly BottomDock $dock,
        private readonly ProjectSearch $engine,
    ) {}

    /** Open (or F5-refresh) the TODO tab and kick off the scan. */
    public function scan(): void
    {
        $tab = $this->dock->showList(ResultsPanelMode::Todo, 'TODO', []);
        $this->dock->markSearching($tab);
        $this->engine->search(
            'TODO|FIXME',
            function (array $results) use ($tab): void {
                if (!$this->dock->isToolAlive($tab)) {
                    return; // the tab was ×'d before grep finished
                }
                $tab->rows = $results;
                $tab->searching = false;
                $this->dock->refreshIfBound($tab);
            },
            new SearchOptions(regex: true, caseSensitive: true),
        );
    }
}
