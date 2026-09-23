<?php

declare(strict_types=1);

namespace Porta\Widget\Render;

use Porta\Ui\Glyphs;
use Porta\Ui\Role;

/**
 * What a text field is doing, told by the SHAPE of its border ({@see FieldBox})
 * so it reads with no colour at all — the monochrome theme and --no-ansi keep
 * the glyphs and drop the tint:
 *
 *   Idle      ╭─╮ │ ╰─╯   rounded, the modal family
 *   Focused   ┏━┓ ┃ ┗━┛   heavy: this field takes the keys
 *   Danger    ╔═╗ ║ ╚═╝   double: it takes the keys and the answer destroys
 *   Disabled  ╭┄╮ ┆ ╰┄╯   dashed: it is there, and it will not act
 *
 * Focused and Danger are the states that may carry the caret; an Idle field
 * never does, which is what keeps "one focused field" and "one caret marker
 * per frame" the same rule.
 */
enum FieldState
{
    case Idle;
    case Focused;
    case Danger;
    case Disabled;

    /** The state of a field that takes keys exactly when $focused. */
    // @mago-expect lint:no-boolean-flag-parameter -- the bool IS the domain: every caller already holds "does this field take the keys".
    public static function of(bool $focused): self
    {
        return $focused ? self::Focused : self::Idle;
    }

    /** @return array{string, string, string, string, string, string} top-left, top-right, bottom-left, bottom-right, horizontal, vertical */
    public function glyphs(): array
    {
        return match ($this) {
            self::Idle => [Glyphs::MODAL_TOP_LEFT, Glyphs::MODAL_TOP_RIGHT, Glyphs::MODAL_BOTTOM_LEFT, Glyphs::MODAL_BOTTOM_RIGHT, Glyphs::FRAME_HORIZONTAL, Glyphs::FRAME_VERTICAL],
            self::Focused => [
                Glyphs::FIELD_FOCUS_TOP_LEFT,
                Glyphs::FIELD_FOCUS_TOP_RIGHT,
                Glyphs::FIELD_FOCUS_BOTTOM_LEFT,
                Glyphs::FIELD_FOCUS_BOTTOM_RIGHT,
                Glyphs::FIELD_FOCUS_HORIZONTAL,
                Glyphs::FIELD_FOCUS_VERTICAL,
            ],
            self::Danger => [
                Glyphs::FIELD_DANGER_TOP_LEFT,
                Glyphs::FIELD_DANGER_TOP_RIGHT,
                Glyphs::FIELD_DANGER_BOTTOM_LEFT,
                Glyphs::FIELD_DANGER_BOTTOM_RIGHT,
                Glyphs::FIELD_DANGER_HORIZONTAL,
                Glyphs::FIELD_DANGER_VERTICAL,
            ],
            self::Disabled => [
                Glyphs::MODAL_TOP_LEFT,
                Glyphs::MODAL_TOP_RIGHT,
                Glyphs::MODAL_BOTTOM_LEFT,
                Glyphs::MODAL_BOTTOM_RIGHT,
                Glyphs::FIELD_DISABLED_HORIZONTAL,
                Glyphs::FIELD_DISABLED_VERTICAL,
            ],
        };
    }

    /** @return array{string, string} the left and right tees joining a separator to this state's frame */
    public function tees(): array
    {
        return match ($this) {
            self::Focused => [Glyphs::FIELD_FOCUS_TEE_LEFT, Glyphs::FIELD_FOCUS_TEE_RIGHT],
            default => [Glyphs::MODAL_TEE_LEFT, Glyphs::MODAL_TEE_RIGHT],
        };
    }

    /**
     * A checkbox's two rails: the brackets at rest, and the border's own
     * vertical otherwise — heavy `┃✓┃` where it takes the keys, dashed `┆✓┆`
     * where it will not act.
     *
     * @return array{string, string}
     */
    public function checkboxRails(): array
    {
        if (self::Idle === $this) {
            return [Glyphs::CHECKBOX_LEFT, Glyphs::CHECKBOX_RIGHT];
        }
        $rail = $this->glyphs()[5];

        return [$rail, $rail];
    }

    /** The border's tint — secondary to the shape, never the only cue. */
    public function borderRole(): Role
    {
        return match ($this) {
            self::Idle => Role::FieldBorder,
            self::Focused => Role::TabAccent,
            self::Danger => Role::DiagError,
            self::Disabled => Role::Faint,
        };
    }

    public function takesKeys(): bool
    {
        return self::Focused === $this || self::Danger === $this;
    }
}
