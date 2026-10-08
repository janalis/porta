<?php

declare(strict_types=1);

namespace Porta\Markdown\Inline;

/** A `[` or `![` on the bracket stack, waiting for the `]` that may make it a link or image. */
final class Bracket
{
    public bool $active = true;
    public bool $bracketAfter = false;

    public function __construct(
        public readonly InlineItem $item,
        public readonly bool $image,
        /** Where its `[` sits in the text. */
        public readonly int $index,
        public readonly ?Bracket $previous,
        public readonly ?Delimiter $previousDelimiter,
    ) {}
}
