<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;
use Porta\Highlight\ColorMode;
use Porta\Ui\ColorPalette;
use Porta\Ui\MonochromePalette;
use Porta\Ui\PaletteFactory;
use Porta\Ui\Role;

final class PaletteFactoryTest extends TestCase
{
    public function testMonochromeWhenAnsiDisabledRegardlessOfTheme(): void
    {
        $this->assertInstanceOf(MonochromePalette::class, PaletteFactory::create(ColorMode::Plain, 'dark'));
        $this->assertInstanceOf(MonochromePalette::class, PaletteFactory::create(ColorMode::Plain, 'light'));
    }

    public function testDarkColorPaletteByDefault(): void
    {
        $palette = PaletteFactory::create(ColorMode::Ansi, 'dark');
        $this->assertInstanceOf(ColorPalette::class, $palette);
        $this->assertSame(ColorPalette::dark()->of(Role::HeaderBar), $palette->of(Role::HeaderBar));
    }

    public function testLightColorPaletteWhenThemeIsLight(): void
    {
        $palette = PaletteFactory::create(ColorMode::Ansi, 'light');
        $this->assertInstanceOf(ColorPalette::class, $palette);
        $this->assertSame(ColorPalette::light()->of(Role::HeaderBar), $palette->of(Role::HeaderBar));
        $this->assertNotSame(ColorPalette::dark()->of(Role::HeaderBar), $palette->of(Role::HeaderBar));
    }

    public function testHighContrastPaletteWhenThemeIsHighContrast(): void
    {
        $palette = PaletteFactory::create(ColorMode::Ansi, 'high-contrast');
        $this->assertInstanceOf(ColorPalette::class, $palette);
        $this->assertSame(ColorPalette::highContrast()->of(Role::HeaderBar), $palette->of(Role::HeaderBar));
        $this->assertNotSame(ColorPalette::dark()->of(Role::Bright), $palette->of(Role::Muted), 'high contrast brightens the muted shade');
    }

    public function testUnknownThemeFallsBackToDark(): void
    {
        $palette = PaletteFactory::create(ColorMode::Ansi, 'solarized');
        $this->assertSame(ColorPalette::dark()->of(Role::HeaderBar), $palette->of(Role::HeaderBar));
    }
}
