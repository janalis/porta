<?php

declare(strict_types=1);

namespace Porta\Layout;

/**
 * The per-sidebar constants a resize drag needs: which pane to resize, the
 * clamp bounds ({@see LayoutController::clampSidebarWidth}) and the width the
 * drag started from (to skip no-op persists on release). Bundled so the shared
 * {@see LayoutController::continueSidebarResize} takes one spec instead of the
 * tree/git/settings variants spelled out three times.
 */
final readonly class SidebarResize
{
    public function __construct(
        public ResizableSidebar $sidebar,
        public int $min,
        public float $ratio,
        public int $startWidth,
    ) {}
}
