<?php

declare(strict_types=1);

namespace Porta\Markdown\Node;

use Porta\Markdown\SourceRange;

/**
 * The whole document: its top-level blocks.
 */
final readonly class Document implements Block
{
    /**
     * @param list<Block> $blocks
     */
    public function __construct(
        public array $blocks,
        public SourceRange $range,
    ) {}

    #[\Override]
    public function children(): array
    {
        return $this->blocks;
    }
}
