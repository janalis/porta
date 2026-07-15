<?php

declare(strict_types=1);

namespace Porta\Container;

/** Whether the IDE renders with ANSI color/styling or in monochrome. */
enum Ansi
{
    case Enabled;
    case Disabled;
}
