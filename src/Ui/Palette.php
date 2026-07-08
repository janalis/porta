<?php

declare(strict_types=1);

namespace Porta\Ui;

/**
 * The IDE's color scheme, as escape sequences per semantic {@see Role}. Widgets
 * prepend `of($role)` to their text and close with `reset()`/`fgReset()`,
 * instead of hardcoding raw SGR codes — so a single implementation swap recolors
 * the whole UI. {@see ColorPalette} emits real colors (dark/light);
 * {@see MonochromePalette} emits nothing (`--no-ansi`), leaving text and
 * structural ANSI untouched.
 */
interface Palette
{
    /** The full SGR sequence for $role (foreground, background, or both), or '' in monochrome. */
    public function of(Role $role): string;

    /** Reset the foreground to the terminal default ("\x1b[39m"), or '' in monochrome. */
    public function fgReset(): string;

    /** Reset all attributes ("\x1b[0m"); kept in monochrome to close bold/reverse runs. */
    public function reset(): string;

    /** Whether this palette emits color (false in monochrome) — drives the syntax theme too. */
    public function hasColor(): bool;
}
