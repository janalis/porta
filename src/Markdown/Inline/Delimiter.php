<?php

declare(strict_types=1);

namespace Porta\Markdown\Inline;

/** A run of `*`, `_` or `~` on the delimiter stack, waiting to open or close emphasis. */
final class Delimiter
{
    public ?Delimiter $previous = null;
    public ?Delimiter $next = null;
    public readonly int $original;

    public function __construct(
        public readonly InlineItem $item,
        public readonly string $char,
        public int $count,
        public readonly bool $canOpen,
        public readonly bool $canClose,
    ) {
        $this->original = $count;
    }
}
