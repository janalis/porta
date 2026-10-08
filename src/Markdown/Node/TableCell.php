<?php

declare(strict_types=1);

namespace Porta\Markdown\Node;

use Porta\Markdown\SourceRange;

/**
 * One cell of a table row.
 */
final readonly class TableCell implements Block
{
    /**
     * @param list<Inline> $inlines
     */
    public function __construct(
        public array $inlines,
        public SourceRange $range,
    ) {}

    #[\Override]
    public function children(): array
    {
        return $this->inlines;
    }
}
