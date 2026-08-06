<?php

declare(strict_types=1);

namespace Porta\Workbench;

use Porta\Workbench\View\ViewRegistry;

/**
 * F6/Ctrl+F6 focus cycling: an open view owns its own ring; otherwise the
 * editor layout's focus ring cycles.
 */
final class FocusCoordinator
{
    public function __construct(
        private readonly FocusRing $ring,
        private readonly ViewRegistry $views,
    ) {}

    public function cycle(int $direction): void
    {
        if (!$this->views->cycleFocus($direction)) {
            $this->ring->cycle($direction);
        }
    }
}
