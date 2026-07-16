<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Ui\Theme;

use PHPUnit\Framework\TestCase;
use Porta\Ui\Role;
use Porta\Ui\Theme\DarkTheme;
use Porta\Ui\Theme\MonochromeTheme;
use Porta\Ui\Theme\StyleSheetThemer;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Style\TailwindStylesheet;
use Symfony\Component\Tui\Widget\TextWidget;

final class StyleSheetThemerTest extends TestCase
{
    public function testAppliesEveryThemeRuleToTheRenderersLiveSheet(): void
    {
        $original = new TailwindStylesheet();
        $renderer = new Renderer($original);

        new StyleSheetThemer($renderer)->apply(DarkTheme::definition());

        $this->assertEquals(new Style(color: 114), $renderer->getStyleSheet()->getRules()[Role::Added->selector()]);
        // The Renderer works on a clone of the constructor sheet — rules must
        // land on the live clone, never the original.
        $this->assertArrayNotHasKey(Role::Added->selector(), $original->getRules());
    }

    public function testRoleClassesResolveThroughTheStylesheetCascade(): void
    {
        $renderer = new Renderer(new TailwindStylesheet());
        new StyleSheetThemer($renderer)->apply(DarkTheme::definition());

        $widget = new TextWidget();
        $widget->addStyleClass('role-toolbar');

        $this->assertSame("\x1b[48;5;235m", $renderer->resolveStyle($widget)->getBackground()?->toBackgroundCode());
    }

    public function testReapplyOverwritesTheFullPreviousTheme(): void
    {
        $renderer = new Renderer(new TailwindStylesheet());
        $themer = new StyleSheetThemer($renderer);

        $themer->apply(DarkTheme::definition());
        $themer->apply(MonochromeTheme::definition());

        // The sheet has no rule removal, so the switch relies on every theme
        // covering the same selector set: dark's color must be fully gone.
        $rules = $renderer->getStyleSheet()->getRules();
        $this->assertTrue($rules[Role::Added->selector()]->isPlain());
        $this->assertEquals(new Style(reverse: true), $rules[Role::SelectedRow->selector()]);
    }
}
