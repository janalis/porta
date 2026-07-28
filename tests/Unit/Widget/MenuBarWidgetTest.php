<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget;

use PHPUnit\Framework\TestCase;
use Porta\Widget\MenuBarWidget;
use Porta\Workbench\Menu\Menu;
use Porta\Workbench\Menu\MenuItem;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;

final class MenuBarWidgetTest extends TestCase
{
    /** @var list<string> */
    private array $ran = [];

    private function bar(): MenuBarWidget
    {
        $this->ran = [];
        $bar = new MenuBarWidget();
        $bar->setMenus([
            new Menu('File', [
                new MenuItem('Save', 'Ctrl+S', function (): void {
                    $this->ran[] = 'save';
                }),
                MenuItem::separator(),
                new MenuItem('Quit', 'Ctrl+Q', function (): void {
                    $this->ran[] = 'quit';
                }),
            ]),
            new Menu('Edit', [
                new MenuItem('Undo', 'Ctrl+Z', function (): void {
                    $this->ran[] = 'undo';
                }),
                new MenuItem('Redo', '', function (): void {
                    $this->ran[] = 'redo';
                }),
            ]),
        ]);

        return $bar;
    }

    /** @param string[] $rows */
    private function assertExactWidth(array $rows, int $width): void
    {
        foreach ($rows as $i => $row) {
            $this->assertSame($width, AnsiUtils::visibleWidth($row), "row {$i} must be {$width} wide");
        }
    }

    private function plain(array $rows): string
    {
        return AnsiUtils::stripAnsiCodes(implode("\n", $rows));
    }

    public function testClosedRendersTitleStripOnly(): void
    {
        $rows = $this->bar()->render(new RenderContext(60, 24));

        $this->assertCount(1, $rows, 'only the title row when closed');
        $this->assertExactWidth($rows, 60);
        $plain = $this->plain($rows);
        $this->assertStringContainsString('File', $plain);
        $this->assertStringContainsString('Edit', $plain);
    }

    public function testOpenStillRendersOnlyTheTitleRow(): void
    {
        $bar = $this->bar();
        $bar->open(0);
        $rows = $bar->render(new RenderContext(60, 24));

        // The dropdown floats over the widgets below (composited by the
        // coordinator), so opening a menu must NOT append rows here — otherwise
        // the layout reflows and content shifts down.
        $this->assertTrue($bar->isOpen());
        $this->assertCount(1, $rows, 'title row only, even when open');
        $this->assertExactWidth($rows, 60);
    }

    public function testDropdownSegmentsCarryLabelsShortcutsAndSeparators(): void
    {
        $bar = $this->bar();
        $bar->open(0);
        $bar->render(new RenderContext(60, 24));

        $width = $bar->panelWidth();
        $segments = $bar->dropdownSegments($width);

        $this->assertCount(3, $segments, 'Save / separator / Quit');
        $this->assertFalse($segments[0]['separator']);
        $this->assertTrue($segments[1]['separator']);
        $this->assertFalse($segments[2]['separator']);
        foreach ($segments as $segment) {
            $this->assertSame($width, AnsiUtils::visibleWidth($segment['segment']), 'each segment is exactly panelWidth wide');
        }
        $plain = $this->plain(array_column($segments, 'segment'));
        $this->assertStringContainsString('Save', $plain);
        $this->assertStringContainsString('Ctrl+S', $plain);
        $this->assertStringContainsString('Quit', $plain);
    }

    public function testDropdownSegmentsEmptyWhenClosed(): void
    {
        $this->assertSame([], $this->bar()->dropdownSegments(20), 'no segments while closed');
    }

    public function testGeometryAccessorsReflectOpenMenu(): void
    {
        $bar = $this->bar();
        $bar->render(new RenderContext(60, 24)); // populate titleLayout

        $this->assertSame(0, $bar->titleStart(0), 'File starts at column 0');
        $this->assertGreaterThan(0, $bar->titleStart(1), 'Edit starts after File');
        $this->assertSame(0, $bar->panelWidth(), 'no panel while closed');

        $bar->open(1);
        $this->assertSame(1, $bar->openIndex());
        $this->assertGreaterThanOrEqual(8, $bar->panelWidth(), 'open panel has a minimum width');
        $this->assertCount(2, $bar->dropdownSegments($bar->panelWidth()), 'Edit has Undo + Redo');
    }

    public function testOnNavigateFiresOnOpenAndMovement(): void
    {
        $bar = $this->bar();
        $hits = 0;
        $bar->onNavigate(static function () use (&$hits): void {
            ++$hits;
        });

        $bar->open(0); // fires
        $bar->handleInput("\x1b[B"); // down → fires
        $bar->handleInput("\x1b[C"); // right (re-open) → fires
        $bar->handleInput("\x1b[A"); // up → fires

        $this->assertSame(4, $hits, 'navigation notifies the coordinator to re-place the dropdown');
    }

