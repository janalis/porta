<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;
use Porta\Tests\Support\PumpsLoop;
use Porta\Ui\NonBlockingTerminal;
use Revolt\EventLoop;
use Symfony\Component\Tui\Terminal\TerminalInterface;

final class NonBlockingTerminalTest extends TestCase
{
    use PumpsLoop;

    /** @return array{0: resource, 1: resource} [decoratorStream, peerReadSide] */
    private function pair(): array
    {
        $pair = stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, protocol: 0);
        \assert(false !== $pair, description: 'stream_socket_pair created a socket pair');
        stream_set_blocking($pair[1], enable: false);

        return [$pair[0], $pair[1]];
    }

    /** A recording inner terminal; $calls collects method traces. */
    private function inner(array &$calls): TerminalInterface
    {
        return new class($calls) implements TerminalInterface {
            /** @param list<string> $calls */
            public function __construct(
                private array &$calls,
            ) {}

            public function start(callable $onInput, callable $onResize, callable $onKittyProtocolActivated): void
            {
                $this->calls[] = 'start';
                $onInput('probe'); // prove the callbacks were passed through verbatim
            }

            public function stop(): void
            {
                $this->calls[] = 'stop';
            }

            public function write(string $data): void
            {
                $this->calls[] = 'write:'.$data;
            }

            public function getColumns(): int
            {
                return 123;
            }

            public function getRows(): int
            {
                return 45;
            }

            public function isKittyProtocolActive(): bool
            {
                return true;
            }

            public function moveBy(int $lines): void
            {
                $this->calls[] = 'moveBy';
            }

            public function hideCursor(): void
            {
                $this->calls[] = 'hideCursor';
            }

            public function showCursor(): void
            {
                $this->calls[] = 'showCursor';
            }

            public function clearLine(): void
            {
                $this->calls[] = 'clearLine';
            }

            public function clearFromCursor(): void
            {
                $this->calls[] = 'clearFromCursor';
            }

            public function clearScreen(): void
            {
                $this->calls[] = 'clearScreen';
            }

            public function setTitle(string $title): void
            {
                $this->calls[] = 'setTitle';
            }

            public function bell(): void
            {
                $this->calls[] = 'bell';
            }

            public function isVirtual(): bool
            {
                return false;
            }
        };
    }

    private function start(NonBlockingTerminal $terminal, array &$received): void
    {
        $terminal->start(
            static function (string $data) use (&$received): void {
                $received[] = $data;
            },
            static function (): void {},
            static function (): void {},
        );
    }

    /** Run the event loop until $done() is true or the timeout elapses. */
    public function testEveryWritingMethodEmitsVendorIdenticalBytesInOrderThroughTheQueue(): void
    {
        [$stream, $peer] = $this->pair();
        $calls = [];
        $received = [];
        $terminal = new NonBlockingTerminal($this->inner($calls), $stream);
        $this->start($terminal, $received);

        $terminal->write('frame');
        $terminal->hideCursor();
        $terminal->showCursor();
        $terminal->moveBy(3);
        $terminal->moveBy(-2);
        $terminal->moveBy(0); // emits nothing
        $terminal->clearLine();
        $terminal->clearFromCursor();
        $terminal->clearScreen();
        $terminal->setTitle("ti\x01tle\x7f"); // control chars stripped like the vendor
        $terminal->bell();

        $expected = "frame\x1b[?25l\x1b[?25h\x1b[3B\x1b[2A\x1b[2K\x1b[0J\x1b[2J\x1b[H\x1b]0;title\x07\x07";
        $this->assertSame($expected, fread($peer, length: 8192), 'byte-identical to the vendor terminal, in call order');
        $this->assertSame(['start'], $calls, 'no writing method leaked to the inner terminal after start()');
        $this->assertSame(['probe'], $received, 'the onInput callback was passed through verbatim');
        $terminal->stop();
    }

    public function testNonWritingMethodsDelegate(): void
    {
        [$stream, $peer] = $this->pair();
        $calls = [];
        $terminal = new NonBlockingTerminal($this->inner($calls), $stream);

        $this->assertSame(123, $terminal->getColumns());
        $this->assertSame(45, $terminal->getRows());
        $this->assertTrue($terminal->isKittyProtocolActive());
        $this->assertFalse($terminal->isVirtual());
        fclose($peer);
    }

    public function testStartFlipsToNonBlockingOnlyAfterTheInnerBooted(): void
    {
        [$stream, $peer] = $this->pair();
        $blockedDuringStart = null;
        $inner = new class($stream, $blockedDuringStart) implements TerminalInterface {
            public function __construct(
                private $stream,
                private ?bool &$blockedDuringStart,
            ) {}

            public function start(callable $onInput, callable $onResize, callable $onKittyProtocolActivated): void
            {
                // The inner terminal's own boot writes must still run blocking.
                $this->blockedDuringStart = stream_get_meta_data($this->stream)['blocked'];
            }

            public function stop(): void {}

            public function write(string $data): void {}

            public function getColumns(): int
            {
                return 0;
            }

            public function getRows(): int
            {
                return 0;
            }

            public function isKittyProtocolActive(): bool
            {
                return false;
            }

            public function moveBy(int $lines): void {}

            public function hideCursor(): void {}

            public function showCursor(): void {}

            public function clearLine(): void {}

            public function clearFromCursor(): void {}

            public function clearScreen(): void {}

            public function setTitle(string $title): void {}

            public function bell(): void {}

            public function isVirtual(): bool
            {
                return false;
            }
        };
        $terminal = new NonBlockingTerminal($inner, $stream);

        $terminal->start(static function (): void {}, static function (): void {}, static function (): void {});

        $this->assertTrue($blockedDuringStart, 'stream still blocking while the inner terminal boots');
        $this->assertFalse(stream_get_meta_data($stream)['blocked'], 'non-blocking once the queue owns the stream');
        $terminal->stop();
        $this->assertTrue(stream_get_meta_data($stream)['blocked'], 'blocking mode restored on stop');
        fclose($peer);
    }

    public function testWritesOutsideTheStartStopWindowPassThroughToTheInner(): void
    {
        [$stream, $peer] = $this->pair();
        $calls = [];
        $received = [];
        $terminal = new NonBlockingTerminal($this->inner($calls), $stream);

        $terminal->write('boot-bytes'); // before start(): the alt-screen bracket
        $this->assertSame(['write:boot-bytes'], $calls);

        $this->start($terminal, $received);
        $terminal->write('frame');
        $terminal->stop();
        $terminal->write('teardown-bytes'); // after stop(): the alt-screen exit

        $this->assertContains('write:teardown-bytes', $calls, 'post-stop writes reach the tty via the inner terminal');
        $this->assertSame('frame', fread($peer, length: 8192), 'queued bytes were flushed by stop()');
        $terminal->stop(); // double-stop is harmless
        $this->assertSame(2, \count(array_filter($calls, static fn (string $c): bool => 'stop' === $c)));
        fclose($peer);
    }

    public function testBackPressureNeverBlocksAndDrainsInOrder(): void
    {
        [$stream, $peer] = $this->pair();
        $calls = [];
        $received = [];
        $terminal = new NonBlockingTerminal($this->inner($calls), $stream);
        $this->start($terminal, $received);
        $first = str_repeat('a', times: 524_288);
        $second = str_repeat('b', times: 524_288);

        $start = microtime(true);
        $terminal->write($first);
        $terminal->write($second);
        $this->assertLessThan(0.1, microtime(true) - $start, 'a stalled terminal cannot park the loop in a write');

        $drained = '';
        $expected = \strlen($first) + \strlen($second);
        $this->await(static function () use (&$drained, $peer, $expected): bool {
            $chunk = fread($peer, length: 65_536);
            if (\is_string($chunk)) {
                $drained .= $chunk;
            }

            return \strlen($drained) >= $expected;
        });

        $this->assertSame($first.$second, $drained, 'frames arrive intact and in order once the terminal drains');
        $terminal->stop();
        fclose($peer);
    }

    public function testOverflowFallsBackToABlockingFlushWithoutDroppingBytes(): void
    {
        // A peer that drains only after a pause: the fallback blocks briefly
        // instead of deadlocking, and every byte still arrives in order. Its
        // echo goes to a FILE — an echo pipe could fill and deadlock too.
        $sink = tempnam(sys_get_temp_dir(), prefix: 'porta-nbt');
        \assert(\is_string($sink), description: 'tempnam returned a sink path');
        $process = proc_open(
            [\PHP_BINARY, __DIR__.'/../../Support/slow-drain-peer.php'],
            [0 => ['pipe', 'r'], 1 => ['file', $sink, 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
        );
        \assert(\is_resource($process), description: 'proc_open started the slow-drain peer');
        $calls = [];
        $received = [];
        $terminal = new NonBlockingTerminal($this->inner($calls), $pipes[0], limit: 65_536);
        $this->start($terminal, $received);

        $first = str_repeat('x', times: 131_072); // fills the ~64KiB pipe, remainder queued past the limit
        $second = str_repeat('y', times: 131_072); // trips the pre-check → flushBlocking against the slow peer
        $terminal->write($first);
        $terminal->write($second);
        $terminal->stop(); // flushes whatever is still queued

        fclose($pipes[0]); // EOF ends the peer's fpassthru
        proc_close($process); // waits for the child, so the sink is complete
        $this->assertSame($first.$second, (string) file_get_contents($sink), 'nothing was dropped across the blocking fallback');
        is_file($sink) && unlink($sink);
    }

    public function testPendingBytesReportsTheBacklogLifecycle(): void
    {
        [$stream, $peer] = $this->pair();
        $calls = [];
        $received = [];
        $terminal = new NonBlockingTerminal($this->inner($calls), $stream);
        $this->assertSame(0, $terminal->pendingBytes(), 'no writer before start()');

        $this->start($terminal, $received);
        $terminal->write(str_repeat('x', times: 524_288)); // past the socket buffer
        $this->assertGreaterThan(0, $terminal->pendingBytes(), 'the undelivered tail is visible');

        $drained = '';
        $this->await(static function () use (&$drained, $peer, $terminal): bool {
            $chunk = fread($peer, length: 65_536);
            if (\is_string($chunk)) {
                $drained .= $chunk;
            }

            return 0 === $terminal->pendingBytes();
        });
        $this->assertSame(0, $terminal->pendingBytes(), 'zero once the peer drained everything');

        $terminal->stop();
        $this->assertSame(0, $terminal->pendingBytes(), 'zero after stop()');
        fclose($peer);
    }

    public function testADeadTtyTurnsWritesIntoSilentNoOps(): void
    {
        [$stream, $peer] = $this->pair();
        $calls = [];
        $received = [];
        $terminal = new NonBlockingTerminal($this->inner($calls), $stream);
        $this->start($terminal, $received);
        fclose($peer);

        // Write until the broken pipe surfaces and kills the queueing layer.
        $this->await(
            static function () use ($terminal): bool {
                $terminal->write(str_repeat('x', times: 65_536));

                return true; // one pass is enough on a closed socketpair; loop tick lets the watcher fire
            },
            0.1,
        );
        $terminal->write('after-death');
        $terminal->write('again');

        $this->assertSame(['start'], $calls, 'dead-tty writes never fall back to the (blocking) inner terminal');
        $terminal->stop();
        $this->assertContains('stop', $calls, 'stop still restores modes and stops the inner terminal');
    }
}
