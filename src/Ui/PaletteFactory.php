<?php

declare(strict_types=1);

namespace Porta\Ui;

use Porta\Highlight\ColorMode;

/**
 * Selects the active {@see Palette} at container-build time from the boot flags:
 * a {@see MonochromePalette} for `--no-ansi`, otherwise a colored
 * {@see ColorPalette} in the requested theme (`light`, `high-contrast` or, by
 * default, `dark`). Bound to the `porta.ansi` and `porta.theme` container parameters.
 */
final class PaletteFactory
{
    public static function create(ColorMode $color, string $theme): Palette
    {
        if (ColorMode::Plain === $color) {
            return new MonochromePalette();
        }

        return match ($theme) {
            'light' => ColorPalette::light(),
            'high-contrast' => ColorPalette::highContrast(),
            default => ColorPalette::dark(),
        };
    }

    /**
     * The {@see Palette} wrapped in a {@see SwitchablePalette} so the theme can be
     * swapped live. This is what the container binds; widgets hold the holder.
     */
    public static function createSwitchable(ColorMode $color, string $theme): SwitchablePalette
    {
        return new SwitchablePalette(self::create($color, $theme));
    }
}
