<?php

declare(strict_types=1);

namespace Porta\Widget\Render;

/**
 * Which edge of its pane a tab strip sits on: a folder tab opens toward the
 * pane it heads, so a strip under its pane draws its labels first and its
 * lids below them.
 */
enum StripEdge
{
    case Top;
    case Bottom;
}
