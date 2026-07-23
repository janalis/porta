<?php

declare(strict_types=1);

namespace Porta\Overlay\Region;

use Porta\Overlay\State\MenuOverlayController;

/**
 * The topmost floating dropdown regions of the current frame (app menu and
 * context menu), for consumers that must treat them as their own panes — the
 * RTL segmenter reorders each dropdown within its own box. Implemented by
 * {@see MenuOverlayController}; tests inject empty fakes.
 */
interface FloatingRegions
{
    /** @return list<DropdownRegion> */
    public function regions(): array;
}
