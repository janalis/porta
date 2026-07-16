<?php

declare(strict_types=1);

namespace Porta\Ui\Theme;

use Porta\Ui\Role;
use Symfony\Component\Tui\Style\Style;

/**
 * The active theme's per-role tui {@see Style}s, behind a swappable holder:
 * widgets hold this one instance as a `readonly` reference, so the theme
 * setting can only take effect live if the object stays the same while its
 * styles change — {@see switchTo()} replaces the backing definition and the
 * whole UI recolors on its next render. Consumers wrap fragments with
 * `of($role)->apply($text)` instead of concatenating raw SGR sequences.
 */
final class Styles
{
    public function __construct(
        private ThemeDefinition $current,
    ) {}

    /** Swap the backing theme; every consumer holding this instance recolors on its next render. */
    public function switchTo(ThemeDefinition $definition): void
    {
        $this->current = $definition;
    }

    public function of(Role $role): Style
    {
        return $this->current->of($role);
    }

    /** Several roles merged into one Style — later roles win per property. */
    public function merge(Role ...$roles): Style
    {
        return Style::mergeAll(array_map($this->current->of(...), $roles));
    }

    /** The SGR-58 underline-color sequence for $role ('' when the theme defines none). */
    public function underline(Role $role): string
    {
        return $this->current->underline($role);
    }

    /** Whether the theme emits color (false in monochrome) — drives the syntax theme too. */
    public function hasColor(): bool
    {
        return $this->current->hasColor();
    }
}
