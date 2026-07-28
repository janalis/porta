<?php

declare(strict_types=1);

namespace Porta\Workbench;

/**
 * F6/Ctrl+F6 focus cycling: an open full view (git, settings) owns its own
 * ring; otherwise the editor layout's focus ring cycles.
 */
final class FocusCoordinator
{
    public function __construct(
        private readonly FocusRing $ring,
        private readonly WorkspaceViews $views,
    ) {}

    public function cycle(int $direction): void
    {
        if (!$this->views->cycleFocus($direction)) {
            $this->ring->cycle($direction);
        }
    }
}
