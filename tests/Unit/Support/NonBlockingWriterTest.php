<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Porta\Support\NonBlockingWriter;
use Porta\Tests\Support\DrainsPeer;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\PumpsLoop;
use Revolt\EventLoop;

final class NonBlockingWriterTest extends TestCase
{
    use DrainsPeer;
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

        // "never blocks" is a QUEUE-DEPTH fact, not a clock one. Nothing is
        // reading this socketpair and its buffer is a few KB, so a write that
        // had waited for delivery would leave nothing pending — while a
        // wall-clock assertLessThan(0.1) could only ever fail from a GC pause
        // or a scheduling gap under sixteen workers.
        $writer->write($first);
        $writer->write($second);
        $this->assertGreaterThan(\strlen($first), $writer->pendingBytes(), 'write() never blocks, even past the socket buffer: the tail is still queued');

        // Drain the peer while the loop runs the onWritable watcher.
        $expected = \strlen($first) + \strlen($second);
        $received = $this->drainPeer($read, static fn (string $received): bool => \strlen($received) >= $expected);

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
        if (\is_file($sink)) {
            \unlink($sink);
        }
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
        $this->drainPeer($read, static fn (): bool => 0 === $writer->pendingBytes());
        $this->assertSame(1, $drains, 'the hook fired exactly once for the backlog');

        $writer->write('small');
        $this->assertSame(0, $writer->pendingBytes());
        $this->assertSame(1, $drains, 'an immediately-accepted write never fires the hook');

        $writer->write(\str_repeat('b', times: 524_288));
        $this->drainPeer($read, static fn (): bool => 0 === $writer->pendingBytes());
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
        $this->drainPeer($read, static fn (): bool => 0 === $writer->pendingBytes());

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

    /**
     * A peer that takes a kilobyte a write — a pty does — costs what it is
     * sent, not its square. The writer trimmed its queue after every write,
     * copying all of it: 8 MB through such a peer copied about 32 GB and took
     * seconds (a 9 MB paste, 6s of copying). Linear, it is milliseconds. The
     * bound is a clock, against this suite's doctrine, because the copies are
     * the only thing that changes and nothing else can see them. Linear takes
     * about 50ms and quadratic about 4.3s here, and load slows the second
     * more, so 2s sits forty times above one and half the other.
     */
    public function testAPeerThatTakesAKilobyteAWriteCostsWhatItIsSent(): void
    {
        $peer = self::slowPeer();
        \stream_wrapper_register('porta-slow-peer', $peer::class);
        try {
            $stream = \fopen('porta-slow-peer://x', mode: 'wb');
            \assert(\is_resource($stream), description: 'the wrapper opened');
            $writer = $this->writer($stream, \PHP_INT_MAX);
            $size = 8_000_000;
            $started = \hrtime(as_number: true);

            $writer->write(\str_repeat('x', $size));
            $writer->flushBlocking();

            $seconds = (\hrtime(as_number: true) - $started) / 1e9;
            $this->assertSame($size, $peer::$received, 'every byte reached the peer');
            $this->assertSame(0, $writer->pendingBytes());
            $this->assertLessThan(2.0, $seconds, 'linear in what is sent, not quadratic');
        } finally {
            \stream_wrapper_unregister('porta-slow-peer');
        }
    }

    /**
     * A writer whose queue never empties — a peer always a little behind —
     * must not keep what it already wrote: the written head is dropped a
     * mebibyte at a time, not only when the queue drains.
     */
    public function testWhatIsWrittenIsDroppedWhileTheQueueNeverEmpties(): void
    {
        $peer = self::slowPeer();
        \stream_wrapper_register('porta-slow-peer', $peer::class);
        try {
            $stream = \fopen('porta-slow-peer://x', mode: 'wb');
            \assert(\is_resource($stream), description: 'the wrapper opened');
            $writer = $this->writer($stream, \PHP_INT_MAX);

            $writer->write(\str_repeat('x', times: 3_000_000));
            for ($i = 0; $i < 2_100; ++$i) {
                $writer->write('y'); // each write lets the peer take another kilobyte
            }

            $held = \strlen((string) Probe::get($writer, 'buffer'));
            $this->assertGreaterThan(0, $writer->pendingBytes(), 'the queue never emptied');
            $this->assertLessThan($writer->pendingBytes() + 1_048_576, $held, 'over 2 MB written, and the buffer holds under a mebibyte of it');
            $writer->close(); // the watcher it armed is on a stream no loop can select
        } finally {
            \stream_wrapper_unregister('porta-slow-peer');
        }
    }

    /** A stream wrapper that takes a kilobyte a write, then is full until the next fwrite — as a pty is. */
    private static function slowPeer(): object
    {
        $peer = new class {
            public static int $received = 0;

            /** @var resource|null set by PHP on every wrapper instance */
            public $context;

            public function stream_open(string $path, string $mode, int $options, ?string &$opened): bool
            {
                return true;
            }

            private bool $full = false;

            /** A kilobyte, then full until the next fwrite: PHP keeps calling within one until a write takes nothing. */
            public function stream_write(string $data): int
            {
                $this->full = !$this->full;
                if (!$this->full) {
                    return 0;
                }
                $taken = \min(1024, \strlen($data));
                self::$received += $taken;

                return $taken;
            }

            public function stream_set_option(int $option, int $arg1, ?int $arg2): bool
            {
                return true;
            }

            public function stream_flush(): bool
            {
                return true;
            }
        };
        $peer::$received = 0; // static, and the class is the same one every call

        return $peer;
    }

    /** A peer that takes everything gets it all from one write, chunks or not — not a chunk per writable event. */
    public function testAPeerThatTakesEverythingGetsItAllAtOnce(): void
    {
        $peer = new class {
            public static int $received = 0;

            /** @var resource|null set by PHP on every wrapper instance */
            public $context;

            public function stream_open(string $path, string $mode, int $options, ?string &$opened): bool
            {
                return true;
            }

            public function stream_write(string $data): int
            {
                self::$received += \strlen($data);

                return \strlen($data);
            }

            public function stream_flush(): bool
            {
                return true;
            }
        };
        \stream_wrapper_register('porta-eager-peer', $peer::class);
        try {
            $stream = \fopen('porta-eager-peer://x', mode: 'wb');
            \assert(\is_resource($stream), description: 'the wrapper opened');
            $writer = $this->writer($stream);

            $writer->write(\str_repeat('x', times: 300_000));

            $this->assertSame(0, $writer->pendingBytes(), 'nothing left for a later event');
            $this->assertSame(300_000, $peer::$received);
            $writer->close();
        } finally {
            \stream_wrapper_unregister('porta-eager-peer');
        }
    }
}
