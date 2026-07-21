<?php

declare(strict_types=1);

namespace Porta\Plugin;

/**
 * The immediate result of asking {@see ToolInstaller} to install a tool: the
 * install started (its success/failure arrives later via the callback), one is
 * already running, or none of the declared package managers is on the PATH.
 */
enum InstallOutcome
{
    case Started;
    case Busy;
    case NoManager;
}
