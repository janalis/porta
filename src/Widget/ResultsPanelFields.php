<?php

declare(strict_types=1);

namespace Porta\Widget;

/**
 * Which input fields the shared results panel shows when opened — the query box,
 * the search-option toggles, and the replace box — as one value instead of three
 * positional flags on {@see ResultsPanelWidget::open()}. The named factories
 * spell out the panel's real modes at the call sites.
 */
final readonly class ResultsPanelFields
{
    public function __construct(
        public bool $query = false,
        public bool $options = false,
        public bool $replace = false,
        public bool $mask = false,
        public bool $scope = false,
    ) {}

    /** Live query only (command palette, symbol search). */
    public static function queryOnly(): self
    {
        return new self(query: true);
    }

    /** A static list with no inputs (problems, local history, go-to results). */
    public static function resultsOnly(): self
    {
        return new self();
    }

    /** Query + regex/case/word toggles + scope + file mask + replace box (find-in-project). */
    public static function fullSearch(): self
    {
        return new self(query: true, options: true, replace: true, mask: true, scope: true);
    }
}
