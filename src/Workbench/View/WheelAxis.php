<?php

declare(strict_types=1);

namespace Porta\Workbench\View;

/** Which way a wheel notch points; a horizontal notch is a shift-wheel or a trackpad swipe. */
enum WheelAxis
{
    case Vertical;
    case Horizontal;
}
