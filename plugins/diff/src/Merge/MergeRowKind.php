<?php

declare(strict_types=1);

namespace Porta\DiffPlugin\Merge;

/** What one row of a merge side stands for, beside the result line it is level with. */
enum MergeRowKind
{
    /** A line outside every conflict: the same text on all three columns. */
    case Context;

    /** Level with a conflict's opener: the side's label, and where a click accepts it. */
    case Header;

    /** One of the side's own lines inside a conflict. */
    case Side;

    /** A conflict's remaining height once the side's lines are drawn. */
    case Filler;
}
