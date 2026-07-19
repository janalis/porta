<?php

declare(strict_types=1);

namespace Porta\Widget\Overlay;

/**
 * How a row in an always-focused modal list is lit: the selected row carries
 * the selection background, the row under the pointer the hover tint, everything
 * else the plain popup shade. The Selected/Hovered/Unselected split mirrors
 * {@see \Porta\Widget\OptionTagState}'s — and, as everywhere else, hover is a
 * pure visual that never moves the selection (see {@see \Porta\Widget\HoverTrait}).
 *
 * {@see \Porta\Widget\Render\ModalRowStyle} resolves the state to its role; the
 * find-in-files results, the go-to-file picker and the editor popups share it.
 * {@see of()} folds the two facts once (selection wins over hover), mirroring
 * {@see \Porta\Widget\Render\TabState::of()}.
 */
enum RowSelection
{
    case Selected;
    case Hovered;
    case Unselected;

    public static function of(bool $selected, bool $hovered): self
    {
        return match (true) {
            $selected => self::Selected,
            $hovered => self::Hovered,
            default => self::Unselected,
        };
    }
}
