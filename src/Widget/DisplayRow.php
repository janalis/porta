<?php

declare(strict_types=1);

namespace Porta\Widget;

/**
 * One rendered/navigated row of the results panel: either a **file header**
 * (grouped mode — `filePath` set, with a match `count` and `collapsed` flag) or
 * a **match** row (`matchIndex` into the panel's result list). A file header has
 * `matchIndex = -1`; a match row has `filePath = null`, so a `null !== filePath`
 * check both classifies the row and narrows the path for the type checker.
 */
final class DisplayRow
{
    private function __construct(
        public readonly ?string $filePath,
        public readonly int $count,
        public readonly bool $collapsed,
        public readonly int $matchIndex,
    ) {}

    public static function fileHeader(string $path, int $count, bool $collapsed): self
    {
        return new self($path, $count, $collapsed, -1);
    }

    public static function match(int $index): self
    {
        return new self(null, 0, false, $index);
    }
}
