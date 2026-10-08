<?php

declare(strict_types=1);

namespace Porta\Markdown\Node;

use Porta\Markdown\SourceRange;

/**
 * A footnote's text (`[^label]: …`), wherever it was written.
 */
final readonly class FootnoteDefinition implements Block
{
    /**
     * @param list<Block> $blocks
     */
    public function __construct(
        public string $label,
        public array $blocks,
        public SourceRange $range,
    ) {}

    #[\Override]
    public function children(): array
    {
        return $this->blocks;
    }
}
