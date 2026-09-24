<?php

declare(strict_types=1);

namespace Porta\Widget\Render;

use Porta\Ui\Glyphs;
use Porta\Ui\Role;

/**
 * A folder tab's outline, which is its state — read with no colour at all:
 *
 *   Rest     ╭───╮ │   every other tab
 *   Active   ┍━━━┑ │   the pane's shown tab, while the pane has no focus
 *   Focused  ┏━━━┓ ┃   the shown tab of the pane taking the keys
 *
 * The heavy lid alone says "shown here"; heavy rails as well say "and typing
 * lands here" — the form controls' Focused shape. The tint repeats it.
 */
enum TabShape
{
    case Rest;
    case Active;
    case Focused;

    public static function of(bool $active, bool $paneFocused): self
    {
        return match (true) {
            !$active => self::Rest,
            $paneFocused => self::Focused,
            default => self::Active,
        };
    }

    public function rail(): string
    {
        return self::Focused === $this ? Glyphs::FIELD_FOCUS_VERTICAL : Glyphs::FRAME_VERTICAL;
    }

    /** The lid across a tab $width wide, rails included: its top at a top edge, its bottom at a bottom one. */
    public function lid(int $width, StripEdge $edge): string
    {
        $top = StripEdge::Top === $edge;
        [$left, $fill, $right] = match ($this) {
            self::Rest => $top ? [Glyphs::MODAL_TOP_LEFT, Glyphs::FRAME_HORIZONTAL, Glyphs::MODAL_TOP_RIGHT] : [Glyphs::MODAL_BOTTOM_LEFT, Glyphs::FRAME_HORIZONTAL, Glyphs::MODAL_BOTTOM_RIGHT],
            self::Active => $top
                ? [Glyphs::TAB_LID_TOP_LEFT, Glyphs::FIELD_FOCUS_HORIZONTAL, Glyphs::TAB_LID_TOP_RIGHT]
                : [Glyphs::TAB_LID_BOTTOM_LEFT, Glyphs::FIELD_FOCUS_HORIZONTAL, Glyphs::TAB_LID_BOTTOM_RIGHT],
            self::Focused => $top
                ? [Glyphs::FIELD_FOCUS_TOP_LEFT, Glyphs::FIELD_FOCUS_HORIZONTAL, Glyphs::FIELD_FOCUS_TOP_RIGHT]
                : [Glyphs::FIELD_FOCUS_BOTTOM_LEFT, Glyphs::FIELD_FOCUS_HORIZONTAL, Glyphs::FIELD_FOCUS_BOTTOM_RIGHT],
        };

        return $left.\str_repeat($fill, \max(0, $width - 2)).$right;
    }

    /** The outline's tint — secondary to the shape. */
    public function role(): Role
    {
        return self::Rest === $this ? Role::Rule : Role::TabAccent;
    }
}
