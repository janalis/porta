<?php

declare(strict_types=1);

namespace Porta\Widget\Render;

use Porta\Ui\Glyphs;

/**
 * What a list row's first column says about the row — a browser's focus ring
 * and selected state, drawn as a shape so they survive the monochrome theme
 * and `--no-ansi`, where the row's shading is gone.
 *
 * Bare glyphs, no colour of their own: the row's shading paints them, so a
 * colour here could only reset the foreground the rest of the row needs.
 */
enum SelectionBar: string
{
    /** The cursor, in the list that holds the keys. */
    case Focused = Glyphs::SELECTION_BAR;
    /** The cursor, in a list that does not — where the keys would land. */
    case Unfocused = Glyphs::SELECTION_BAR_UNFOCUSED;
    /** Picked without being the cursor: the second file of a pair. */
    case Marked = Glyphs::SELECTION_MARKED;
    case None = ' ';

    public static function of(bool $selected, bool $focused, bool $marked = false): self
    {
        return match (true) {
            $selected && $focused => self::Focused,
            $selected => self::Unfocused,
            $marked => self::Marked,
            default => self::None,
        };
    }
}
