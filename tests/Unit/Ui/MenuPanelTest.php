<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;
use Porta\Ui\Menu\MenuItem;
use Porta\Ui\MenuPanel;
use Porta\Ui\Theme\DarkTheme;
use Porta\Ui\Theme\Styles;
use Symfony\Component\Tui\Ansi\AnsiUtils;

final class MenuPanelTest extends TestCase
{
    /** @return list<MenuItem> */
    private function items(): array
    {
        return [
            new MenuItem('Save', 'Ctrl+S', static fn () => null),
            MenuItem::separator(),
            new MenuItem('Quit', 'Ctrl+Q', static fn () => null),
        ];
    }

    public function testDisabledItemsAreSkippedForSelection(): void
    {
        $items = [
            new MenuItem('a', '', static fn () => null, false, static fn (): bool => false), // disabled
            new MenuItem('b', '', static fn () => null),
            MenuItem::separator(),
            new MenuItem('c', '', static fn () => null),
        ];

        $this->assertSame(1, MenuPanel::firstSelectable($items), 'skips the disabled first item');
        $this->assertSame(3, MenuPanel::step($items, 1, 1), 'down from b skips the separator to c');
        $this->assertSame(1, MenuPanel::step($items, 3, 1), 'wraps back to b, skipping disabled a and the separator');
    }

    public function testDisabledItemRendersDimAndIsNeverHighlighted(): void
    {
        $items = [new MenuItem('Save', 'Ctrl+S', static fn () => null, false, static fn (): bool => false)];
        $segment = MenuPanel::segments($items, 0, 20, new Styles(DarkTheme::definition()))[0]['segment']; // selected index 0, yet disabled

        $this->assertStringContainsString("\x1b[38;5;240m", $segment, 'disabled item uses the dim foreground');
        $this->assertStringContainsString("\x1b[2m", $segment, 'SGR dim attribute keeps the state visible without color');
        $this->assertStringNotContainsString("\x1b[48;5;31m", $segment, 'disabled item is not highlighted even when selected');
    }

    public function testWidthFitsWidestItemAndHasAFloor(): void
    {
        $width = MenuPanel::width($this->items());
        // 'Quit' + '  ' + 'Ctrl+Q' + padding is wider than the 8-column floor.
        $this->assertGreaterThan(8, $width);
        $this->assertSame(8, MenuPanel::width([new MenuItem('x', '', static fn () => null)]), 'never narrower than 8');
        $this->assertSame(8, MenuPanel::width([MenuItem::separator()]), 'separators do not drive the width');
    }

    public function testSegmentsRenderExactWidthWithSelectionHighlight(): void
    {
        $width = MenuPanel::width($this->items());
        $segments = MenuPanel::segments($this->items(), 2, $width, new Styles(DarkTheme::definition()));

        $this->assertCount(3, $segments);
        $this->assertTrue($segments[1]['separator']);
        foreach ($segments as $segment) {
            $this->assertSame($width, AnsiUtils::visibleWidth($segment['segment']), 'each row is exactly panelWidth wide');
        }
        // The selected row (index 2, Quit) carries the highlight background.
        $this->assertStringContainsString("\x1b[48;5;31m", $segments[2]['segment']);
        $this->assertStringNotContainsString("\x1b[48;5;31m", $segments[0]['segment']);

        $plain = AnsiUtils::stripAnsiCodes(implode("\n", array_column($segments, 'segment')));
        $this->assertStringContainsString('Save', $plain);
        $this->assertStringContainsString('Ctrl+S', $plain);
    }

    public function testLongLabelIsTruncatedToFit(): void
    {
        $items = [new MenuItem('A very long menu item label indeed', 'F2', static fn () => null)];
        $segments = MenuPanel::segments($items, 0, 20, new Styles(DarkTheme::definition()));
        $this->assertSame(20, AnsiUtils::visibleWidth($segments[0]['segment']));
        $this->assertStringContainsString('…', AnsiUtils::stripAnsiCodes($segments[0]['segment']));
    }

    public function testFirstSelectableSkipsLeadingSeparators(): void
    {
        $this->assertSame(0, MenuPanel::firstSelectable($this->items()));
        $this->assertSame(1, MenuPanel::firstSelectable([MenuItem::separator(), new MenuItem('X', '', static fn () => null)]));
        $this->assertSame(0, MenuPanel::firstSelectable([MenuItem::separator(), MenuItem::separator()]), 'none selectable → 0');
    }

    public function testStepSkipsSeparatorsAndWraps(): void
    {
        $items = $this->items(); // Save(0), sep(1), Quit(2)
        $this->assertSame(2, MenuPanel::step($items, 0, 1), 'down from Save skips the separator to Quit');
        $this->assertSame(0, MenuPanel::step($items, 2, 1), 'down from Quit wraps to Save');
        $this->assertSame(2, MenuPanel::step($items, 0, -1), 'up from Save wraps past the separator to Quit');
        $this->assertSame(0, MenuPanel::step([MenuItem::separator(), MenuItem::separator()], 0, 1), 'no selectable item → stays put');
    }
}
