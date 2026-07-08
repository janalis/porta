<?php

declare(strict_types=1);

namespace Porta\Highlight;

use Tempest\Highlight\TerminalTheme;

/**
 * Selects the syntax-highlighting {@see TerminalTheme} at container-build time
 * from the boot flags: a colorless {@see PlainTerminalTheme} for `--no-ansi`,
 * otherwise the {@see LightTerminalTheme} or (by default) {@see DarkTerminalTheme}
 * per the `theme` setting. The syntax-layer mirror of {@see \Porta\Ui\PaletteFactory};
 * bound to the `porta.ansi` and `porta.theme` container parameters.
 */
final class TerminalThemeFactory
{
    public static function create(bool $ansi, string $theme): TerminalTheme
    {
        if (!$ansi) {
            return new PlainTerminalTheme();
        }

        return 'light' === $theme ? new LightTerminalTheme() : new DarkTerminalTheme();
    }
}
