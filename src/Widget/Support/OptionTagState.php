<?php

declare(strict_types=1);

namespace Porta\Widget\Support;

/**
 * Visual state of an option toggle drawn as a tag: lit when the option is on
 * (and underlined too while the pointer is over it), otherwise highlighted
 * while the pointer is over it, otherwise faint.
 *
 * The IDE has three of these surfaces — the find-in-files modal's option tags,
 * the find bar's, and the git diff header's display toggles — so the states
 * are named once here. The shading stays per-widget: they sit on different
 * backgrounds and an on-tag has no room for a second one, which is why a
 * hovered on-tag underlines rather than re-tinting (see
 * the git plugin's commit box is the same precedent).
 *
 * {@see of()} resolves the states once rather than at each tag; mirrors
 * {@see \Porta\Widget\Render\TabState}. A hovered ON tag is its own state:
 * when "on" simply beat "hovered", the pointer over a lit tag changed nothing,
 * so the one tag you were about to switch off never said it would react.
 */
enum OptionTagState
{
    case On;
    case OnHovered;
    case Hovered;
    case Off;

    public static function of(bool $on, bool $hovered): self
    {
        return match (true) {
            $on && $hovered => self::OnHovered,
            $on => self::On,
            $hovered => self::Hovered,
            default => self::Off,
        };
    }
}
