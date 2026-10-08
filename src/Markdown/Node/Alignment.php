<?php

declare(strict_types=1);

namespace Porta\Markdown\Node;

/** A table column's alignment, from its delimiter cell (`:--`, `:-:`, `--:`). */
enum Alignment
{
    case None;
    case Left;
    case Center;
    case Right;
}
