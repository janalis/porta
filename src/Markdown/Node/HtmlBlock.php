<?php

declare(strict_types=1);

namespace Porta\Markdown\Node;

use Porta\Markdown\SourceRange;

/**
 * A raw HTML block, passed through verbatim.
 */
final readonly class HtmlBlock implements Block
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
