<?php

declare(strict_types=1);

namespace Porta\Markdown\Node;

use Porta\Markdown\SourceRange;

/**
 * An inline code span.
 */
final readonly class CodeSpan implements Inline
{
    public function __construct(
        public string $literal,
        public SourceRange $range,
    ) {}

    #[\Override]
    public function children(): array
    {
        return [];
    }
}
