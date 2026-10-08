<?php

declare(strict_types=1);

namespace Porta\Markdown\Node;

use Porta\Markdown\SourceRange;

/**
 * One row of a table.
 */
final readonly class TableRow implements Block
{
    /**
     * @param list<TableCell> $cells
     */
    public function __construct(
        public array $cells,
        public SourceRange $range,
    ) {}

    #[\Override]
    public function children(): array
    {
        return $this->cells;
    }
}
