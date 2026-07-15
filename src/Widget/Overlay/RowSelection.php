<?php

declare(strict_types=1);

namespace Porta\Widget\Overlay;

/**
 * Whether a find-in-files result row is the selected one (so it renders with the
 * selection background). Replaces the former `bool $selected` flag on
 * {@see FindInFilesModal}'s row renderers.
 */
enum RowSelection
{
    case Selected;
    case Unselected;
}
