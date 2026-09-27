<?php

declare(strict_types=1);

namespace Porta\Widget\Render;

use Porta\Ui\Glyphs;
use Porta\Ui\Role;
use Porta\Ui\Theme\Styles;

/**
 * The close `×`, dimmed (bright on the active tab) so it reads as an
 * affordance, and underlined as well as brightened under the pointer — the
 * one hover face every `×` wears: a tab's, a modal's, a toast's. Named
 * variants rather than one shade(bool) — the pick is the caller's:
 * {@see TabStripRenderer} makes it for every horizontal strip, the editor's
 * vertical list for its rows, {@see ModalFrame} and {@see ToastCard} for their
 * title bars. A strip whose tab cannot close (`closable: false`) draws no `×`.
 */
final class TabCloseGlyph
{
    /** The bright variant shown on the active tab. */
    public static function active(Styles $styles): string
    {
        return $styles->of(Role::CurrentLineNumber)->apply(Glyphs::CLOSE);
    }

    /** Under the pointer: underlined, so the hover reads in the monochrome theme too. */
    public static function hovered(Styles $styles): string
    {
        return $styles->of(Role::Bright)->withUnderline()->apply(Glyphs::CLOSE);
    }

    /** The dim variant on inactive tabs. */
    public static function inactive(Styles $styles): string
    {
        return $styles->of(Role::Gutter)->apply(Glyphs::CLOSE);
    }
}
