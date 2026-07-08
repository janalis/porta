<?php

declare(strict_types=1);

namespace Porta\Ui;

/**
 * Selects the active {@see Palette} at container-build time from the boot flags:
 * a {@see MonochromePalette} for `--no-ansi`, otherwise a colored
 * {@see ColorPalette} in the requested theme (`light` or, by default, `dark`).
 * Bound to the `porta.ansi` and `porta.theme` container parameters.
 */
final class PaletteFactory
{
    public static function create(bool $ansi, string $theme): Palette
    {
        if (!$ansi) {
            return new MonochromePalette();
        }

        return 'light' === $theme ? ColorPalette::light() : ColorPalette::dark();
    }

    /**
     * The {@see Palette} wrapped in a {@see SwitchablePalette} so the theme can be
     * swapped live. This is what the container binds; widgets hold the holder.
     */
    public static function createSwitchable(bool $ansi, string $theme): SwitchablePalette
    {
        return new SwitchablePalette(self::create($ansi, $theme));
    }
}
