<?php

declare(strict_types=1);

namespace Porta\Widget\Support;

/**
 * Visual state of an option toggle drawn as a tag: lit when the option is on,
 * otherwise highlighted while the pointer is over it, otherwise faint.
 *
 * The IDE has three of these surfaces — the find-in-files modal's option tags,
 * the find bar's, and the git diff header's display toggles — so the states
 * are named once here. The shading stays per-widget: they sit on different
 * backgrounds and an on-tag has no room for a second one, which is why a
 * hovered on-tag underlines rather than re-tinting (see
 * the git plugin's commit box is the same precedent).
 *
 * {@see of()} resolves the precedence once — on beats hovered — rather than at
 * each tag; mirrors {@see \Porta\Widget\Render\TabState}.
 */
enum OptionTagState
{
    case On;
    case Hovered;
    case Off;

    public static function of(bool $on, bool $hovered): self
    {
        return match (true) {
            $on => self::On,
            $hovered => self::Hovered,
            default => self::Off,
        };
    }
}
