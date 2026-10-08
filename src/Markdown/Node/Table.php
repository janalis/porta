<?php

declare(strict_types=1);

namespace Porta\Markdown\Node;

use Porta\Markdown\SourceRange;

/**
 * A GFM table: the header row, the body rows, and each column's alignment.
 */
final readonly class Table implements Block
{
    /**
     * @param list<Alignment> $alignments
     * @param list<TableRow> $rows
     */
    public function __construct(
        public array $alignments,
        public TableRow $header,
        public array $rows,
        public SourceRange $range,
    ) {}

    #[\Override]
    public function children(): array
    {
        return [$this->header, ...$this->rows];
    }
}
