<?php

declare(strict_types=1);

namespace Porta\Widget;

/**
 * A viewer tab's hooks into the IDE: the tab actions re-bound while the code
 * editor is detached, a view-change ping so the status bar stays live, and —
 * for a pane previewing a text — the way back from what it drew to the text.
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

    /**
     * A preview was clicked on something its text wrote: put the caret there,
     * 0-based line and BYTE column. Ignored unless the active tab is a text
     * with a preview — a viewer tab has no text to go to.
     */
    public function revealSource(int $line, int $byteCol): void;
}
