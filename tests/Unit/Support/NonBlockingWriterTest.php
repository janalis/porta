<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Porta\Support\NonBlockingWriter;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\PumpsLoop;
use Revolt\EventLoop;

final class NonBlockingWriterTest extends TestCase
{
    use PumpsLoop;

    /** @var list<string> */
    private array $errors = [];

    /** @return array{0: resource, 1: resource} a connected non-blocking pair [writeSide, readSide] */
    private function pair(): array
    {
        $pair = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, protocol: 0);
        \assert(false !== $pair, description: 'the socket pair was created');
        \stream_set_blocking($pair[0], enable: false);
        \stream_set_blocking($pair[1], enable: false);

        return [$pair[0], $pair[1]];
    }

    private function writer($stream, int $limit = NonBlockingWriter::DEFAULT_LIMIT): NonBlockingWriter
    {
        $this->errors = [];

        return new NonBlockingWriter(
            $stream,
            function (string $reason): void {
                $this->errors[] = $reason;
            },
            $limit,
        );
    }

    /** Run the event loop until $done() is true or the timeout elapses. */
    public function testASmallWriteIsFlushedImmediately(): void
    {
        [$write, $read] = $this->pair();
        $writer = $this->writer($write);

        $writer->write('hello');

        $this->assertSame(0, $writer->pendingBytes(), 'the stream accepted everything at once');
        $this->assertSame('hello', \fread($read, length: 64));
        $this->assertSame([], $this->errors);
        $writer->close();
    }

    public function testALargeWriteReturnsInstantlyAndDrainsInOrder(): void
    {
        [$write, $read] = $this->pair();
        $writer = $this->writer($write);
        $first = \str_repeat('a', times: 262_144);
        $second = \str_repeat('b', times: 262_144);

        $start = \microtime(true);
        $writer->write($first);
        $writer->write($second);
        $this->assertLessThan(0.1, \microtime(true) - $start, 'write() never blocks, even past the socket buffer');
        $this->assertGreaterThan(0, $writer->pendingBytes(), 'the overflow is queued, not lost');

        // Drain the peer while the loop runs the onWritable watcher.
        $received = '';
        $expected = \strlen($first) + \strlen($second);
        $this->await(static function () use (&$received, $read, $expected): bool {
            $chunk = \fread($read, length: 65_536);
            if (\is_string($chunk)) {
                $received .= $chunk;
            }

            return \strlen($received) >= $expected;
        });

        $this->assertSame($first.$second, $received, 'bytes arrive intact and in order');
        $this->assertSame(0, $writer->pendingBytes());
        $this->assertSame([], $this->errors);
        $writer->close();
    }

    public function testAClosedPeerFailsTheWriterOnce(): void
    {
        [$write, $read] = $this->pair();
        $writer = $this->writer($write);
        \fclose($read);

        // The first write may be absorbed by the socket buffer; keep writing
        // until the broken pipe surfaces.
        $this->await(function () use ($writer): bool {
            $writer->write(\str_repeat('x', times: 65_536));

            return [] !== $this->errors;
        });

        $this->assertSame(['write failed'], $this->errors, 'the error fires exactly once');
        $this->assertSame(0, $writer->pendingBytes(), 'the queue was dropped');

        $writer->write('more');
        $this->assertSame(['write failed'], $this->errors, 'a dead writer is a silent no-op');
    }

    public function testQueueOverflowDropsTheConnection(): void
    {
        [$write, $read] = $this->pair();
        $writer = $this->writer($write, limit: 1024);

        $writer->write(\str_repeat('x', times: 2048));

        $this->assertSame(['write queue overflow'], $this->errors);
        $this->assertSame(0, $writer->pendingBytes());
        $writer->write('after');
        $this->assertSame(['write queue overflow'], $this->errors, 'no further error, no further writes');
        \fclose($read);
    }

    public function testFlushBlockingDeliversTheQueueAndRestoresNonBlockingMode(): void
    {
        // The peer drains only after a pause, so flushBlocking genuinely blocks
        // (briefly) instead of deadlocking against a never-reading socketpair.
        // Its echo goes to a FILE — an echo pipe could fill and deadlock too.
        $sink = \tempnam(\sys_get_temp_dir(), prefix: 'porta-nbw');
        \assert(\is_string($sink), description: 'tempnam returned a path');
        $process = \proc_open(
            [\PHP_BINARY, __DIR__.'/../../Support/slow-drain-peer.php'],
            [0 => ['pipe', 'r'], 1 => ['file', $sink, 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
        );
        \assert(\is_resource($process), description: 'the peer process launched');
        \stream_set_blocking($pipes[0], enable: false);
        $writer = $this->writer($pipes[0]);
        // try/finally: an assertion failure mid-test must still tear the
        // writer down BEFORE the local $process handle dies — its destructor
        // closes $pipes[0] behind the armed drain watcher, which poisons the
        // worker's shared event loop for every later test.
        try {
            $payload = \str_repeat('q', times: 262_144); // larger than the ~64KiB pipe → remainder queued
            $writer->write($payload);
            $this->assertGreaterThan(0, $writer->pendingBytes());

            $writer->flushBlocking();

            $this->assertSame(0, $writer->pendingBytes(), 'the whole queue was delivered synchronously');
            $this->assertFalse(\stream_get_meta_data($pipes[0])['blocked'], 'the stream is back in non-blocking mode');
            $this->assertSame([], $this->errors);

            // No orphaned watcher fires after the synchronous flush.
            $ticked = false;
            EventLoop::delay(0.05, static function () use (&$ticked): void {
                $ticked = true;
            });
            $this->await(static function () use (&$ticked): bool {
                return $ticked;
            });
            $this->assertTrue($ticked);
        } finally {
            $writer->close();
            \fclose($pipes[0]); // EOF: the peer's fpassthru finishes
            \proc_close($process); // waits for the child, so the sink is complete
        }
        $this->assertSame($payload, (string) \file_get_contents($sink), 'bytes intact and in order');
        \is_file($sink) && \unlink($sink);
    }

    public function testFlushBlockingIsANoOpWhenIdleClosedOrDead(): void
    {
        [$write, $read] = $this->pair();
        $writer = $this->writer($write);
        $writer->flushBlocking(); // nothing queued
        $this->assertSame([], $this->errors);

        $writer->close();
        $writer->flushBlocking(); // closed
        $this->assertSame([], $this->errors);

        [$write2, $read2] = $this->pair();
        $dead = $this->writer($write2, limit: 8);
        $dead->write('overflowing!'); // kills the writer
        $dead->flushBlocking(); // dead
        $this->assertSame(['write queue overflow'], $this->errors, 'no second error from the dead-writer no-op');
        \fclose($read);
        \fclose($read2);
        \fclose($write2);
    }

    public function testFlushBlockingAgainstAClosedPeerFailsOnce(): void
    {
        [$write, $read] = $this->pair();
        $writer = $this->writer($write);
        $writer->write(\str_repeat('x', times: 524_288)); // remainder queued
        $this->assertGreaterThan(0, $writer->pendingBytes());
        \fclose($read);

        $writer->flushBlocking();

        $this->assertSame(['write failed'], $this->errors);
        $this->assertSame(0, $writer->pendingBytes(), 'the queue was dropped with the failure');
    }

    public function testFlushWithNothingQueuedIsANoOp(): void
    {
        // Defensive guard: the drain watcher can race a same-tick cancel, so an
        // empty-buffer flush must be harmless.
        [$write, $read] = $this->pair();
        $writer = $this->writer($write);

        Probe::invoke($writer, 'flush');

        $this->assertSame(0, $writer->pendingBytes());
        $this->assertSame([], $this->errors);
        \fclose($read);
    }

    public function testCloseMidQueueCancelsTheWatcher(): void
    {
        [$write, $read] = $this->pair();
        $writer = $this->writer($write);
        $writer->write(\str_repeat('x', times: 524_288)); // larger than the socket buffer → watcher armed
        $this->assertGreaterThan(0, $writer->pendingBytes());

        $writer->close();
        $this->assertSame(0, $writer->pendingBytes());

        $writer->write('after close');
        $this->assertSame(0, $writer->pendingBytes(), 'write after close is a no-op');

        // The loop runs clean: no orphaned watcher fires on the closed writer.
        $ticked = false;
        EventLoop::delay(0.05, static function () use (&$ticked): void {
            $ticked = true;
        });
        $this->await(static function () use (&$ticked): bool {
            return $ticked;
        });
        $this->assertTrue($ticked);
        $this->assertSame([], $this->errors);
        \fclose($read);
    }

    public function testTheDrainHookFiresOncePerBacklogDrain(): void
    {
        [$write, $read] = $this->pair();
        $writer = $this->writer($write);
        $drains = 0;
        $writer->onDrain(static function () use (&$drains): void {
            ++$drains;
        });

        $writer->write(\str_repeat('a', times: 524_288)); // past the socket buffer → watcher armed
        $this->assertGreaterThan(0, $writer->pendingBytes());
        $this->await(static function () use ($read, $writer): bool {
            \fread($read, length: 65_536);

            return 0 === $writer->pendingBytes();
        });
        $this->assertSame(1, $drains, 'the hook fired exactly once for the backlog');

        $writer->write('small');
        $this->assertSame(0, $writer->pendingBytes());
        $this->assertSame(1, $drains, 'an immediately-accepted write never fires the hook');

        $writer->write(\str_repeat('b', times: 524_288));
        $this->await(static function () use ($read, $writer): bool {
            \fread($read, length: 65_536);

            return 0 === $writer->pendingBytes();
        });
        $this->assertSame(2, $drains, 'each backlog drain fires once');
        $this->assertSame([], $this->errors);
        $writer->close();
        \fclose($read);
    }

    public function testClearingTheDrainHookSilencesIt(): void
    {
        [$write, $read] = $this->pair();
        $writer = $this->writer($write);
        $drains = 0;
        $writer->onDrain(static function () use (&$drains): void {
            ++$drains;
        });
        $writer->onDrain(null);

        $writer->write(\str_repeat('a', times: 524_288));
        $this->await(static function () use ($read, $writer): bool {
            \fread($read, length: 65_536);

            return 0 === $writer->pendingBytes();
        });

        $this->assertSame(0, $drains, 'a cleared hook stays silent');
        $writer->close();
        \fclose($read);
    }

    public function testAFailedWriterNeverFiresTheDrainHook(): void
    {
        [$write, $read] = $this->pair();
        $writer = $this->writer($write);
        $drains = 0;
        $writer->onDrain(static function () use (&$drains): void {
            ++$drains;
        });

        $writer->write(\str_repeat('x', times: 524_288)); // watcher armed
        $this->assertGreaterThan(0, $writer->pendingBytes());
        \fclose($read); // the peer dies with the backlog queued

        $this->await(fn (): bool => [] !== $this->errors);

        $this->assertSame(['write failed'], $this->errors);
        $this->assertSame(0, $drains, 'failure drops the queue without a drain signal');
    }
}
