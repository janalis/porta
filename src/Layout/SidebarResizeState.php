<?php

declare(strict_types=1);

namespace Porta\Layout;

/**
 * One sidebar's complete resize state: which pane, whether a drag is armed,
 * the width the drag started from (to skip no-op persists on release) and the
 * persisted proportion. {@see LayoutController} holds one per sidebar
 * (tree/git/settings), which is what lets its arm/drag/nudge/restore paths be
 * written once instead of three field-triples apart. Mutable by design — the
 * drag lifecycle writes it across mouse events. The clamp bounds are not
 * here — they are the pane's own ({@see ResizableSidebar}).
 */
final class SidebarResizeState
{
    public ResizeState $resizing = ResizeState::Idle;
    /** The width at drag start, to skip no-op persists. */
    public int $startWidth = 0;
    /** The persisted proportion of the terminal width (null = widget default). */
    public ?int $widthPercent = null;

    public function __construct(
        public readonly ResizableSidebar $sidebar,
    ) {}
}
