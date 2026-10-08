<?php

declare(strict_types=1);

namespace Porta\Markdown\Node;

use Porta\Markdown\SourceRange;

/**
 * Literal text, escapes and entities already decoded.
 */
final readonly class Text implements Inline
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
