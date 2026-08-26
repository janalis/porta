<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Ui\Theme;

use PHPUnit\Framework\TestCase;
use Porta\Highlight\ColorMode;
use Porta\Ui\Role;
use Porta\Ui\Theme\DarkTheme;
use Porta\Ui\Theme\LightTheme;
use Porta\Ui\Theme\MonochromeTheme;
use Porta\Ui\Theme\Styles;
use Porta\Ui\Theme\Themes;
use Symfony\Component\Tui\Style\Style;

final class StylesTest extends TestCase
{
    public function testOfDelegatesToTheBackingDefinition(): void
    {
        $styles = new Styles(DarkTheme::definition());

        $this->assertEquals(new Style(color: 114), $styles->of(Role::Added));
    }

    public function testApplyWrapsAFragmentInTheRoleStyle(): void
    {
        // The fragment pattern every widget uses: of($role)->apply($text)
        // replaces the palette's SGR-prefix + Sgr::RESET concatenation.
        $styles = new Styles(DarkTheme::definition());

        $this->assertSame("\x1b[38;5;114m+line\x1b[39m", $styles->of(Role::Added)->apply('+line'));
        $this->assertSame('text', new Styles(MonochromeTheme::definition())->of(Role::Added)->apply('text'));
    }

    public function testSwitchToRecolorsWithoutReinjection(): void
    {
        $styles = new Styles(DarkTheme::definition());
        $styles->switchTo(LightTheme::definition());

        $this->assertEquals(new Style(color: 28), $styles->of(Role::Added));
        $this->assertTrue($styles->hasColor());
    }

    public function testMergeCombinesRolesLaterWinning(): void
    {
        $styles = new Styles(DarkTheme::definition());

        $this->assertEquals(new Style(background: 236, color: 110), $styles->merge(Role::RowHighlightBg, Role::TabAccent));
        $this->assertEquals(new Style(background: 31, color: 110, bold: true), $styles->merge(Role::SelectedRow, Role::TabAccent), 'the later role overrides the color, the rest survives');
    }

    public function testMergeIsMemoizedAndDropsTheMemoOnThemeSwitch(): void
    {
        $styles = new Styles(DarkTheme::definition());

        $first = $styles->merge(Role::RowHighlightBg, Role::TabAccent);
        $this->assertSame($first, $styles->merge(Role::RowHighlightBg, Role::TabAccent), 'per-frame callers share one merged instance');

        $styles->switchTo(LightTheme::definition());
        $this->assertNotEquals($first, $styles->merge(Role::RowHighlightBg, Role::TabAccent), 'a theme switch must re-merge — the memo baked the old colors');
    }

    public function testUnderlineDelegates(): void
    {
        $styles = new Styles(DarkTheme::definition());
        $this->assertSame("\x1b[58;5;203m", $styles->underline(Role::DiagErrorUnderline));

        $styles->switchTo(MonochromeTheme::definition());
        $this->assertSame('', $styles->underline(Role::DiagErrorUnderline));
        $this->assertFalse($styles->hasColor());
    }

    public function testCreateSwitchableSelectsFromTheBootFlags(): void
    {
        $this->assertEquals(new Styles(MonochromeTheme::definition()), Themes::createSwitchable(ColorMode::Plain, 'dark'));
        $this->assertEquals(new Styles(LightTheme::definition()), Themes::createSwitchable(ColorMode::Ansi, 'light'));
    }
}
