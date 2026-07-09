<?php

declare(strict_types=1);

namespace Porta\Widget;

/**
 * How grouped find-in-project results are bucketed under collapsible headers:
 * by file (`path (count)`) or by the containing directory (`dir/ (count)`),
 * toggled with Alt+G. Only meaningful in grouped mode.
 */
enum ResultGrouping
{
    case File;
    case Directory;

    public function next(): self
    {
        return self::File === $this ? self::Directory : self::File;
    }

    /** The header key a result is grouped under. */
    public function keyFor(string $path): string
    {
        return self::Directory === $this ? \dirname($path) : $path;
    }
}
