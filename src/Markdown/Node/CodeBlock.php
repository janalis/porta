<?php

declare(strict_types=1);

namespace Porta\Markdown\Node;

use Porta\Markdown\SourceRange;

/**
 * A fenced or indented code block. $info is the fence's info string, empty for an indented block; $body is where the code lines sit in the source.
 */
final readonly class CodeBlock implements Block
{
    public function __construct(
        public string $literal,
        public string $info,
        public bool $fenced,
        public SourceRange $range,
        public SourceRange $body,
    ) {}

    #[\Override]
    public function children(): array
    {
        return [];
    }
}
