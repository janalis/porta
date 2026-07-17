<?php

declare(strict_types=1);

namespace Porta\Layout;

/**
 * The per-sidebar state a resize drag needs: which pane to resize and the
 * width the drag started from (to skip no-op persists on release). Bundled so
 * the shared {@see LayoutController::continueSidebarResize} takes one spec
 * instead of the tree/git/settings variants spelled out three times. The clamp
 * bounds are not here — they are the pane's own ({@see ResizableSidebar}).
 */
final readonly class SidebarResize
{
    public function __construct(
        public ResizableSidebar $sidebar,
        public int $startWidth,
    ) {}
}
