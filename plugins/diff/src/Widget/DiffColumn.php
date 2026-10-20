<?php

declare(strict_types=1);

namespace Porta\DiffPlugin\Widget;

use Porta\DiffPlugin\DiffLine;

/**
 * Which column of a side-by-side diff row a half renders: the old (pre-image)
 * or the new (post-image) one. Not named DiffSide: the git plugin has one, the
 * staging axis, and the two read alike. Owns the line-number pick so the
 * renderer asks the column rather than branching on a bool.
 */
enum DiffColumn
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
