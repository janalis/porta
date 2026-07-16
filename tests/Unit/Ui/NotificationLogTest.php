<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;
use Porta\Ui\NotificationLog;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class NotificationLogTest extends TestCase
{
    public function testAppendsWithTimestampsOldestFirst(): void
    {
        $clock = new MockClock('2026-07-13 10:00:00');
        $log = new NotificationLog($clock, new EventDispatcher());

        $log->append('first');
        $clock->modify('+5 seconds');
        $log->append('second');

        $entries = $log->entries();
        $this->assertSame(['first', 'second'], array_column($entries, 'text'));
        $this->assertSame('10:00:00', $entries[0]['time']->format('H:i:s'));
        $this->assertSame('10:00:05', $entries[1]['time']->format('H:i:s'));
    }

    public function testCapsAtOneHundredEntriesDroppingTheOldest(): void
    {
        $log = new NotificationLog(new MockClock(), new EventDispatcher());
        for ($i = 1; $i <= 105; ++$i) {
            $log->append("msg {$i}");
        }

        $entries = $log->entries();
        $this->assertCount(100, $entries);
        $this->assertSame('msg 6', $entries[0]['text'], 'the oldest five were dropped');
        $this->assertSame('msg 105', $entries[99]['text']);
    }
}
