<?php

declare(strict_types=1);

namespace Porta\Workbench\Menu;

/**
 * Whether a menu row is the selected one, so it renders with the selection
 * highlight. Replaces the former `bool $selected` flag on {@see MenuPanel}'s
 * row renderer.
 */
enum MenuSelection
{
    case Selected;
    case Unselected;
}
