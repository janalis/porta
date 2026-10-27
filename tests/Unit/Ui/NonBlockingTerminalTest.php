<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;
use Porta\Tests\Support\DrainsPeer;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\PumpsLoop;
use Porta\Tests\Support\RecordingTerminal;
use Porta\Ui\NonBlockingTerminal;

final class NonBlockingTerminalTest extends TestCase
{
    use DrainsPeer;
    use PumpsLoop;

    /** @return array{0: resource, 1: resource} [decoratorStream, peerReadSide] */
    private function pair(): array
    {
        $pair = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, protocol: 0);
        \assert(false !== $pair, description: 'stream_socket_pair created a socket pair');
        \stream_set_blocking($pair[1], enable: false);

        return [$pair[0], $pair[1]];
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
        $terminal = new NonBlockingTerminal(new RecordingTerminal($calls), $stream);
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
        $this->assertSame($expected, \fread($peer, length: 8192), 'byte-identical to the vendor terminal, in call order');
        $this->assertSame(['start'], $calls, 'no writing method leaked to the inner terminal after start()');
        $this->assertSame(['probe'], $received, 'the onInput callback was passed through verbatim');
        $terminal->stop();
    }

    public function testNonWritingMethodsDelegate(): void
    {
        [$stream, $peer] = $this->pair();
        $calls = [];
        $inner = new RecordingTerminal($calls);
        $terminal = new NonBlockingTerminal($inner, $stream);

        $this->assertSame(123, $terminal->getColumns());
        $this->assertSame(45, $terminal->getRows());
        $this->assertTrue($terminal->isKittyProtocolActive());
        $this->assertFalse($terminal->isVirtual());
        $this->assertSame($inner->getEventDispatcher(), $terminal->getEventDispatcher(), 'the Tui refuses a terminal whose dispatcher is not its own');
        \fclose($peer);
    }

    public function testStartFlipsToNonBlockingOnlyAfterTheInnerBooted(): void
    {
        [$stream, $peer] = $this->pair();
        $blockedDuringStart = null;
        $calls = [];
        // The inner terminal's own boot writes must still run blocking.
        $inner = new RecordingTerminal($calls, onStart: static function () use ($stream, &$blockedDuringStart): void {
            $blockedDuringStart = \stream_get_meta_data($stream)['blocked'];
        });
        $terminal = new NonBlockingTerminal($inner, $stream);

        $terminal->start(static function (): void {}, static function (): void {}, static function (): void {});

        $this->assertTrue($blockedDuringStart, 'stream still blocking while the inner terminal boots');
        $this->assertFalse(\stream_get_meta_data($stream)['blocked'], 'non-blocking once the queue owns the stream');
        $terminal->stop();
        $this->assertTrue(\stream_get_meta_data($stream)['blocked'], 'blocking mode restored on stop');
        \fclose($peer);
    }

    public function testWritesOutsideTheStartStopWindowPassThroughToTheInner(): void
    {
        [$stream, $peer] = $this->pair();
        $calls = [];
        $received = [];
        $terminal = new NonBlockingTerminal(new RecordingTerminal($calls), $stream);

        $terminal->write('boot-bytes'); // before start(): the alt-screen bracket
        $this->assertSame(['write:boot-bytes'], $calls);

        $this->start($terminal, $received);
        $terminal->write('frame');
        $terminal->stop();
        $terminal->write('teardown-bytes'); // after stop(): the alt-screen exit

        $this->assertContains('write:teardown-bytes', $calls, 'post-stop writes reach the tty via the inner terminal');
        $this->assertSame('frame', \fread($peer, length: 8192), 'queued bytes were flushed by stop()');
        $terminal->stop(); // double-stop is harmless
        $this->assertCount(2, \array_filter($calls, static fn (string $c): bool => 'stop' === $c));
        \fclose($peer);
    }

    public function testBackPressureNeverBlocksAndDrainsInOrder(): void
    {
        [$stream, $peer] = $this->pair();
        $calls = [];
        $received = [];
        $terminal = new NonBlockingTerminal(new RecordingTerminal($calls), $stream);
        $this->start($terminal, $received);
        $first = \str_repeat('a', times: 524_288);
        $second = \str_repeat('b', times: 524_288);

        // A queue-depth claim, not a clock one: nothing is reading this
        // socketpair, so a write that had parked until the peer drained would
        // leave nothing pending. A wall-clock assertLessThan(0.1) could only
        // ever fail from a scheduler hiccup.
        $terminal->write($first);
        $terminal->write($second);
        $this->assertGreaterThan(\strlen($first), $terminal->pendingBytes(), 'a stalled terminal cannot park the loop in a write');

        $expected = \strlen($first) + \strlen($second);
        $drained = $this->drainPeer($peer, static fn (string $received): bool => \strlen($received) >= $expected);

        $this->assertSame($first.$second, $drained, 'frames arrive intact and in order once the terminal drains');
        $terminal->stop();
        \fclose($peer);
    }

    public function testOverflowFallsBackToABlockingFlushWithoutDroppingBytes(): void
    {
        // A peer that drains only after a pause: the fallback blocks briefly
        // instead of deadlocking, and every byte still arrives in order. Its
        // echo goes to a FILE — an echo pipe could fill and deadlock too.
        $sink = \tempnam(\sys_get_temp_dir(), prefix: 'porta-nbt');
        \assert(\is_string($sink), description: 'tempnam returned a sink path');
        $process = \proc_open(
            [\PHP_BINARY, __DIR__.'/../../Support/slow-drain-peer.php'],
            [0 => ['pipe', 'r'], 1 => ['file', $sink, 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
        );
        \assert(\is_resource($process), description: 'proc_open started the slow-drain peer');
        $calls = [];
        $received = [];
        $terminal = new NonBlockingTerminal(new RecordingTerminal($calls), $pipes[0], limit: 65_536);
        $this->start($terminal, $received);

        // try/finally: an abort must stop the terminal (cancelling its writer
        // watcher) before the local $process handle's destructor closes
        // $pipes[0] behind it — the worker-poisoning shape.
        try {
            $first = \str_repeat('x', times: 131_072); // fills the ~64KiB pipe, remainder queued past the limit
            $second = \str_repeat('y', times: 131_072); // trips the pre-check → flushBlocking against the slow peer
            $terminal->write($first);
            $terminal->write($second);
        } finally {
            $terminal->stop(); // flushes whatever is still queued
            \fclose($pipes[0]); // EOF ends the peer's fpassthru
            \proc_close($process); // waits for the child, so the sink is complete
        }
        $this->assertSame($first.$second, (string) \file_get_contents($sink), 'nothing was dropped across the blocking fallback');
        if (\is_file($sink)) {
            \unlink($sink);
        }
    }

    public function testPendingBytesReportsTheBacklogLifecycle(): void
    {
        [$stream, $peer] = $this->pair();
        $calls = [];
        $received = [];
        $terminal = new NonBlockingTerminal(new RecordingTerminal($calls), $stream);
        $this->assertSame(0, $terminal->pendingBytes(), 'no writer before start()');

        $this->start($terminal, $received);
        $terminal->write(\str_repeat('x', times: 524_288)); // past the socket buffer
        $this->assertGreaterThan(0, $terminal->pendingBytes(), 'the undelivered tail is visible');

        $this->drainPeer($peer, static fn (): bool => 0 === $terminal->pendingBytes());
        $this->assertSame(0, $terminal->pendingBytes(), 'zero once the peer drained everything');

        $terminal->stop();
        $this->assertSame(0, $terminal->pendingBytes(), 'zero after stop()');
        \fclose($peer);
    }

    public function testADeadTtyTurnsWritesIntoSilentNoOps(): void
    {
        [$stream, $peer] = $this->pair();
        $calls = [];
        $received = [];
        $terminal = new NonBlockingTerminal(new RecordingTerminal($calls), $stream);
        $this->start($terminal, $received);
        \fclose($peer);

        // Write until the broken pipe surfaces and NULLS the writer — which is
        // the death this test is about, and is observable, so it is the
        // barrier. The condition used to `return true` unconditionally: one
        // loop turn wearing await()'s clothes, on a 0.1s budget that could not
        // fail and told the reader nothing about what was being waited for.
        $this->await(
            static function () use ($terminal): bool {
                $terminal->write(\str_repeat('x', times: 65_536));

                return null === Probe::get($terminal, 'writer');
            },
            what: 'the broken pipe to kill the queueing layer',
        );
        $terminal->write('after-death');
        $terminal->write('again');

        $this->assertSame(['start'], $calls, 'dead-tty writes never fall back to the (blocking) inner terminal');
        $terminal->stop();
        $this->assertContains('stop', $calls, 'stop still restores modes and stops the inner terminal');
    }

    public function testADrainHookSetBeforeStartFiresWhenTheBacklogEmpties(): void
    {
        [$stream, $peer] = $this->pair();
        $calls = [];
        $received = [];
        $terminal = new NonBlockingTerminal(new RecordingTerminal($calls), $stream);
        $drains = 0;
        $terminal->onDrain(static function () use (&$drains): void {
            ++$drains;
        });
        $this->start($terminal, $received);

        $terminal->write(\str_repeat('#', times: 524_288)); // past the socket buffer → backlog
        $this->assertGreaterThan(0, $terminal->pendingBytes());
        $this->drainPeer($peer, static fn (): bool => 0 === $terminal->pendingBytes());

        $this->assertSame(1, $drains, 'the pre-start hook was forwarded to the writer at start');
        $terminal->stop();
        \fclose($peer);
    }

    public function testTheDrainHookSurvivesAStopStartCycle(): void
    {
        [$stream, $peer] = $this->pair();
        $calls = [];
        $received = [];
        $terminal = new NonBlockingTerminal(new RecordingTerminal($calls), $stream);
        $this->start($terminal, $received);
        $drains = 0;
        $terminal->onDrain(static function () use (&$drains): void {
            ++$drains;
        });

        $terminal->stop();
        $this->start($terminal, $received); // a fresh writer must re-receive the hook

        $terminal->write(\str_repeat('#', times: 524_288));
        $this->assertGreaterThan(0, $terminal->pendingBytes());
        $this->drainPeer($peer, static fn (): bool => 0 === $terminal->pendingBytes());

        $this->assertSame(1, $drains, 'the hook survived the restart');
        $terminal->stop();
        \fclose($peer);
    }

    public function testTheKittyEnableWakesTheOwnerSoTheScreenCanBeRepaired(): void
    {
        // The inner terminal answers the host's kitty query by writing
        // `CSI >7u` STRAIGHT to stdout, bypassing this decorator's queue, from
        // its stdin handler. Captured from a real session it landed inside an
        // SGR -- `ESC[38;` `ESC[>7u` `5;240m` -- leaving six literal characters
        // in the row, which the tty then wrapped, putting every row below one
        // off for the rest of the session. The bytes cannot be intercepted, so
        // the owner has to be told it happened.
        $calls = [];
        $inner = new RecordingTerminal($calls);
        [$stream] = $this->pair();
        $terminal = new NonBlockingTerminal($inner, $stream);

        $woken = 0;
        $terminal->onKittyActivated(static function () use (&$woken): void {
            ++$woken;
        });
        $terminal->start(static function (): void {}, static function (): void {}, static function (): void {});

        $this->assertSame(0, $woken, 'nothing fires until the host actually answers');

        ($inner->kitty)();

        $this->assertSame(1, $woken, 'the owner is told the moment the direct write has happened');
    }

    public function testTheWrappedKittyCallbackStillReachesTheTui(): void
    {
        // The vendor's own callback sets the kitty flag on the key parser.
        // Wrapping it must not swallow it.
        $calls = [];
        $inner = new RecordingTerminal($calls);
        [$stream] = $this->pair();
        $terminal = new NonBlockingTerminal($inner, $stream);

        $vendor = 0;
        $terminal->start(
            static function (): void {},
            static function (): void {},
            static function () use (&$vendor): void {
                ++$vendor;
            },
        );
        ($inner->kitty)();

        $this->assertSame(1, $vendor);
    }

    public function testWithNoHookInstalledTheKittyCallbackIsHarmless(): void
    {
        $calls = [];
        $inner = new RecordingTerminal($calls);
        [$stream] = $this->pair();
        $terminal = new NonBlockingTerminal($inner, $stream);
        $terminal->start(static function (): void {}, static function (): void {}, static function (): void {});

        ($inner->kitty)();

        $this->assertContains('start', $calls);
    }
}
