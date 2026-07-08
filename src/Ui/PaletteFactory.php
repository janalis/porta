<?php

declare(strict_types=1);

namespace Porta\Ui;

/**
 * Selects the active {@see Palette} at container-build time from the boot flags:
 * a {@see MonochromePalette} for `--no-ansi`, otherwise a colored
 * {@see ColorPalette}. Bound to the `porta.ansi` container parameter.
 */
final class PaletteFactory
{
    public static function create(bool $ansi): Palette
    {
        return $ansi ? ColorPalette::dark() : new MonochromePalette();
    }
}
