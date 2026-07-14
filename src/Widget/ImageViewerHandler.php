<?php

declare(strict_types=1);

namespace Porta\Widget;

/**
 * The image viewer's hooks into the IDE: the tab actions re-bound while the
 * code editor is detached, plus a view-change ping so the status bar's zoom
 * label stays live. Implemented by {@see \Porta\Image\ImageViewerActions};
 * tests inject recording fakes.
 */
interface ImageViewerHandler
{
    /** Switch tabs (+1 / -1). */
    public function cycleBuffer(int $offset): void;

    public function closeBuffer(): void;

    public function openGotoFile(): void;

    public function saveAll(): void;

    /** Zoom/pan changed — refresh the status bar. */
    public function viewChanged(): void;
}
