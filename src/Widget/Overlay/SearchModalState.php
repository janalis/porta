<?php

declare(strict_types=1);

namespace Porta\Widget\Overlay;

use Porta\Search\Everywhere\SearchHit;
use Porta\Search\Everywhere\SearchRow;
use Porta\Ui\TextField;

/**
 * Mutable state for the Search Everywhere modal owned by {@see OverlayHost}: the
 * query field, the current rows, the selected hit-row index, and the driving
 * callbacks. A small object (rather than an array shape) so the host can capture
 * it into a local and mutate it across helper calls without null-narrowing noise.
 */
final class SearchModalState
{
    /** @var list<SearchRow> */
    public array $rows = [];
    public int $selected = 0;
    /** Hit row tinted under the pointer; visual only, never the selection. */
    public ?int $hovered = null;

    /**
     * @param \Closure(string): void    $onQuery fired on each query edit
     * @param \Closure(SearchHit): void $onPick  fired when a hit is chosen
     * @param \Closure(): void          $onClose fired on Esc / dismiss
     */
    public function __construct(
        public readonly string $title,
        public readonly TextField $query,
        public readonly \Closure $onQuery,
        public readonly \Closure $onPick,
        public readonly \Closure $onClose,
        public readonly string $emptyMessage = '',
    ) {}
}
