<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\TestCase;
use Porta\Editor\DecorationStore;

/**
 * The debug values' own slot in the decoration store.
 *
 * Separate from the line annotations a plugin writes, because that map REPLACES
 * — two writers on one slot would clobber each other every time the debugger
 * stepped.
 */
final class DecorationStoreDebugValuesTest extends TestCase
{
    public function testSettingThemASKSForARepaint(): void
    {
        // They arrive two async round-trips after the frame that drew the stop,
        // so a setter that only stored would leave them invisible until
        // something else repainted. Asserted here rather than through a render:
        // the widget composes rows fresh every frame, so a render can never
        // tell a missing invalidation from a present one.
        $repaints = 0;
        $store = new DecorationStore(static function () use (&$repaints): void {
            ++$repaints;
        });

        $store->setDebugValues([1 => '$count = 41']);

        $this->assertSame(1, $repaints);
        $this->assertSame([1 => '$count = 41'], $store->debugValues);
    }

    public function testANewBufferBringsNoneOfThePreviousFilesValues(): void
    {
        // The stopped frame is in one file. Switching to another must not show
        // its values against whatever happens to be on those line numbers.
        $store = new DecorationStore(static function (): void {});
        $store->setDebugValues([1 => '$count = 41']);

        $store->resetForBuffer([]);

        $this->assertSame([], $store->debugValues);
    }
}
