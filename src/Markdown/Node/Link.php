<?php

declare(strict_types=1);

namespace Porta\Markdown\Node;

use Porta\Markdown\SourceRange;

/**
 * A link: inline, reference, or an autolink ($autolink set, its text being the address).
 */
final readonly class Link implements Inline
{
    /**
     * @param list<Inline> $inlines
     */
    public function __construct(
        public string $destination,
        public string $title,
        public array $inlines,
        public SourceRange $range,
        public bool $autolink = false,
    ) {}

    #[\Override]
    public function children(): array
    {
        return $this->inlines;
    }
}
