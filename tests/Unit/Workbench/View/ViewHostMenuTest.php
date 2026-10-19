<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Workbench\View;

use PHPUnit\Framework\TestCase;
use Porta\Tests\Support\BootsIde;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\Services;
use Porta\Widget\ContextMenu;
use Porta\Workbench\Menu\MenuItem;
use Porta\Workbench\View\ViewHost;

/**
 * A view's toolbar drops its own menus: a left press on a control opens the
 * context menu at the cell the view names, with the view's items.
 */
final class ViewHostMenuTest extends TestCase
{
    use BootsIde;

    protected function setUp(): void
    {
        $this->bootIde(['composer.json' => '{}']);
    }

    public function testAViewOpensItsItemsAsTheContextMenu(): void
    {
        $ran = [];
        $items = [
            new MenuItem('Unified', '', static function () use (&$ran): void {
                $ran[] = 'unified';
            }),
            new MenuItem('Split', '', static function () use (&$ran): void {
                $ran[] = 'split';
            }),
        ];
        $menu = Services::resolve($this->ide, ContextMenu::class);
        $this->assertFalse($menu->isOpen());

        Services::resolve($this->ide, ViewHost::class)->openMenu($items, 4, 10);

        $this->assertTrue($menu->isOpen());
        $this->assertSame(['Unified', 'Split'], \array_map(static fn (MenuItem $item): string => $item->label, Probe::get($menu, 'items')));
        $menu->runItem(1);
        $this->assertSame(['split'], $ran, 'a pick runs the view\'s own closure');
    }
}
