<?php

declare(strict_types=1);

namespace Porta\Widget\Git;

use Porta\Git\DiffLine;

/**
 * Which side of a side-by-side diff row a half renders: the old (pre-image)
 * or the new (post-image) column. Owns the line-number pick so the renderer
 * asks the side rather than branching on a bool.
 */
enum DiffSide
{
    case Old;
    case New;

    /** The line number this side shows for $line (null when the side has no line here). */
    public function lineNo(DiffLine $line): ?int
    {
        return match ($this) {
            self::Old => $line->oldNo,
            self::New => $line->newNo,
        };
    }
}
