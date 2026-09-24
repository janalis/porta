<?php

declare(strict_types=1);

namespace Porta\Widget\Render;

/**
 * Which side of the caret's row a popup took. The popups anchored at the caret
 * share the two sides, so the one drawn last is told which is taken and goes
 * to the other — never over the one already there.
 */
enum CaretSide
{
    case Above;
    case Below;

    /** The side a popup starting at $top took, against the caret's $row. */
    public static function of(int $top, int $row): self
    {
        return $top > $row ? self::Below : self::Above;
    }
}
