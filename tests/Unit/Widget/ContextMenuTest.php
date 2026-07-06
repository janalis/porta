<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget;

use PHPUnit\Framework\TestCase;
use Porta\Ui\MenuItem;
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
                function () use (&$ran): void {
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
        $menu->onNavigate(function () use (&$hits): void {
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
        $menu->onClose(function () use (&$closed): void {
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
}
