<?php

declare(strict_types=1);

namespace Porta\Markdown\Node;

use Porta\Markdown\SourceRange;

/**
 * A reference to a footnote (`[^label]`) whose definition exists.
 */
final readonly class FootnoteReference implements Inline
{
    public function __construct(
        public string $label,
        public SourceRange $range,
    ) {}

    #[\Override]
    public function children(): array
    {
        return [];
    }
}
