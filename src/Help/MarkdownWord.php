<?php

declare(strict_types=1);

namespace Porta\Help;

/**
 * A wrap-unit of a {@see MarkdownSegment}: one word (or hard-broken slice of
 * an over-wide one) with its display width, keeping the segment's styling so
 * consecutive same-style words re-join into one styled run per line.
 */
final class MarkdownWord
{
    public function __construct(
        public readonly string $text,
        public readonly int $width,
        public readonly MarkdownSpanKind $kind,
        public readonly ?string $target,
        public readonly int $group,
    ) {}
}
