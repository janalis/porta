<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Highlight;

use PHPUnit\Framework\TestCase;
use Porta\Highlight\DarkTerminalTheme;
use Porta\Highlight\HighContrastTerminalTheme;
use Porta\Highlight\LightTerminalTheme;
use Porta\Highlight\PlainTerminalTheme;
use Porta\Highlight\TerminalThemeFactory;

final class TerminalThemeFactoryTest extends TestCase
{
    public function testPlainWhenAnsiDisabledRegardlessOfTheme(): void
    {
        $this->assertInstanceOf(PlainTerminalTheme::class, TerminalThemeFactory::create(false, 'dark'));
        $this->assertInstanceOf(PlainTerminalTheme::class, TerminalThemeFactory::create(false, 'light'));
    }

    public function testDarkByDefault(): void
    {
        $this->assertInstanceOf(DarkTerminalTheme::class, TerminalThemeFactory::create(true, 'dark'));
    }

    public function testLightWhenThemeIsLight(): void
    {
        $this->assertInstanceOf(LightTerminalTheme::class, TerminalThemeFactory::create(true, 'light'));
    }

    public function testHighContrastWhenThemeIsHighContrast(): void
    {
        $this->assertInstanceOf(HighContrastTerminalTheme::class, TerminalThemeFactory::create(true, 'high-contrast'));
    }

    public function testUnknownThemeFallsBackToDark(): void
    {
        $this->assertInstanceOf(DarkTerminalTheme::class, TerminalThemeFactory::create(true, 'solarized'));
    }
}
