<?php

declare(strict_types=1);

namespace Porta\Widget;

/**
 * How grouped find-in-project results are bucketed under collapsible headers,
 * cycled with Alt+G: by file (`path (count)`), by the containing directory
 * (`dir (count)`), or nested (`dir/ → file → matches`). Only meaningful in
 * grouped mode.
 */
enum ResultGrouping
{
    case File;
    case Directory;
    case Nested;

    public function next(): self
    {
        return match ($this) {
            self::File => self::Directory,
            self::Directory => self::Nested,
            self::Nested => self::File,
        };
    }

    /** The leaf header key a result is grouped under (directory for Directory, file path otherwise). */
    public function keyFor(string $path): string
    {
        return self::Directory === $this ? \dirname($path) : $path;
    }
}
