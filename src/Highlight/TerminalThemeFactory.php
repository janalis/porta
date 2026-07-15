<?php

declare(strict_types=1);

namespace Porta\Highlight;

use Tempest\Highlight\TerminalTheme;

/**
 * Selects the syntax-highlighting {@see TerminalTheme} at container-build time
 * from the boot flags: a colorless {@see PlainTerminalTheme} for `--no-ansi`,
 * otherwise the {@see LightTerminalTheme}, {@see HighContrastTerminalTheme} or
 * (by default) {@see DarkTerminalTheme} per the `theme` setting. The syntax-layer
 * mirror of {@see \Porta\Ui\PaletteFactory}; bound to the `porta.ansi` and
 * `porta.theme` container parameters.
 */
final class TerminalThemeFactory
{
    public static function create(ColorMode $color, string $theme): TerminalTheme
    {
        if (ColorMode::Plain === $color) {
            return new PlainTerminalTheme();
        }

        return match ($theme) {
            'light' => new LightTerminalTheme(),
            'high-contrast' => new HighContrastTerminalTheme(),
            default => new DarkTerminalTheme(),
        };
    }

    /**
     * The {@see TerminalTheme} wrapped in a {@see SwitchableTerminalTheme} so the
     * theme can be swapped live. This is what the container binds; the buffers'
     * highlighters hold the holder.
     */
    public static function createSwitchable(ColorMode $color, string $theme): SwitchableTerminalTheme
    {
        return new SwitchableTerminalTheme(self::create($color, $theme));
    }
}
