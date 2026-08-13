<?php

declare(strict_types=1);

namespace Porta\Widget\Support;

/**
 * One rendered/navigated row of the results panel: a **header** (grouped mode —
 * `filePath` set, either a file or, in the nested view, a directory when
 * `isDirectory` is true, with a match `count` and `collapsed` flag), a **match**
 * row (`matchIndex` into the panel's result list), or an inert **context** row
 * (`contextText` set — a neighbouring source line shown around an expanded
 * match). A header has `matchIndex = -1`; a match row has `filePath = null` and
 * `contextText = null`; a context row has both null but `contextText` set, so
 * `null !== filePath` then `null !== contextText` classifies the three.
 */
final readonly class DisplayRow
{
    private function __construct(
        public ?string $filePath,
        public int $count,
        public bool $collapsed,
        public int $matchIndex,
        public int $contextLine = -1,
        public ?string $contextText = null,
        public bool $isDirectory = false,
    ) {}

    public static function fileHeader(string $path, int $count, HeaderState $state): self
    {
        return new self($path, $count, HeaderState::Collapsed === $state, -1);
    }

    /** A directory header (nested grouping only), keyed by its directory path. */
    public static function dirHeader(string $path, int $count, HeaderState $state): self
    {
        return new self($path, $count, HeaderState::Collapsed === $state, -1, -1, null, true);
    }

    public static function match(int $index): self
    {
        return new self(null, 0, false, $index);
    }

    public static function context(int $line, string $text): self
    {
        return new self(null, 0, false, -1, $line, $text);
    }
}
