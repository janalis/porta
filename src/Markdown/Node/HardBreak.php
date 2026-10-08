<?php

declare(strict_types=1);

namespace Porta\Markdown\Node;

use Porta\Markdown\SourceRange;

/**
 * A forced line break (two trailing spaces, or a backslash).
 */
final readonly class HardBreak implements Inline
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
