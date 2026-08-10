<?php

declare(strict_types=1);

namespace Porta\Config;

/**
 * The persisted pane proportions and git-diff display toggles, as one value the
 * {@see LayoutStore} reads and writes — replacing six positional nullables on
 * `save()` and the string-keyed array `load()` returned. A `null` field means
 * "not set" (absent from the JSON file / left at its default).
 *
 * The per-view sidebar widths are a MAP keyed by view id, not two named fields.
 * They were `gitSidebarWidthPercent` and `settingsSidebarWidthPercent` while
 * core knew both views; a contributed view has an id core learns at runtime, so
 * a field per view cannot describe one. {@see LayoutStore} still reads the two
 * old keys, once, for a file written before the move.
 */
final readonly class LayoutPrefs
{
    /** @param array<string, int> $viewSidebarWidthPercent view id => width, for whichever views have been resized */
    public function __construct(
        public ?int $sidebarWidthPercent = null,
        public ?int $bottomPanelHeightPercent = null,
        public array $viewSidebarWidthPercent = [],
    ) {}
}
