<?php

declare(strict_types=1);

namespace Porta\Widget\Render;

use Porta\Ui\Glyphs;
use Porta\Ui\Role;
use Porta\Ui\Theme\Styles;

/**
 * The tab strip's close `×`, dimmed (bright on the active tab) so it reads as
 * an affordance. Two named variants rather than one shade(bool) — the pick is
 * the caller's: {@see TabStripRenderer} makes it for every horizontal strip,
 * and the editor's vertical list for its rows. A strip whose tab cannot close
 * (`closable: false`) draws no `×` at all.
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
