<?php

declare(strict_types=1);

namespace Porta\Widget;

/**
 * A viewer tab's hooks into the IDE: the tab actions re-bound while the code
 * editor is detached, plus a view-change ping so the status bar stays live.
 * Shared by the image and graph viewers (five identical methods — one seam,
 * not one per viewer); implemented by {@see \Porta\Editor\BufferNavigator},
 * tests inject recording fakes.
 */
interface ViewerTabHandler
{
    /** Switch tabs (+1 / -1). */
    public function cycleBuffer(int $offset): void;

    public function closeBuffer(): void;

    public function openGotoFile(): void;

    public function saveAll(): void;

    /** Zoom/pan changed — refresh the status bar. */
    public function viewChanged(): void;
}
