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
        public ?SearchCategory $header,
        public ?SearchHit $hit,
    ) {}

    public static function header(SearchCategory $category): self
    {
        return new self($category, null);
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
