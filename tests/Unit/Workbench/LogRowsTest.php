<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Workbench;

use PHPUnit\Framework\TestCase;
use Porta\Workbench\LogRows;

final class LogRowsTest extends TestCase
{
    public function testMapsEntriesToTimestampedLocationLessRowsNewestFirst(): void
    {
        $rows = LogRows::build([
            ['time' => new \DateTimeImmutable('2026-07-16 10:00:01'), 'text' => 'first'],
            ['time' => new \DateTimeImmutable('2026-07-16 10:00:02'), 'text' => 'second'],
        ]);

        $this->assertSame(
            [
                ['path' => '', 'line' => 0, 'text' => '10:00:02  second'],
                ['path' => '', 'line' => 0, 'text' => '10:00:01  first'],
            ],
            $rows,
            'newest first, empty path marks the rows inert',
        );
    }

    public function testEmptyRingYieldsNoRows(): void
    {
        $this->assertSame([], LogRows::build([]));
    }
}
