<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\TestCase;
use Porta\Editor\DocPopup;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Input\Keybindings;

final class DocPopupTest extends TestCase
{
    private function keys(): Keybindings
    {
        return new Keybindings(['cursor_up' => [Key::UP], 'cursor_down' => [Key::DOWN], 'page_up' => [Key::PAGE_UP], 'page_down' => [Key::PAGE_DOWN]]);
    }

    /** The arrows and pages scroll within what was drawn; any other key is not the popup's. */
    public function testKeysScrollWithinTheDrawnText(): void
    {
        $popup = new DocPopup('text');
        $popup->drawn = ['rect' => [2, 4, 20, 7], 'maxScroll' => 12];

        $this->assertTrue($popup->handleKey("\x1b[B", $this->keys()));
        $this->assertSame(1, $popup->scroll);
        $this->assertTrue($popup->handleKey("\x1b[6~", $this->keys()));
        $this->assertSame(6, $popup->scroll, 'a page is the rows between the borders');
        $popup->handleKey("\x1b[6~", $this->keys());
        $popup->handleKey("\x1b[6~", $this->keys());
        $this->assertSame(12, $popup->scroll, 'held at the end');
        $popup->handleKey("\x1b[5~", $this->keys());
        $this->assertSame(7, $popup->scroll);
        $popup->handleKey("\x1b[A", $this->keys());
        $this->assertSame(6, $popup->scroll);
        $this->assertFalse($popup->handleKey('x', $this->keys()));
        $popup->scrollBy(-50);
        $this->assertSame(0, $popup->scroll, 'and at the top');
    }

    public function testCoversIsTheDrawnRectangle(): void
    {
        $popup = new DocPopup('text');
        $this->assertFalse($popup->covers(3, 5), 'not drawn yet');
        $popup->drawn = ['rect' => [2, 4, 20, 7], 'maxScroll' => 0];

        $this->assertTrue($popup->covers(2, 4));
        $this->assertTrue($popup->covers(8, 23));
        $this->assertFalse($popup->covers(9, 10));
        $this->assertFalse($popup->covers(5, 24));
        $this->assertFalse($popup->covers(1, 10));
        $this->assertFalse($popup->covers(5, 3));
    }
}
