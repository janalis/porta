<?php

declare(strict_types=1);

namespace Porta\Markdown\Block;

/**
 * A list item's marker as the block parser read it: bullet or ordered, its
 * character (`-`, `+`, `*`, or the `.`/`)` after a number), the number, and
 * the columns before the marker and between it and the content.
 */
final readonly class ListMarker
{
    public function __construct(
        public bool $ordered,
        public string $char,
        public int $start,
        public int $markerOffset,
        public int $padding,
        public int $sourceStart,
        public int $sourceEnd,
    ) {}

    /** Whether an item with $other's marker continues the same list. */
    public function sameListAs(self $other): bool
    {
        return $this->ordered === $other->ordered && $this->char === $other->char;
    }
}
