<?php

declare(strict_types=1);

namespace Porta\Workbench\Dock;

use Porta\Search\SearchTabState;
use Porta\Widget\Support\ResultsPanelFields;
use Porta\Widget\Support\ResultsViewState;

/**
 * One list-tool instance living as a tab on the dock strip: a Problems run,
 * a TODO scan, one Find-in-Project query, a Usages list, a log… Created by
 * {@see DockToolTabs} (not a service, like a terminal session); the results
 * panel widget displays exactly one of these at a time and the tab carries
 * the state that survives a switch — rows, title, the searching flag, the
 * frozen view state (filter/grouping/collapse/context/selection, captured by
 * the dock on switch-away) and (for Find-in-Project tabs) the query
 * definition.
 *
 * A CONTRIBUTED tool (a plugin's) has a null $mode and carries its identity and
 * behaviour in {@see DockToolHandlers} instead — see that class for why null
 * rather than an extra enum case.
 */
final class DockToolTab
{
    /** @var list<array{path: string, line: int, text: string, ranges?: list<array{start: int, length: int}>}> */
    public array $rows = [];

    /** Async work (grep/scan) still in flight for this tab. */
    public bool $searching = false;

    /** The find-in-project definition behind a Search tab; null for other Search-mode lists (Usages, Choose target). */
    public ?SearchTabState $searchState = null;

    /** The view state frozen when the dock last switched away from this tab; null until then. */
    public ?ResultsViewState $viewState = null;

    public function __construct(
        /** The strip identity — stable across renames-by-reuse, cheap to fingerprint. */
        public readonly int $id,
        /** Which of core's multiplexed contents this serves; null for a contributed tool. */
        public readonly ?ResultsPanelMode $mode,
        public readonly string $title,
        public ResultsPanelFields $fields,
        /** Set for a contributed tool, null for core's own. */
        public readonly ?DockToolHandlers $handlers = null,
    ) {}
}
