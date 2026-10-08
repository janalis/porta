<?php

declare(strict_types=1);

namespace Porta\Markdown\Node;

use Porta\Markdown\SourceRange;

/**
 * A raw inline HTML tag, comment or declaration.
 */
final readonly class HtmlInline implements Inline
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
