<?php

declare(strict_types=1);

namespace Porta\Markdown\Node;

use Porta\Markdown\SourceRange;

/**
 * A bullet or ordered list. $marker is the bullet character, or the ordered delimiter (`.` or `)`).
 */
final readonly class ListBlock implements Block
{
    /**
     * @param list<ListItem> $items
     */
    public function __construct(
        public bool $ordered,
        public int $start,
        public bool $tight,
        public string $marker,
        public array $items,
        public SourceRange $range,
    ) {}

    #[\Override]
    public function children(): array
    {
        return $this->items;
    }
}
