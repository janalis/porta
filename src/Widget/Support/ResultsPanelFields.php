<?php

declare(strict_types=1);

namespace Porta\Widget\Support;

/**
 * Which input fields the shared results panel shows when opened — a grouped tree
 * and a client-side filter — as one value instead of positional flags on
 * {@see ResultsPanelWidget::open()}. The named factories spell out the panel's
 * real modes at the call sites.
 */
final readonly class ResultsPanelFields
{
    public function __construct(
        public bool $grouped = false,
        public bool $filter = false,
    ) {}

    /** A static list with no inputs (problems, local history, go-to results). */
    public static function resultsOnly(): self
    {
        return new self();
    }

    /** The Find tool window: grouped results + client-side filter, no query-configuration rows (that lives in the modal). */
    public static function searchResults(): self
    {
        return new self(grouped: true, filter: true);
    }

    /**
     * Problems: the same two fields the Find tool gets, and for the same
     * reason — the list runs to every diagnostic in every open buffer, so
     * grouping it by file is how you read it and filtering is how you find the
     * one you came for.
     */
    public static function problemList(): self
    {
        return self::searchResults();
    }
}
