<?php

declare(strict_types=1);

namespace Porta\Markdown\Node;

use Porta\Markdown\SourceRange;

/**
 * Emphasised (italic) content.
 */
final readonly class Emphasis implements Inline
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
