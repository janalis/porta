<?php

declare(strict_types=1);

namespace Porta\Editor;

/**
 * One parsed snippet tab stop: its 1-based index (0 is the final `$0` caret) and
 * the byte offsets [start, end) of its placeholder within the inserted text.
 * Produced by {@see SnippetParser} and turned into absolute document positions
 * by {@see SnippetSession}. Replaces the former array{index, start, end} shape.
 */
final readonly class SnippetTabStop
{
    public function __construct(
        public int $index,
        public int $start,
        public int $end,
    ) {}
}
