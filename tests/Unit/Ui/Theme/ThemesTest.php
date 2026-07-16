<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Ui\Theme;

use PHPUnit\Framework\TestCase;
use Porta\Highlight\ColorMode;
use Porta\Ui\Theme\DarkTheme;
use Porta\Ui\Theme\HighContrastTheme;
use Porta\Ui\Theme\LightTheme;
use Porta\Ui\Theme\MonochromeTheme;
use Porta\Ui\Theme\Themes;

final class ThemesTest extends TestCase
{
    public function testPlainColorModeSelectsMonochromeWhateverTheTheme(): void
    {
        $this->assertEquals(MonochromeTheme::definition(), Themes::definition(ColorMode::Plain, 'dark'));
        $this->assertEquals(MonochromeTheme::definition(), Themes::definition(ColorMode::Plain, 'light'));
    }

    public function testThemeSelection(): void
    {
        $this->assertEquals(DarkTheme::definition(), Themes::definition(ColorMode::Ansi, 'dark'));
        $this->assertEquals(LightTheme::definition(), Themes::definition(ColorMode::Ansi, 'light'));
        $this->assertEquals(HighContrastTheme::definition(), Themes::definition(ColorMode::Ansi, 'high-contrast'));
        $this->assertEquals(DarkTheme::definition(), Themes::definition(ColorMode::Ansi, 'unknown'), 'unknown themes fall back to dark');
    }
}
