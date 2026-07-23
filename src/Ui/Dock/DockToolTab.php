<?php

declare(strict_types=1);

namespace Porta\Ui\Dock;

use Porta\Search\SearchTabState;
use Porta\Widget\ResultsPanelFields;

/**
 * One list-tool instance living as a tab on the dock strip: a Problems run,
 * a TODO scan, one Find-in-Project query, a Usages list, a log… Created by
 * {@see DockToolTabs} (not a service, like a terminal session); the results
 * panel widget displays exactly one of these at a time and the tab carries
 * the state that survives a switch — rows, title, the searching flag and
 * (for Find-in-Project tabs) the query definition.
 */
final class DockToolTab
{
    /** @var list<array{path: string, line: int, text: string, ranges?: list<array{start: int, length: int}>}> */
    public array $rows = [];

    /** Async work (grep/scan) still in flight for this tab. */
    public bool $searching = false;

    /** The find-in-project definition behind a Search tab; null for other Search-mode lists (Usages, Choose target). */
    public ?SearchTabState $searchState = null;

    public function __construct(
        /** The strip identity — stable across renames-by-reuse, cheap to fingerprint. */
        public readonly int $id,
        public readonly ResultsPanelMode $mode,
        public readonly string $title,
        public ResultsPanelFields $fields,
    ) {}
}
