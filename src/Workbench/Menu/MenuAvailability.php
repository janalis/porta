<?php

declare(strict_types=1);

namespace Porta\Workbench\Menu;

/**
 * Whether a menu row is runnable, so it renders normally or dimmed and never
 * highlighted. Replaces the former `bool $enabled` flag on {@see MenuPanel}'s
 * row renderer.
 */
enum MenuAvailability
{
    case Enabled;
    case Disabled;
}
