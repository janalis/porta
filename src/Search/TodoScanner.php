<?php

declare(strict_types=1);

namespace Porta\Search;

use Porta\Ui\BottomDock;
use Porta\Ui\ResultsPanelMode;
use Porta\Widget\ResultsPanelWidget;
use Symfony\Component\Tui\Tui;

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
        private readonly ResultsPanelWidget $resultsPanel,
        private readonly ProjectSearch $engine,
        private readonly Tui $tui,
    ) {}

    /** Open (or F5-refresh) the TODO tab and kick off the scan. */
    public function scan(): void
    {
        $this->dock->showList(ResultsPanelMode::Todo, 'TODO', []);
        $this->resultsPanel->setSearching();
        $this->engine->search(
            'TODO|FIXME',
            function (array $results): void {
                // Stale completion: the user moved the panel on before grep finished.
                if (ResultsPanelMode::Todo !== $this->dock->mode() || !$this->resultsPanel->isOpen()) {
                    return;
                }
                $this->resultsPanel->setResults($results);
                $this->tui->requestRender();
            },
            new SearchOptions(regex: true, caseSensitive: true),
        );
    }
}
