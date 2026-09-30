<?php

declare(strict_types=1);

namespace Porta\Help;

/**
 * A wrap-unit of a {@see MarkdownSegment}: one word (or hard-broken slice of
 * an over-wide one) with its display width, keeping the segment's styling so
 * consecutive same-style words re-join into one styled run per line.
 */
final readonly class MarkdownWord
{
    public function __construct(
        public string $text,
        public int $width,
        public MarkdownSpanKind $kind,
        public ?string $target,
        public int $group,
        /** No space before it in the source: `[a link].` — the `.` touches the link. */
        public bool $glued = false,
    ) {}
}
