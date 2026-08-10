<?php

declare(strict_types=1);

namespace Porta\Config;

/**
 * The persisted pane proportions and git-diff display toggles, as one value the
 * {@see LayoutStore} reads and writes — replacing six positional nullables on
 * `save()` and the string-keyed array `load()` returned. A `null` field means
 * "not set" (absent from the JSON file / left at its default).
 */
final readonly class LayoutPrefs
{
    public function __construct(
        public ?int $sidebarWidthPercent = null,
        public ?int $bottomPanelHeightPercent = null,
        public ?int $gitSidebarWidthPercent = null,
        public ?int $settingsSidebarWidthPercent = null,
    ) {}
}
