<?php

declare(strict_types=1);

namespace Porta\Markdown\Block;

use Porta\Markdown\Inline\LinkReference;

/** What the block phase hands the inline phase: the block tree and the link reference definitions found in it. */
final readonly class ParsedBlocks
{
    /**
     * @param array<string, LinkReference> $references keyed by normalised label
     */
    public function __construct(
        public OpenBlock $document,
        public array $references,
    ) {}
}
