<?php

declare(strict_types=1);

namespace Porta\Layout;

/**
 * Whether a sidebar's mouse-resize drag is currently armed. Replaces the former
 * `bool` resize flags threaded (by reference) through
 * {@see LayoutController::continueSidebarResize()}.
 */
enum ResizeState
{
    case Active;
    case Idle;
}
