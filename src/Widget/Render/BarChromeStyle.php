<?php

declare(strict_types=1);

namespace Porta\Widget\Render;

use Porta\Ui\Role;
use Porta\Ui\Theme\Styles;
use Symfony\Component\Tui\Style\Style;

/**
 * The shading of an item in a chrome bar — a tab in any of the four strips, a
 * menu-bar title — by its {@see TabState}: the active one bright and bold on
 * {@see Role::TabActiveBg}, the one under the pointer on the bar-chrome hover
 * shade, the rest subtle on the bar's own background.
 *
 * A bar item that shades differently from bar to bar is the drift worth
 * preventing, so the active/hovered shades are resolved once here. Only the
 * *rest* background differs between bars (the tab strips sit on {@see Role::Toolbar},
 * the menu-bar titles on {@see Role::HeaderBar}), so it is the one parameter.
 * The modal-list counterpart is {@see ModalRowStyle}; breadcrumbs deliberately
 * keep their own current/ancestor foreground and merely add the hover
 * background, so they do not route through here.
 */
final class BarChromeStyle
{
    public static function of(Styles $styles, TabState $state, Role $restBackground): Style
    {
        return match ($state) {
            TabState::Active => $styles->merge(Role::TabActiveBg, Role::Bright)->withBold(),
            TabState::Hovered => $styles->merge(Role::BarHoverBg, Role::Subtle),
            TabState::Plain => $styles->merge($restBackground, Role::Subtle),
        };
    }
}
