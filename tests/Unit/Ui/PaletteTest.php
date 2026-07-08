<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Ui;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\Ui\ColorPalette;
use Porta\Ui\MonochromePalette;
use Porta\Ui\Role;

final class PaletteTest extends TestCase
{
    /**
     * Locks the dark palette to the exact SGR codes the widgets hardcoded before
     * the migration — the guardrail against a visual regression.
     *
     * @return iterable<string, array{Role, string}>
     */
    public static function darkRoles(): iterable
    {
        yield 'gutter' => [Role::Gutter, "\x1b[38;5;240m"];
        yield 'added' => [Role::Added, "\x1b[38;5;114m"];
        yield 'deleted' => [Role::Deleted, "\x1b[38;5;203m"];
        yield 'hunk' => [Role::Hunk, "\x1b[38;5;75m"];
        yield 'selected-row' => [Role::SelectedRow, "\x1b[48;5;31m\x1b[38;5;231m"];
        yield 'row-highlight-bg' => [Role::RowHighlightBg, "\x1b[48;5;236m"];
        yield 'popup-row' => [Role::PopupRow, "\x1b[48;5;236m\x1b[38;5;250m"];
    }

    #[DataProvider('darkRoles')]
    public function testDarkPaletteReproducesHistoricalCodes(Role $role, string $expected): void
    {
        $this->assertSame($expected, ColorPalette::dark()->of($role));
    }

    public function testDarkResets(): void
    {
        $dark = ColorPalette::dark();
        $this->assertSame("\x1b[39m", $dark->fgReset());
        $this->assertSame("\x1b[0m", $dark->reset());
    }

    public function testMonochromeEmitsNoColorForEveryRole(): void
    {
        $mono = new MonochromePalette();
        foreach (Role::cases() as $role) {
            $this->assertSame('', $mono->of($role), $role->name.' must emit no color');
        }
    }

    public function testMonochromeResets(): void
    {
        $mono = new MonochromePalette();
        $this->assertSame('', $mono->fgReset(), 'no fg reset when nothing was colored');
        $this->assertSame("\x1b[0m", $mono->reset(), 'attribute reset stays to close bold/reverse');
    }
}