    public function testDownSkipsSeparatorAndSelectRuns(): void
    {
        $bar = $this->bar();
        $bar->open(0); // selected = Save (0)
        $bar->handleInput("\x1b[B"); // down → skips separator → Quit (2)
        $bar->handleInput("\r"); // enter

        $this->assertSame(['quit'], $this->ran);
        $this->assertFalse($bar->isOpen(), 'closes after select');
    }

    public function testRightSwitchesMenuAndResetsSelection(): void
    {
        $bar = $this->bar();
        $bar->open(0);
        $bar->handleInput("\x1b[C"); // right → Edit
        $bar->handleInput("\r"); // enter → Edit's first item (Undo)

        $this->assertSame(['undo'], $this->ran);
    }

    public function testSelectFiresOnCloseCallback(): void
    {
        $bar = $this->bar();
        $closed = false;
        $bar->onClose(static function () use (&$closed): void {
            $closed = true;
        });
        $bar->open(0);
        $bar->handleInput("\r"); // enter on Save

        $this->assertSame(['save'], $this->ran);
        $this->assertTrue($closed, 'onClose fired to return focus');
    }

    public function testEscapeClosesWithoutRunning(): void
    {
        $bar = $this->bar();
        $bar->open(0);
        $bar->handleInput("\x1b"); // escape

        $this->assertFalse($bar->isOpen());
        $this->assertSame([], $this->ran);
    }

    public function testTitleAtColumnMapsClicks(): void
    {
        $bar = $this->bar();
        $bar->render(new RenderContext(60, 24)); // populate titleLayout

        $this->assertSame(0, $bar->titleAtColumn(3), 'inside File');
        $this->assertSame(1, $bar->titleAtColumn(8), 'inside Edit');
        $this->assertNull($bar->titleAtColumn(58), 'past the last title');
    }

    public function testRunItemIgnoresSeparatorRows(): void
    {
        $bar = $this->bar();
        $bar->open(0);
        $bar->render(new RenderContext(60, 24));

        $bar->runItem(1); // the separator row → no-op run, still closes
        $this->assertSame([], $this->ran, 'clicking a separator runs nothing');
        $this->assertFalse($bar->isOpen());
    }

    public function testRunItemInvokesAndCloses(): void
    {
        $bar = $this->bar();
        $bar->open(0);
        $bar->render(new RenderContext(60, 24));

        $bar->runItem(2); // Quit
        $this->assertSame(['quit'], $this->ran);
        $this->assertFalse($bar->isOpen());
    }

    public function testLeftSwitchesMenu(): void
    {
        $bar = $this->bar();
        $bar->open(1); // Edit
        $bar->handleInput("\x1b[D"); // left → File
        $bar->handleInput("\r"); // enter → File's first item (Save)

        $this->assertSame(['save'], $this->ran);
    }

    public function testUpWrapsToLastSelectable(): void
    {
        $bar = $this->bar();
        $bar->open(0); // File, selected = Save (0)
        $bar->handleInput("\x1b[A"); // up → wraps past the separator to Quit (2)
        $bar->handleInput("\r");

        $this->assertSame(['quit'], $this->ran);
    }

    public function testAllSeparatorMenuNavigatesSafely(): void
    {
        $bar = new MenuBarWidget();
        $bar->setMenus([new Menu('Empty', [MenuItem::separator(), MenuItem::separator()])]);
        $bar->open(0);
        $bar->handleInput("\x1b[B"); // down — no selectable item
        $bar->handleInput("\x1b[A"); // up
        $bar->handleInput("\r"); // enter — nothing to run
        $this->assertFalse($bar->isOpen(), 'select on a separator still closes');
    }

    public function testHoverTitleHighlightsAndClears(): void
    {
        $bar = $this->bar();
        $this->assertTrue($bar->hoverTitle(1), 'first hover changes state');
        $this->assertFalse($bar->hoverTitle(1), 'same title → no change');

        $row = $bar->render(new RenderContext(60, 24))[0];
        $this->assertStringContainsString("\x1b[48;5;240m", $row, 'hovered closed title uses the hover background');

        $this->assertTrue($bar->hoverTitle(null), 'clearing changes state');
        $row = $bar->render(new RenderContext(60, 24))[0];
        $this->assertStringNotContainsString("\x1b[48;5;240m", $row, 'no hover background once cleared');
    }

