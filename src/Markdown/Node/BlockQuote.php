<?php

declare(strict_types=1);

namespace Porta\Markdown\Node;

use Porta\Markdown\SourceRange;

/**
 * A block quote; a GitHub alert (`> [!NOTE]`) when $alert is set, its marker line then dropped from the children.
 */
final readonly class BlockQuote implements Block
{
    /**
     * @param list<Block> $blocks
     */
    public function __construct(
        public array $blocks,
        public SourceRange $range,
        public ?AlertKind $alert = null,
    ) {}

    #[\Override]
    public function children(): array
    {
        return $this->blocks;
    }
}
