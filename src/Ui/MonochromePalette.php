<?php

declare(strict_types=1);

namespace Porta\Ui;

/**
 * The `--no-ansi` {@see Palette}: every color role resolves to nothing, so the
 * IDE renders without any SGR color while its text, layout and structural ANSI
 * (cursor, mouse, alt-screen) are left intact. {@see reset()} still emits the
 * attribute reset so bold/reverse runs (which stay in monochrome) are closed.
 * Selection is the one state that must survive without color, so the selected
 * row maps to reverse video (dimmed while its pane is unfocused).
 */
final class MonochromePalette implements Palette
{
    public function of(Role $role): string
    {
        return match ($role) {
            Role::SelectedRow => Sgr::REVERSE,
            Role::SelectedRowUnfocused => "\x1b[2;7m",
            default => '',
        };
    }

    public function fgReset(): string
    {
        return '';
    }

    public function reset(): string
    {
        return Sgr::RESET;
    }

    public function hasColor(): bool
    {
        return false;
    }
}