    public function testHoverTitleSuppressedWhileOpen(): void
    {
        $bar = $this->bar();
        $bar->hoverTitle(1);
        $bar->open(0); // the active style wins; the hover style is hidden while open
        $row = $bar->render(new RenderContext(60, 24))[0];
        $this->assertStringContainsString("\x1b[48;5;238m", $row, 'open title uses the tab-active background, like an active tab');
        $this->assertStringContainsString("\x1b[1m", $row, 'and is bold, matching TabStyle::Active');
        $this->assertStringNotContainsString("\x1b[48;5;31m", $row, 'no longer the strong SelectedRow blue');
        $this->assertStringNotContainsString("\x1b[48;5;240m", $row, 'hover style suppressed while a menu is open');
    }

    public function testHoverItemMovesSelectionSkippingSeparators(): void
    {
        $bar = $this->bar();
        $bar->open(0); // File, selected = Save (0)
        $bar->render(new RenderContext(60, 24));

        $this->assertFalse($bar->hoverItem(0), 'already on Save → no change');
        $this->assertFalse($bar->hoverItem(1), 'separator row → no selection');
        $this->assertFalse($bar->hoverItem(99), 'out-of-range index → no change');
        $this->assertTrue($bar->hoverItem(2), 'hovering Quit selects it');

        // The dropdown now highlights Quit; Enter runs the hovered item.
        $bar->handleInput("\r");
        $this->assertSame(['quit'], $this->ran);
    }

    public function testHoverItemNoMenusIsNoOp(): void
    {
        $this->assertFalse(new MenuBarWidget()->hoverItem(0), 'no menus → nothing to hover');
    }

    public function testDisabledItemsAreNotSelectedRunOrHovered(): void
    {
        $ran = false;
        $bar = new MenuBarWidget();
        $bar->setMenus([new Menu('File', [
            new MenuItem(
                'Save',
                'Ctrl+S',
                static function () use (&$ran): void {
                    $ran = true;
                },
                false,
                static fn (): bool => false,
            ),
            new MenuItem('Quit', 'Ctrl+Q', static fn () => null),
        ])]);

        $bar->open(0); // firstSelectable skips disabled Save → selects Quit
        $bar->render(new RenderContext(60, 24));

        $bar->runItem(0); // click the disabled Save → runs nothing
        $this->assertFalse($ran);
        $this->assertFalse($bar->isOpen(), 'clicking still closes the menu');

        $bar->open(0);
        $this->assertFalse($bar->hoverItem(0), 'cannot hover a disabled item');
    }

    public function testNarrowPaneDropsOverflowingTitles(): void
    {
        $bar = $this->bar(); // ' File ' (6) + ' Edit ' (6)
        // 8 columns fit File but not Edit, so Edit is dropped from the strip.
        $rows = $bar->render(new RenderContext(8, 1));

        $this->assertExactWidth($rows, 8);
        $plain = $this->plain($rows);
        $this->assertStringContainsString('File', $plain);
        $this->assertStringNotContainsString('Edit', $plain, 'the overflowing title is dropped');
        $this->assertNull($bar->titleAtColumn(6), 'a dropped title is not clickable');
    }

    public function testInputIgnoredWhenClosed(): void
    {
        $bar = $this->bar();
        $bar->handleInput("\r"); // no menu open
        $this->assertSame([], $this->ran);
        $this->assertFalse($bar->isOpen());
    }

    public function testOpenWithNoMenusIsNoOp(): void
    {
        $bar = new MenuBarWidget();
        $bar->open(0);
        $this->assertFalse($bar->isOpen());
        $this->assertExactWidth($bar->render(new RenderContext(30, 5)), 30);
    }

    public function testWheelMovesTheDropdownSelectionSkippingSeparators(): void
    {
        $bar = $this->bar();
        $bar->moveSelection(1); // closed: a no-op

        $bar->open(0);
        $bar->moveSelection(1); // Save → (separator skipped) → Quit
        $bar->handleInput("\r");
        $this->assertSame(['quit'], $this->ran, 'the wheel stepped past the separator to Quit');
    }

    public function testWheelStepHonoursTheDeltaMagnitudeNotJustItsSign(): void
    {
        $bar = $this->bar();
        $bar->open(0); // File: Save selected
        // Two selectable steps in the {Save, Quit} cycle (separator skipped,
        // wrapping): Save → Quit → Save. A sign-only move would stop at Quit
        // after one step; the magnitude must carry both, landing back on Save.
        $bar->moveSelection(2);
        $bar->handleInput("\r");
        $this->assertSame(['save'], $this->ran, 'two steps wrap back to Save — the magnitude moved, not just the sign');
    }
}
