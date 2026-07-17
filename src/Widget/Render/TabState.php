<?php

declare(strict_types=1);

namespace Porta\Widget\Render;

/**
 * How a tab of the strip family is lit: the active tab, the one under the
 * pointer, or the rest. Mirrors {@see \Porta\Widget\Overlay\OptionTagState}'s
 * On/Hovered/Off split.
 *
 * The three states are what the strips actually have — a tab is not
 * independently active and hovered — so {@see of()} resolves the two facts
 * once, here, rather than in each strip's render. Active wins: the pointer
 * resting on the active tab leaves it looking active.
 */
enum TabState
{
    case Active;
    case Hovered;
    case Plain;

    public static function of(bool $active, bool $hovered): self
    {
        return match (true) {
            $active => self::Active,
            $hovered => self::Hovered,
            default => self::Plain,
        };
    }
}
