<?php

declare(strict_types=1);

namespace Porta\Ui;

/**
 * A {@see Palette} whose backing scheme can be swapped at runtime. Widgets hold
 * their palette as a `readonly` reference, so the theme setting can only take
 * effect live if the object they hold stays the same while its colors change:
 * this holder delegates every call to a current inner palette and lets
 * {@see switchTo()} replace it, recoloring the whole UI without re-injection.
 */
final class SwitchablePalette implements Palette
{
    public function __construct(
        private Palette $current,
    ) {}

    /** Swap the backing palette; every widget holding this instance recolors on its next render. */
    public function switchTo(Palette $palette): void
    {
        $this->current = $palette;
    }

    public function of(Role $role): string
    {
        return $this->current->of($role);
    }

    public function fgReset(): string
    {
        return $this->current->fgReset();
    }

    public function reset(): string
    {
        return $this->current->reset();
    }

    public function hasColor(): bool
    {
        return $this->current->hasColor();
    }
}
