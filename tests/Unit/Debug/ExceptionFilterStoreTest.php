<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Debug;

use PHPUnit\Framework\TestCase;
use Porta\Debug\ExceptionFilterStore;

final class ExceptionFilterStoreTest extends TestCase
{
    private const array ADVERTISED = [
        ['filter' => 'warning', 'label' => 'Warnings', 'default' => false],
        ['filter' => 'exception', 'label' => 'Uncaught Exceptions', 'default' => true],
    ];

    public function testNullSelectionHonorsAdapterDefaults(): void
    {
        $store = new ExceptionFilterStore();
        $this->assertNull($store->selection());
        $this->assertSame(['exception'], $store->enabledFor(self::ADVERTISED));
    }

    public function testToggleMaterializesFromDefaultsThenFlips(): void
    {
        $store = new ExceptionFilterStore();

        $store->toggle('warning', self::ADVERTISED);
        $this->assertSame(['exception', 'warning'], $store->enabledFor(self::ADVERTISED));
        $this->assertSame(['exception', 'warning'], $store->selection(), 'the selection materialized');

        $store->toggle('exception', self::ADVERTISED);
        $this->assertSame(['warning'], $store->enabledFor(self::ADVERTISED));

        $store->toggle('warning', self::ADVERTISED);
        $this->assertSame([], $store->enabledFor(self::ADVERTISED), 'everything off is a valid state');
        $this->assertSame([], $store->selection(), 'explicitly empty, not null');
    }

    public function testSelectionIntersectsWithWhatTheAdapterAdvertises(): void
    {
        $store = new ExceptionFilterStore();
        $store->setSelection(['exception', 'vanished-filter']);
        $this->assertSame(['exception'], $store->enabledFor(self::ADVERTISED), 'ids the adapter no longer advertises are dropped');

        $store->setSelection(null);
        $this->assertSame(['exception'], $store->enabledFor(self::ADVERTISED), 'null returns to defaults');
    }
}
