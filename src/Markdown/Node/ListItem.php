<?php

declare(strict_types=1);

namespace Porta\Markdown\Node;

use Porta\Markdown\SourceRange;

/**
 * One list item. A GFM task item has $checked set and $checkbox covering its `[ ]`/`[x]` in the source.
 */
final readonly class ListItem implements Block
{
    /**
     * @param list<Block> $blocks
     */
    public function __construct(
        public array $blocks,
        public SourceRange $range,
        public SourceRange $marker,
        public ?bool $checked = null,
        public ?SourceRange $checkbox = null,
    ) {}

    #[\Override]
    public function children(): array
    {
        return $this->blocks;
    }
}
