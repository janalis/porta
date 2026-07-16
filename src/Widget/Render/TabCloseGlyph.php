<?php

declare(strict_types=1);

namespace Porta\Widget\Render;

use Porta\Ui\Role;
use Porta\Ui\Theme\Styles;

/**
 * The tab strip's close `×`, dimmed (bright on the active tab) so it reads as
 * an affordance. Shared by the editor tab bar (horizontal and vertical) and
 * the terminal panel's session tabs, which had three copies of the shade pick.
 */
final class TabCloseGlyph
{
    /** The bright variant shown on the active tab. */
    public static function active(Styles $styles): string
    {
        return $styles->of(Role::CurrentLineNumber)->apply('×');
    }

    /** The dim variant on inactive tabs. */
    public static function inactive(Styles $styles): string
    {
        return $styles->of(Role::Gutter)->apply('×');
    }
}
