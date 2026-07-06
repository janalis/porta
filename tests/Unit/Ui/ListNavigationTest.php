<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;
use Porta\Ui\ListNavigation;
use Porta\Ui\ListSelection;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Input\Keybindings;

final class ListNavigationTest extends TestCase
{
    private function keybindings(): Keybindings
    {
        return new Keybindings([
            'move_up' => [Key::UP],
            'move_down' => [Key::DOWN],
            'page_up' => [Key::PAGE_UP],
            'page_down' => [Key::PAGE_DOWN],
        ]);
    }

    public function testMovesTheSelectionAndReportsHandled(): void
    {
        $list = new ListSelection();
        $kb = $this->keybindings();

        $this->assertTrue(ListNavigation::handleKey("\x1b[B", $kb, $list, 3));
        $this->assertSame(1, $list->selected());
        $this->assertTrue(ListNavigation::handleKey("\x1b[A", $kb, $list, 3));
        $this->assertSame(0, $list->selected());
    }

    public function testPageKeysStepByThePageSize(): void
    {
        $list = new ListSelection();
        $kb = $this->keybindings();

        $this->assertTrue(ListNavigation::handleKey("\x1b[6~", $kb, $list, 20, page: 5));
        $this->assertSame(5, $list->selected());
        $this->assertTrue(ListNavigation::handleKey("\x1b[5~", $kb, $list, 20, page: 5));
        $this->assertSame(0, $list->selected());
    }

    public function testPageKeysAreUnhandledWithoutAPageSize(): void
    {
        $list = new ListSelection();

        $this->assertFalse(ListNavigation::handleKey("\x1b[6~", $this->keybindings(), $list, 20));
        $this->assertSame(0, $list->selected());
    }

    public function testUnrelatedKeysAreUnhandled(): void
    {
        $list = new ListSelection();

        $this->assertFalse(ListNavigation::handleKey('x', $this->keybindings(), $list, 3));
        $this->assertSame(0, $list->selected());
    }
}
