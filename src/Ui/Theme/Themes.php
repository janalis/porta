<?php

declare(strict_types=1);

namespace Porta\Ui\Theme;

use Porta\Highlight\ColorMode;

/**
 * Selects the active {@see ThemeDefinition} from the boot flags: the
 * {@see MonochromeTheme} for `--no-ansi`, otherwise the requested theme
 * (`light`, `high-contrast` or, by default, `dark`) — the same selection
 * the settings view offers live.
 */
final class Themes
{
    public static function definition(ColorMode $color, string $theme): ThemeDefinition
    {
        if (ColorMode::Plain === $color) {
            return MonochromeTheme::definition();
        }

        return Theme::fromId($theme)->definition();
    }

    /**
     * The definition wrapped in a {@see Styles} holder so the theme can be
     * swapped live. This is what the container binds; widgets hold the holder.
     */
    public static function createSwitchable(ColorMode $color, string $theme): Styles
    {
        return new Styles(self::definition($color, $theme));
    }
}
