<?php

declare(strict_types=1);

namespace Porta\Search\Everywhere;

/**
 * One rendered row of the Search Everywhere modal: either a category **header**
 * or a result **hit**. The controller flattens grouped results into a single
 * list of these so the overlay renders headers and hits together while keeping
 * selection constrained to hit rows.
 */
final readonly class SearchRow
{
    private function __construct(
        /** The already-translated group label, or null for a hit row. */
        public ?string $header,
        public ?SearchHit $hit,
    ) {}

    public static function header(string $label): self
    {
        return new self($label, null);
    }

    public static function hit(SearchHit $hit): self
    {
        return new self(null, $hit);
    }

    public function isHeader(): bool
    {
        return null !== $this->header;
    }
}
