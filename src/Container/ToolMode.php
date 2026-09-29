<?php

declare(strict_types=1);

namespace Porta\Container;

/** Which git tool a launch stands in for ({@see ToolLaunch}). */
enum ToolMode
{
    case Diff;
    case Merge;
}
