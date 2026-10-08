<?php

declare(strict_types=1);

namespace Porta\Markdown\Node;

use Porta\Markdown\SourceRange;

/**
 * A node of a parsed markdown document: where in the source it was written,
 * and what it holds.
 */
interface Node
{
    public SourceRange $range { get; }

    /** @return list<Node> */
    public function children(): array;
}
