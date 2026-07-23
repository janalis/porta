<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget;

use PHPUnit\Framework\TestCase;
use Porta\Ui\Menu\MenuItem;
use Porta\Widget\ContextMenu;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;

final class ContextMenuTest extends TestCase
{
    /** @var list<string> */
    private array $ran = [];

    /** @return list<MenuItem> */
    private function items(): array
    {
        $this->ran = [];

        return [
            new MenuItem('Cut', 'Ctrl+X', function (): void {
                $this->ran[] = 'cut';
            }),
            MenuItem::separator(),
            new MenuItem('Copy', 'Ctrl+C', function (): void {
                $this->ran[] = 'copy';
            }),
        ];
    }

    private function menu(): ContextMenu
    {
        $menu = new ContextMenu();
        $menu->open($this->items());

        return $menu;
    }

    public function testRendersNoLayoutRows(): void
    {
        $menu = $this->menu();
        $this->assertTrue($menu->isOpen());
        $this->assertSame([], $menu->render(new RenderContext(80, 24)), 'floats via the overlay; occupies no layout row');
    }

    public function testSegmentsCarryLabelsShortcutsAndSeparators(): void
    {
        $menu = $this->menu();
        $width = $menu->panelWidth();
        $segments = $menu->segments($width);

        $this->assertSame(3, $menu->itemCount());
        $this->assertCount(3, $segments);
        $this->assertFalse($segments[0]['separator']);
        $this->assertTrue($segments[1]['separator']);
        foreach ($segments as $segment) {
            $this->assertSame($width, AnsiUtils::visibleWidth($segment['segment']), 'each row is exactly panelWidth wide');
        }
        $plain = AnsiUtils::stripAnsiCodes(implode("\n", array_column($segments, 'segment')));
        $this->assertStringContainsString('Cut', $plain);
        $this->assertStringContainsString('Ctrl+X', $plain);
        $this->assertStringContainsString('Copy', $plain);
    }

    public function testGeometryEmptyWhenClosed(): void
    {
        $menu = new ContextMenu();
        $this->assertSame(0, $menu->panelWidth());
        $this->assertSame([], $menu->segments(20));
        $this->assertSame(0, $menu->itemCount());
    }

    public function testOpenWithNoItemsIsNoOp(): void
    {
        $menu = new ContextMenu();
        $menu->open([]);
        $this->assertFalse($menu->isOpen());
    }

    public function testDownSkipsSeparatorSelectRunsAndCloses(): void
    {
        $menu = $this->menu(); // selected = Cut (0)
        $menu->handleInput("\x1b[B"); // down → skips separator → Copy (2)
        $menu->handleInput("\r"); // enter

        $this->assertSame(['copy'], $this->ran);
        $this->assertFalse($menu->isOpen(), 'closes after select');
    }

    public function testDisabledItemRunsNothingButStillCloses(): void
    {
        $ran = false;
        $menu = new ContextMenu();
        $menu->open([
            new MenuItem(
                'Cut',
                'Ctrl+X',
                static function () use (&$ran): void {
                    $ran = true;
                },
                false,
                static fn (): bool => false,
            ),
            new MenuItem('Copy', 'Ctrl+C', static fn () => null),
        ]);

        $menu->runItem(0); // the disabled Cut
        $this->assertFalse($ran, 'a disabled item is not run');
        $this->assertFalse($menu->isOpen(), 'the menu still closes');
    }

    public function testHoverItemHighlightsSelectableRowsOnly(): void
    {
        $menu = new ContextMenu();
        $navigations = 0;
        $menu->onNavigate(static function () use (&$navigations): void {
            ++$navigations;
        });
        $ran = [];
        $menu->open([
            new MenuItem('Cut', 'Ctrl+X', static fn () => null), // 0: selectable (selected)
            MenuItem::separator(), // 1: separator
            new MenuItem('Paste', 'Ctrl+V', static fn () => null, false, static fn (): bool => false), // 2: disabled
            new MenuItem('Copy', 'Ctrl+C', static function () use (&$ran): void {
                $ran[] = 'copy';
            }), // 3: selectable
        ]);
        $navigations = 0; // ignore the open() navigation

        $this->assertFalse($menu->hoverItem(1), 'a separator cannot be highlighted');
        $this->assertFalse($menu->hoverItem(2), 'a disabled item cannot be highlighted');
        $this->assertFalse($menu->hoverItem(9), 'an out-of-range row is a no-op');
        $this->assertFalse($menu->hoverItem(0), 'the already-selected row is a no-op');
        $this->assertSame(0, $navigations, 'no re-placement for a no-op hover');

        $this->assertTrue($menu->hoverItem(3), 'hovering a selectable row highlights it');
        $this->assertSame(1, $navigations, 'the highlight re-places the overlay');

        $menu->handleInput("\r"); // Enter runs the hovered row
        $this->assertSame(['copy'], $ran);
    }

