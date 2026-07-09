<?php

declare(strict_types=1);

namespace Porta\Widget;

/**
 * One rendered/navigated row of the results panel: a **file header** (grouped
 * mode — `filePath` set, with a match `count` and `collapsed` flag), a **match**
 * row (`matchIndex` into the panel's result list), or an inert **context** row
 * (`contextText` set — a neighbouring source line shown around an expanded
 * match). A file header has `matchIndex = -1`; a match row has `filePath = null`
 * and `contextText = null`; a context row has both null but `contextText` set,
 * so `null !== filePath` then `null !== contextText` classifies the three.
 */
final class DisplayRow
{
    private function __construct(
        public readonly ?string $filePath,
        public readonly int $count,
        public readonly bool $collapsed,
        public readonly int $matchIndex,
        public readonly int $contextLine = -1,
        public readonly ?string $contextText = null,
    ) {}

    public static function fileHeader(string $path, int $count, bool $collapsed): self
    {
        return new self($path, $count, $collapsed, -1);
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
