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

        return match ($theme) {
            'light' => LightTheme::definition(),
            'high-contrast' => HighContrastTheme::definition(),
            default => DarkTheme::definition(),
        };
    }
}
