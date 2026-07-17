<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget\Render;

use PHPUnit\Framework\TestCase;
use Porta\Ui\Theme\Styles;
use Porta\Widget\Render\TabState;
use Porta\Widget\Render\TabStyle;

final class TabStyleTest extends TestCase
{
    public function testTheThreeShadesOfTheStripFamily(): void
    {
        $styles = Styles::dark();

        $active = TabStyle::of($styles, TabState::Active)->apply('x');
        $this->assertStringContainsString("\x1b[48;5;238m", $active, 'active on TabActiveBg');
        $this->assertStringContainsString("\x1b[1m", $active, 'active is bold');

        $hovered = TabStyle::of($styles, TabState::Hovered)->apply('x');
        $this->assertStringContainsString("\x1b[48;5;240m", $hovered, 'hovered on the bar-chrome hover shade');

        $plain = TabStyle::of($styles, TabState::Plain)->apply('x');
        $this->assertStringContainsString("\x1b[48;5;235m", $plain, 'the rest on Toolbar');
    }

    public function testActiveWinsOverHoveredWhenResolvingTheTwoFacts(): void
    {
        // The pointer resting on the active tab must leave it looking active —
        // the precedence every strip used to spell out for itself.
        $this->assertSame(TabState::Active, TabState::of(active: true, hovered: true));
        $this->assertSame(TabState::Hovered, TabState::of(active: false, hovered: true));
        $this->assertSame(TabState::Plain, TabState::of(active: false, hovered: false));
    }
}
