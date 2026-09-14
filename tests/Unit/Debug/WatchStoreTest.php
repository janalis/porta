<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Debug;

use PHPUnit\Framework\TestCase;
use Porta\Debug\WatchStore;

final class WatchStoreTest extends TestCase
{
    public function testAddAppendsInInsertionOrder(): void
    {
        $store = new WatchStore();
        $store->add('$a');
        $store->add('$b');

        $this->assertSame(['$a', '$b'], $store->expressions);
    }

    public function testRemoveDropsOnlyTheIndexedExpression(): void
    {
        $store = new WatchStore();
        $store->add('$a');
        $store->add('$b');
        $store->add('$c');

        $store->remove(1);

        $this->assertSame(['$a', '$c'], $store->expressions, 'only $b, and only one item, is removed');
    }

    public function testRemoveAnEmptyStringExpressionStillRemoves(): void
    {
        // isset()-based checks treat '' as present (not null), so a naive
        // rewrite of the presence guard can accidentally treat '' as falsy
        // and skip the removal.
        $store = new WatchStore();
        $store->add('');
        $store->add('$b');

        $store->remove(0);

        $this->assertSame(['$b'], $store->expressions);
    }

    public function testRemoveOutOfRangeIsANoOp(): void
    {
        $store = new WatchStore();
        $store->add('$a');

        $store->remove(5);

        $this->assertSame(['$a'], $store->expressions);
    }
}
