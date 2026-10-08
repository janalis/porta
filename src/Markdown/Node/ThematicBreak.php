<?php

declare(strict_types=1);

namespace Porta\Markdown\Node;

use Porta\Markdown\SourceRange;

/**
 * A thematic break (`---`, `***`, `___`).
 */
final readonly class ThematicBreak implements Block
{
    public function __construct(
        public SourceRange $range,
    ) {}

    #[\Override]
    public function children(): array
    {
        return [];
    }
}
