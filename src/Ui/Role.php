<?php

declare(strict_types=1);

namespace Porta\Ui;

/**
 * A semantic color role in the IDE chrome. Widgets ask the {@see Palette} for a
 * role's escape sequence rather than hardcoding a raw SGR code, so the whole
 * theme (dark/light) and the monochrome (`--no-ansi`) mode can be swapped in one
 * place. Roles carry no code themselves — each {@see Palette} maps them.
 */
enum Role
{
    // Diff view.
    case Gutter;
    case Added;
    case Deleted;
    case Hunk;

    // Shared row highlights (menus, popups, lists, the current editor line).
    case SelectedRow;
    case RowHighlightBg;
    case PopupRow;
}
