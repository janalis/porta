<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Lsp;

use PHPUnit\Framework\TestCase;
use Porta\Event\LspLogAppended;
use Porta\Lsp\LspLog;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class LspLogTest extends TestCase
{
    public function testSplitsChunksIntoTrimmedNonEmptyLines(): void
    {
        $log = new LspLog(new MockClock('2026-07-14 10:00:00'), new EventDispatcher());
        // A blank line in the MIDDLE of the chunk: skipping it must not abort
        // the loop before "second line" (which follows it) is reached.
        $log->append("first line\n\n  second line  \n");
        $log->append('partial');

        $this->assertSame(['first line', 'second line', 'partial'], \array_column($log->entries(), 'text'));
        $this->assertSame('10:00:00', $log->entries()[0]['time']->format('H:i:s'));
    }

    public function testCapsTheHistoryAtTheNewestEntries(): void
    {
        $log = new LspLog(new MockClock(), new EventDispatcher());
        for ($i = 1; $i <= 205; ++$i) {
            $log->append("line {$i}");
        }

        $entries = $log->entries();
        $this->assertCount(200, $entries);
        $this->assertSame('line 6', $entries[0]['text'], 'the oldest lines fell off');
        $this->assertSame('line 205', $entries[199]['text']);
    }

    public function testTruncatesOverlongLines(): void
    {
        $log = new LspLog(new MockClock(), new EventDispatcher());
        $log->append(\str_repeat('x', times: 600));

        $this->assertSame(500, \mb_strlen($log->entries()[0]['text']));
    }

    public function testAnnouncesGrowthButNotBlankChunks(): void
    {
        $appended = 0;
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(LspLogAppended::class, static function () use (&$appended): void {
            ++$appended;
        });
        $log = new LspLog(new MockClock(), $dispatcher);

        $log->append("one\ntwo\n");
        $this->assertSame(1, $appended, 'one event per chunk, however many lines it adds');

        $log->append("  \n\n");
        $this->assertSame(1, $appended, 'an all-blank chunk changes nothing and stays silent');
    }
}
