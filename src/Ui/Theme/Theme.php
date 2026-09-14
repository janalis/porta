<?php

declare(strict_types=1);

namespace Porta\Ui\Theme;

use Porta\Highlight\DarkTerminalTheme;
use Porta\Highlight\HighContrastTerminalTheme;
use Porta\Highlight\LightTerminalTheme;
use Tempest\Highlight\TerminalTheme;

/**
 * The IDE's colour schemes. The id travels as a string through `.porta.json`
 * and `$PORTA_THEME`, but everywhere inside it is this enum — as every other
 * kind in this codebase already is.
 *
 * Both halves of a theme hang off the case: the chrome {@see ThemeDefinition}
 * and the syntax-layer {@see TerminalTheme}. They used to be two `match` tables
 * in two files ({@see Themes} and {@see \Porta\Highlight\TerminalThemeFactory})
 * keyed by the same three strings, which is how a theme could be added to one
 * and forgotten in the other. Both matches here are exhaustive over the cases,
 * so a new case fails loudly instead of silently falling back to dark.
 *
 * The colourless variants are NOT cases: {@see MonochromeTheme} and
 * {@see \Porta\Highlight\PlainTerminalTheme} are what `--no-ansi` selects
 * regardless of the configured theme, so they belong to the ColorMode branch,
 * not to this list of user choices.
 */
enum Theme: string
{
    case Dark = 'dark';
    case Light = 'light';
    case HighContrast = 'high-contrast';

    /** The configured id, falling back to dark for an unset or unknown value. */
    public static function fromId(?string $id): self
    {
        return self::tryFrom((string) $id) ?? self::Dark;
    }

    /**
     * Every id, for the settings page's choice row.
     *
     * @return list<string>
     */
    public static function ids(): array
    {
        return \array_map(static fn (self $theme): string => $theme->value, self::cases());
    }

    /** The chrome palette (menus, panes, gutters, status bar). */
    public function definition(): ThemeDefinition
    {
        return match ($this) {
            self::Dark => DarkTheme::definition(),
            self::Light => LightTheme::definition(),
            self::HighContrast => HighContrastTheme::definition(),
        };
    }

    /** The syntax-highlighting palette, applied to code spans. */
    public function terminalTheme(): TerminalTheme
    {
        return match ($this) {
            self::Dark => new DarkTerminalTheme(),
            self::Light => new LightTerminalTheme(),
            self::HighContrast => new HighContrastTerminalTheme(),
        };
    }
}
