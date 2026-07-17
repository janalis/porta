<?php

declare(strict_types=1);

namespace Porta\Widget\Render;

use Porta\Ui\Glyphs;
use Porta\Ui\Role;
use Porta\Ui\Theme\Styles;

/**
 * The tab strip's close `×`, dimmed (bright on the active tab) so it reads as
 * an affordance. Two named variants rather than one shade(bool) — the pick is
 * the caller's, and {@see TabCell} is now the only place that makes it for the
 * strips that carry a close affordance: the editor tab bar (horizontal and
 * vertical) and the bottom dock's strip.
 */
final class TabCloseGlyph
{
    /** The bright variant shown on the active tab. */
    public static function active(Styles $styles): string
    {
        return $styles->of(Role::CurrentLineNumber)->apply(Glyphs::CLOSE);
    }

    /** The dim variant on inactive tabs. */
    public static function inactive(Styles $styles): string
    {
        return $styles->of(Role::Gutter)->apply(Glyphs::CLOSE);
    }
}
