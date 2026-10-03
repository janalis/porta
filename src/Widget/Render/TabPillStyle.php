<?php

declare(strict_types=1);

namespace Porta\Widget\Render;

use Porta\Ui\Role;
use Porta\Ui\Theme\Styles;
use Symfony\Component\Tui\Style\Style;

/**
 * A tab's shading on a roomy screen, where a tab is a pill rather than an
 * outline: the file tree's three shades, so a tab and a tree row read alike.
 * The rest sit on nothing, subtle; the hovered one takes the rows' hover
 * tint; the shown one the list selection's — {@see Role::SelectedRow} while
 * its pane has the keys, else {@see Role::SelectedRowUnfocused}. Bright and
 * bold over it, so a theme whose selection sets no colour of its own still
 * lifts the label.
 *
 * The compact one-row cells keep {@see TabStyle}: the toolbar shades, a
 * divider between cells, no halves to reach into.
 */
final class TabPillStyle
{
    // @mago-expect lint:no-boolean-flag-parameter -- whether the pane has the keys IS which selection shade the shown tab wears, not a second responsibility.
    public static function of(Styles $styles, TabState $state, bool $paneFocused): Style
    {
        return match ($state) {
            TabState::Active => $styles->merge(Role::Bright, $paneFocused ? Role::SelectedRow : Role::SelectedRowUnfocused)->withBold(),
            TabState::Hovered => $styles->merge(Role::Subtle, Role::RowHighlightBg),
            TabState::Plain => $styles->of(Role::Subtle),
        };
    }
}
