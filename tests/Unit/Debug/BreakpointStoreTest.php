<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Debug;

use PHPUnit\Framework\TestCase;
use Porta\Debug\BreakpointStore;

final class BreakpointStoreTest extends TestCase
{
    public function testToggleSetsAndClearsPerFile(): void
    {
        $store = new BreakpointStore();

        $this->assertTrue($store->toggle('/p/a.php', 9));
        $this->assertTrue($store->toggle('/p/a.php', 3));
        $this->assertTrue($store->toggle('/p/b.php', 3));
        $this->assertSame([3, 9], $store->linesFor('/p/a.php'), 'ascending order');
        $this->assertSame([3], $store->linesFor('/p/b.php'), 'files are isolated');

        $this->assertFalse($store->toggle('/p/a.php', 9), 'second toggle clears');
        $this->assertSame([3], $store->linesFor('/p/a.php'));
        $this->assertSame([], $store->linesFor('/p/unknown.php'));
    }

    public function testAllDropsEmptiedFilesAndClearEmptiesEverything(): void
    {
        $store = new BreakpointStore();
        $store->toggle('/p/a.php', 1);
        $store->toggle('/p/b.php', 2);
        $store->toggle('/p/b.php', 2); // toggles off → the file disappears

        $this->assertSame(['/p/a.php' => [1]], $store->all());

        $store->clear();
        $this->assertSame([], $store->all());
    }
}
