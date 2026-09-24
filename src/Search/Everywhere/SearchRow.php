<?php

declare(strict_types=1);

namespace Porta\Search\Everywhere;

/**
 * One rendered row of the Search Everywhere modal: a category **header**, a
 * result **hit**, or a **note** — a line of its own under a header that is not
 * a result, such as "Searching…" while a slow source has not answered. The
 * controller flattens grouped results into a single list of these so the
 * overlay renders them together while selection lands on hits alone.
 */
final readonly class SearchRow
{
    private function __construct(
        public ?SearchHit $hit,
        /** The already-translated group label, or null. */
        public ?string $header = null,
        /** An already-translated line that is not a result, or null. */
        public ?string $note = null,
    ) {}

    public static function header(string $label): self
    {
        return new self(null, header: $label);
    }

    public static function hit(SearchHit $hit): self
    {
        return new self($hit);
    }

    public static function note(string $text): self
    {
        return new self(null, note: $text);
    }

    public function isHeader(): bool
    {
        return null !== $this->header;
    }

    /** Whether the selection can land here: a result, never a header or a note. */
    public function isHit(): bool
    {
        return null !== $this->hit;
    }
}
