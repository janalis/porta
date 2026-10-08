<?php

declare(strict_types=1);

namespace Porta\Markdown\Node;

use Porta\Markdown\SourceRange;

/**
 * A GitHub emoji shortcode (`:rocket:`) and the glyph it stands for.
 */
final readonly class Emoji implements Inline
{
    public function __construct(
        public string $shortcode,
        public string $glyph,
        public SourceRange $range,
    ) {}

    #[\Override]
    public function children(): array
    {
        return [];
    }
}
