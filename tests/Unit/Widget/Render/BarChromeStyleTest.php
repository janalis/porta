<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget\Render;

use PHPUnit\Framework\TestCase;
use Porta\Ui\Role;
use Porta\Ui\Theme\Styles;
use Porta\Widget\Render\BarChromeStyle;
use Porta\Widget\Render\TabState;

final class BarChromeStyleTest extends TestCase
{
    public function testActiveAndHoveredAreTheSameAcrossBarsRegardlessOfRestBackground(): void
    {
        $styles = Styles::dark();

        // The active and hovered shades do not depend on the bar's rest
        // background: a menu title and a tab light identically.
        foreach ([Role::Toolbar, Role::HeaderBar] as $rest) {
            $active = BarChromeStyle::of($styles, TabState::Active, $rest)->apply('x');
            $this->assertStringContainsString("\x1b[48;5;238m", $active, 'active on TabActiveBg');
            $this->assertStringContainsString("\x1b[1m", $active, 'active is bold');

            $hovered = BarChromeStyle::of($styles, TabState::Hovered, $rest)->apply('x');
            $this->assertStringContainsString("\x1b[48;5;240m", $hovered, 'hovered on the bar-chrome hover shade');
        }
    }

    public function testTheRestShadeSitsOnTheBarsOwnBackground(): void
    {
        $styles = Styles::dark();

        $onToolbar = BarChromeStyle::of($styles, TabState::Plain, Role::Toolbar)->apply('x');
        $this->assertStringContainsString("\x1b[48;5;235m", $onToolbar, 'tabs rest on Toolbar');

        $onHeaderBar = BarChromeStyle::of($styles, TabState::Plain, Role::HeaderBar)->apply('x');
        $this->assertStringContainsString("\x1b[48;5;237m", $onHeaderBar, 'menu titles rest on HeaderBar');
    }
}
