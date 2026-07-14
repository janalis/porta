<?php

declare(strict_types=1);

namespace Porta\Widget;

/**
 * The editor's tab-management hooks. Implemented by
 * {@see \Porta\Editor\BufferNavigator}; tests inject fakes.
 */
interface BufferNavigationHandler
{
    /** Cycle to the next (+1) / previous (-1) buffer. */
    public function cycleBuffer(int $offset): void;

    /** Open the recent-files switcher. */
    public function openRecentFiles(): void;

    /** Close the active buffer. */
    public function closeBuffer(): void;
}
