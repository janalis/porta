<?php

declare(strict_types=1);

namespace Porta\Markdown\Node;

use Porta\Markdown\SourceRange;

/**
 * An ATX (`## x`) or setext (underlined) heading, level 1 to 6.
 */
final readonly class Heading implements Block
{
    /**
     * @param list<Inline> $inlines
     */
    public function __construct(
        public int $level,
        public array $inlines,
        public SourceRange $range,
        public bool $setext = false,
    ) {}

    #[\Override]
    public function children(): array
    {
        return $this->inlines;
    }
}
