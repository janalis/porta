<?php

declare(strict_types=1);

namespace Porta\Overlay\State;

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
     * The widest row seen since the modal opened, in columns. The box is sized
     * from it rather than from this frame's rows, so it never shrinks and
     * re-centres under the reader as results stream in or the query narrows.
     */
    public int $widest = 0;

    /** The most rows seen since the modal opened: the box keeps that height as the list shortens, as it keeps its width. */
    public int $tallest = 0;

    /** The query the current rows answer: rows for the SAME query keep the selected hit. */
    public ?string $rowsFor = null;

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
