<?php

declare(strict_types=1);

namespace Porta\Ui;

/**
 * The `--no-ansi` {@see Palette}: every color role resolves to nothing, so the
 * IDE renders without any SGR color while its text, layout and structural ANSI
 * (cursor, mouse, alt-screen) are left intact. {@see reset()} still emits the
 * attribute reset so bold/reverse runs (which stay in monochrome) are closed.
 */
final class MonochromePalette implements Palette
{
    public function of(Role $role): string
    {
        return '';
    }

    public function fgReset(): string
    {
        return '';
    }

    public function reset(): string
    {
        return "\x1b[0m";
    }

    public function hasColor(): bool
    {
        return false;
    }
}