    public function testUpWrapsPastSeparator(): void
    {
        $menu = $this->menu(); // Cut (0)
        $menu->handleInput("\x1b[A"); // up → wraps to Copy (2)
        $menu->handleInput("\r");

        $this->assertSame(['copy'], $this->ran);
    }

    public function testEscapeClosesWithoutRunning(): void
    {
        $menu = $this->menu();
        $menu->handleInput("\x1b");
        $this->assertFalse($menu->isOpen());
        $this->assertSame([], $this->ran);
    }

    public function testUnhandledKeyIsSwallowed(): void
    {
        $menu = $this->menu();
        $menu->handleInput('x'); // not a navigation key
        $this->assertTrue($menu->isOpen(), 'other keys neither run nor close');
        $this->assertSame([], $this->ran);
    }

    public function testRunItemInvokesAndCloses(): void
    {
        $menu = $this->menu();
        $menu->runItem(2); // Copy
        $this->assertSame(['copy'], $this->ran);
        $this->assertFalse($menu->isOpen());
    }

    public function testRunItemOnSeparatorRunsNothingButCloses(): void
    {
        $menu = $this->menu();
        $menu->runItem(1); // separator
        $this->assertSame([], $this->ran);
        $this->assertFalse($menu->isOpen());
    }

    public function testOnNavigateFiresOnOpenAndMovement(): void
    {
        $menu = new ContextMenu();
        $hits = 0;
        $menu->onNavigate(static function () use (&$hits): void {
            ++$hits;
        });

        $menu->open($this->items()); // fires
        $menu->handleInput("\x1b[B"); // down → fires
        $menu->handleInput("\x1b[A"); // up → fires

        $this->assertSame(3, $hits);
    }

    public function testOnCloseFires(): void
    {
        $menu = $this->menu();
        $closed = false;
        $menu->onClose(static function () use (&$closed): void {
            $closed = true;
        });
        $menu->handleInput("\x1b");
        $this->assertTrue($closed);
    }

    public function testInputIgnoredWhenClosed(): void
    {
        $menu = new ContextMenu();
        $menu->handleInput("\r");
        $this->assertFalse($menu->isOpen());
    }

    public function testAllSeparatorMenuNavigatesSafely(): void
    {
        $menu = new ContextMenu();
        $menu->open([MenuItem::separator(), MenuItem::separator()]);
        $menu->handleInput("\x1b[B");
        $menu->handleInput("\x1b[A");
        $menu->handleInput("\r"); // nothing to run
        $this->assertFalse($menu->isOpen(), 'select on a separator still closes');
    }

    public function testWheelMovesTheSelectionSkippingSeparators(): void
    {
        $closed = new ContextMenu();
        $closed->moveSelection(1); // closed: a no-op

        $menu = $this->menu();
        $menu->moveSelection(1); // steps past the separator to the next item
        $menu->handleInput("\r");
        $this->assertNotSame([], $this->ran, 'the wheel-selected item ran on Enter');
    }

    public function testWheelStepHonoursTheDeltaMagnitudeNotJustItsSign(): void
    {
        $menu = $this->menu(); // Cut selected
        // {Cut, Copy} with the separator skipped, wrapping: Cut → Copy → Cut. A
        // sign-only move stops at Copy after one step; the magnitude carries both.
        $menu->moveSelection(2);
        $menu->handleInput("\r");
        $this->assertSame(['cut'], $this->ran, 'two steps wrap back to Cut — the magnitude moved, not just the sign');
    }
}
