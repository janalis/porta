<?php

declare(strict_types=1);

namespace Porta\Widget\Render;

use Porta\Ui\Role;
use Porta\Widget\Overlay\RowSelection;

/**
 * The shading of a row in an always-focused modal list — the go-to-file picker,
 * the editor completion/nav popups, the find-in-files results: the selection
 * wins, then the hover tint, then the plain popup shade.
 *
 * The same {@see Role::SelectedRow}/{@see Role::RowHighlightBg}/{@see Role::PopupRow}
 * triple was hand-written at each of those sites; resolving it once here is the
 * modal-row counterpart of {@see TabStyle} for the strip family. Each state is a
 * single role (unlike a tab's active merge+bold), so this returns the {@see Role}
 * and the caller applies it — some sites want the Style, some its ANSI restore.
 */
final class ModalRowStyle
{
    public static function role(RowSelection $state): Role
    {
        return match ($state) {
            RowSelection::Selected => Role::SelectedRow,
            RowSelection::Hovered => Role::RowHighlightBg,
            RowSelection::Unselected => Role::PopupRow,
        };
    }
}
