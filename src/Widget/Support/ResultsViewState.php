<?php

declare(strict_types=1);

namespace Porta\Widget\Support;

/**
 * A results-panel tab's view state, frozen when the dock switches the shared
 * widget to another tab and restored when the tab is bound again — the
 * {@see \Porta\Search\SearchTabState} pattern applied to the VIEW side. It
 * rides {@see \Porta\Workbench\Dock\DockToolTab}, so closing the tab drops it.
 *
 * Selection and context are stored by IDENTITY (path + line), never by
 * position: a live refresh may rewrite a parked tab's rows, so a positional
 * key captured at switch-away can point at a different match at switch-back.
 * Fallbacks at restore: the selected group header's key, then the clamped
 * display index.
 */
final readonly class ResultsViewState
{
    /**
     * @param array<string, bool>                                  $collapsed       group key (file or directory) => collapsed
     * @param array<string, bool>                                  $contextExpanded "path\0line" identity => context expanded
     * @param array<string, list<array{line: int, text: string}>>  $contextCache    "path\0line" identity => cached neighbouring lines
     */
    public function __construct(
        public string $filter,
        public ResultGrouping $grouping,
        public array $collapsed,
        public ?string $selectedPath,
        public ?int $selectedLine,
        public ?string $selectedGroupKey,
        public int $selectedIndex,
        public array $contextExpanded,
        public array $contextCache,
    ) {}
}
