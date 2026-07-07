<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget\Settings;

use PHPUnit\Framework\TestCase;
use Porta\Widget\Settings\SettingsTabsWidget;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;

final class SettingsTabsWidgetTest extends TestCase
{
    public function testRendersBothTabsWithTheActiveOneHighlighted(): void
    {
        $widget = new SettingsTabsWidget();
        $lines = $widget->render(new RenderContext(44, 1));

        $this->assertCount(1, $lines);
        $plain = AnsiUtils::stripAnsiCodes($lines[0]);
        $this->assertStringContainsString('Settings', $plain);
        $this->assertStringContainsString('Keybindings', $plain);
        $this->assertStringContainsString("\x1b[48;5;31m", $lines[0], 'the active tab uses the selected style');

        $widget->setActive('keybindings');
        $lines = $widget->render(new RenderContext(44, 1));
        $highlightPos = strpos($lines[0], "\x1b[48;5;31m");
        $this->assertNotFalse($highlightPos);
        $this->assertStringContainsString('Keybindings', substr($lines[0], $highlightPos), 'the highlight moved to Keybindings');
    }

    public function testClickOnTheInactiveTabFiresAndTheActiveOneDoesNot(): void
    {
        $selected = [];
        $widget = new SettingsTabsWidget();
        $widget->onSelect(function (string $mode) use (&$selected): void {
            $selected[] = $mode;
        });
        $widget->render(new RenderContext(44, 1)); // populate the layout map

        $this->assertSame('keybindings', $widget->modeAtColumn(14), 'inside " Keybindings "');
        $widget->handleClick(14);
        $this->assertSame(['keybindings'], $selected);

        // The controller flips the active tab; a repeat click is a no-op.
        $widget->setActive('keybindings');
        $widget->handleClick(14);
        $this->assertSame(['keybindings'], $selected);

        // Clicking the (now inactive) Settings tab switches back.
        $widget->handleClick(2);
        $this->assertSame(['keybindings', 'settings'], $selected);

        // Dead space past the tabs selects nothing.
        $this->assertNull($widget->modeAtColumn(40));
        $widget->handleClick(40);
        $this->assertSame(['keybindings', 'settings'], $selected);
    }

    public function testSetActiveIsIdempotentAndOverlayComposites(): void
    {
        $widget = new SettingsTabsWidget();
        $widget->setActive('settings'); // already active: no-op

        $widget->setMenuOverlay([['row' => 0, 'col' => 0, 'width' => 8, 'segment' => "\x1b[7mDROPDOWN\x1b[0m"]]);
        $line = $widget->render(new RenderContext(44, 1))[0];
        $this->assertStringContainsString('DROPDOWN', $line, 'a dropdown span composites over the strip');
    }
}
