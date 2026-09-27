<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Overlay\State;

use PHPUnit\Framework\TestCase;
use Porta\Overlay\State\LiveRows;

final class LiveRowsTest extends TestCase
{
    /** @return list<array{label: string, hint: string, value: string, disabled?: bool}> */
    // @mago-expect lint:no-boolean-flag-parameter -- each bool IS a row's greyed state, the fixture's whole content.
    private static function rows(bool ...$disabled): array
    {
        return \array_map(static fn (bool $grey): array => ['label' => '', 'hint' => '', 'value' => '', 'disabled' => $grey], $disabled);
    }

    public function testFromWrapsPastGreyRowsInTheDirectionOfTravel(): void
    {
        $rows = self::rows(true, false, true, false);

        $this->assertSame(1, LiveRows::from($rows, 0, 1));
        $this->assertSame(3, LiveRows::from($rows, 0, -1), 'backwards round the start');
        $this->assertSame(3, LiveRows::from($rows, 3, 1), 'a live row stays');
        $this->assertSame(2, LiveRows::from(self::rows(true, true, true), 2, 1), 'all grey: where it was');
    }

    public function testWithinStopsShortOfTheEndsAndFallsBack(): void
    {
        $rows = self::rows(true, false, false, true);

        $this->assertSame(1, LiveRows::within($rows, 0, -1), 'paging up onto a grey top: the first live row below it');
        $this->assertSame(2, LiveRows::within($rows, 3, 1), 'paging down onto a grey end: the last live row above it');
        $this->assertSame(2, LiveRows::within($rows, 2, 1));
        $this->assertSame(1, LiveRows::within(self::rows(true, true), 1, 1), 'all grey: where it was');
    }

    /** A note is read, like a disabled row: the selection walks past it as well. */
    public function testANoteIsPassedOverLikeAGreyRow(): void
    {
        $rows = [
            ['label' => 'boom', 'hint' => '', 'value' => '', 'note' => true],
            ['label' => 'fix', 'hint' => '', 'value' => '0'],
        ];

        $this->assertSame(1, LiveRows::from($rows, 0, 1));
        $this->assertSame(1, LiveRows::within($rows, 0, -1));
        $this->assertTrue(LiveRows::readOnly($rows[0]));
        $this->assertFalse(LiveRows::readOnly($rows[1]));
        $this->assertTrue(LiveRows::readOnly(['label' => '', 'hint' => '', 'value' => '', 'disabled' => true]));
        $this->assertFalse(LiveRows::readOnly(['label' => '', 'hint' => '', 'value' => '', 'disabled' => false, 'note' => false]));
    }
}
