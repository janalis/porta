<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Filesystem;

use PHPUnit\Framework\TestCase;
use Porta\Filesystem\ChangeQueue;

/** One change at a time, in order, each waiting for the server's answer to the last. */
final class ChangeQueueTest extends TestCase
{
    public function testChangesRunOneAtATimeInOrder(): void
    {
        $queue = new ChangeQueue();
        $ran = [];
        /** @var list<\Closure(): void> $nexts */
        $nexts = [];
        foreach (['a', 'b', 'a'] as $i => $path) {
            $queue->add($path, static function (\Closure $next) use (&$ran, &$nexts, $i): void {
                $ran[] = $i;
                $nexts[] = $next;
            });
        }

        $this->assertSame([0], $ran, 'the second waits for the first to be answered');
        $this->assertSame(3, $queue->pending());
        $this->assertTrue($queue->touches('a'));
        $this->assertTrue($queue->touches('b'));
        $this->assertFalse($queue->touches('c'));

        $nexts[0]();
        $nexts[0](); // answered twice: ignored
        $this->assertSame([0, 1], $ran);
        $this->assertSame(2, $queue->pending());
        $this->assertTrue($queue->touches('a'), 'the third still touches it');

        $nexts[1]();
        $this->assertFalse($queue->touches('b'));
        $nexts[2]();

        $this->assertSame([0, 1, 2], $ran);
        $this->assertSame(0, $queue->pending());
        $this->assertFalse($queue->touches('a'));
    }

    public function testAChangeAnsweredAtOnceLetsTheNextRun(): void
    {
        $queue = new ChangeQueue();
        $ran = 0;

        $queue->add('a', static function (\Closure $next) use (&$ran): void {
            ++$ran;
            $next();
        });
        $queue->add('a', static function (\Closure $next) use (&$ran): void {
            ++$ran;
            $next();
        });

        $this->assertSame(2, $ran);
        $this->assertSame(0, $queue->pending());
    }
}
