<?php

declare(strict_types=1);

namespace Porta\Widget\Overlay;

/**
 * How a find-in-files result row is lit: the selected row carries the selection
 * background, the row under the pointer the hover tint, everything else the
 * plain popup shade. The Selected/Hovered/Unselected split mirrors
 * {@see OptionTagState}'s — and, as everywhere else, hover is a pure visual
 * that never moves the selection (see {@see \Porta\Widget\HoverTrait}).
 */
enum RowSelection
{
    case Selected;
    case Hovered;
    case Unselected;
}
