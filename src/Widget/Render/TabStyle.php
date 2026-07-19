<?php

declare(strict_types=1);

namespace Porta\Widget\Render;

use Porta\Ui\Role;
use Porta\Ui\Theme\Styles;
use Symfony\Component\Tui\Style\Style;

/**
 * The unified strip family's tab shading — the active tab bright and bold, a
 * hovered one on the bar-chrome hover shade, the rest subtle on the toolbar.
 *
 * This is the one thing all four strips share — the editor tab bar in both
 * orientations, the bottom dock's strip and the git view's mode tabs. Their
 * cells differ (only two carry a close ×, only one is a column of rows), but
 * a tab that shades differently from strip to strip is the drift worth
 * preventing. The shades themselves are the bar-chrome family's, so this pins
 * {@see BarChromeStyle} to the tab strips' {@see Role::Toolbar} rest background.
 */
final class TabStyle
{
    public static function of(Styles $styles, TabState $state): Style
    {
        return BarChromeStyle::of($styles, $state, Role::Toolbar);
    }
}
