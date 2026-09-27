<?php

declare(strict_types=1);

namespace Porta\Overlay\Region;

/** Which side of the element it explains a tooltip card sits on. */
enum TooltipSide
{
    case Below;
    case Above;
    case Right;
    case Left;
}
