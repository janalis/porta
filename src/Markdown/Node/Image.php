<?php

declare(strict_types=1);

namespace Porta\Markdown\Node;

use Porta\Markdown\SourceRange;

/**
 * An image; its children are the alt text.
 */
final readonly class Image implements Inline
{
    /**
     * @param list<Inline> $inlines
     */
    public function __construct(
        public string $destination,
        public string $title,
        public array $inlines,
        public SourceRange $range,
    ) {}

    #[\Override]
    public function children(): array
    {
        return $this->inlines;
    }
}
