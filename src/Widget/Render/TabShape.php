<?php

declare(strict_types=1);

namespace Porta\Widget\Render;

use Porta\Ui\Glyphs;
use Porta\Ui\Role;

/**
 * A folder tab's outline, which is its state — read with no colour at all:
 *
 *   Rest     ╭───╮ │ ┴───┴   every other tab
 *   Active   ┍━━━┑ │ ┘   └   the pane's shown tab, while the pane has no focus
 *   Focused  ┏━━━┓ ┃ ┚   ┖   the shown tab of the pane taking the keys
 *
 * The last column is the foot, where the tab meets the strip's baseline: the
 * line runs under every tab but the shown one.
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

    /**
     * Where a tab $width wide meets the strip's baseline, rails included: at
     * rest its rails land on the line, and under the shown tab the line opens
     * — the tab and the pane it heads are one surface. Under the tab at a
     * top edge, over it at a bottom one.
     */
    public function foot(int $width, StripEdge $edge): string
    {
        $top = StripEdge::Top === $edge;
        if (self::Rest === $this) {
            $join = $top ? Glyphs::RULE_UP : Glyphs::RULE_DOWN;

            return $join.\str_repeat(Glyphs::FRAME_HORIZONTAL, \max(0, $width - 2)).$join;
        }
        [$left, $right] = match (true) {
            self::Focused === $this => $top ? [Glyphs::TAB_FOOT_UP_LEFT, Glyphs::TAB_FOOT_UP_RIGHT] : [Glyphs::TAB_FOOT_DOWN_LEFT, Glyphs::TAB_FOOT_DOWN_RIGHT],
            $top => [Glyphs::FRAME_BOTTOM_RIGHT, Glyphs::FRAME_BOTTOM_LEFT],
            default => [Glyphs::FRAME_TOP_RIGHT, Glyphs::FRAME_TOP_LEFT],
        };

        return $left.\str_repeat(' ', \max(0, $width - 2)).$right;
    }

    /**
     * A side tab's outline, $width wide, for a strip whose inner edge — the
     * one facing the editor — is a rule down the last column ($ruleAtEnd) or
     * the first. The top and bottom rows run from the outer rail into the
     * rule: a resting tab tees into it, and the shown tab turns it, so the
     * rule opens beside that tab alone — {@see foot()} stood on its side.
     *
     * @return array{top: string, bottom: string, outer: string, inner: string} the two edge rows, and the middle row's outer rail and inner column
     */
    // @mago-expect lint:no-boolean-flag-parameter -- which edge faces the editor IS the outline's shape, not a second responsibility.
    public function side(int $width, bool $ruleAtEnd): array
    {
        [$top, $bottom] = match ($this) {
            self::Rest => $ruleAtEnd
                ? [
                    [Glyphs::MODAL_TOP_LEFT,    Glyphs::MODAL_TEE_RIGHT],
                    [Glyphs::MODAL_BOTTOM_LEFT, Glyphs::MODAL_TEE_RIGHT],
                ]
                : [
                    [Glyphs::MODAL_TEE_LEFT, Glyphs::MODAL_TOP_RIGHT],
                    [Glyphs::MODAL_TEE_LEFT, Glyphs::MODAL_BOTTOM_RIGHT],
                ],
            self::Active => $ruleAtEnd
                ? [
                    [Glyphs::TAB_LID_TOP_LEFT,    Glyphs::TAB_LID_BOTTOM_RIGHT],
                    [Glyphs::TAB_LID_BOTTOM_LEFT, Glyphs::TAB_LID_TOP_RIGHT],
                ]
                : [
                    [Glyphs::TAB_LID_BOTTOM_LEFT, Glyphs::TAB_LID_TOP_RIGHT],
                    [Glyphs::TAB_LID_TOP_LEFT,    Glyphs::TAB_LID_BOTTOM_RIGHT],
                ],
            self::Focused => $ruleAtEnd
                ? [
                    [Glyphs::FIELD_FOCUS_TOP_LEFT,    Glyphs::TAB_LID_BOTTOM_RIGHT],
                    [Glyphs::FIELD_FOCUS_BOTTOM_LEFT, Glyphs::TAB_LID_TOP_RIGHT],
                ]
                : [
                    [Glyphs::TAB_LID_BOTTOM_LEFT, Glyphs::FIELD_FOCUS_TOP_RIGHT],
                    [Glyphs::TAB_LID_TOP_LEFT,    Glyphs::FIELD_FOCUS_BOTTOM_RIGHT],
                ],
        };
        $fill = \str_repeat(self::Rest === $this ? Glyphs::FRAME_HORIZONTAL : Glyphs::FIELD_FOCUS_HORIZONTAL, \max(0, $width - 2));

        return [
            'top' => $top[0].$fill.$top[1],
            'bottom' => $bottom[0].$fill.$bottom[1],
            'outer' => $this->rail(),
            'inner' => self::Rest === $this ? Glyphs::FRAME_VERTICAL : ' ',
        ];
    }

    /** The outline's tint — secondary to the shape. */
    public function role(): Role
    {
        return self::Rest === $this ? Role::Rule : Role::TabAccent;
    }
}
