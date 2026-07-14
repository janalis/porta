<?php

declare(strict_types=1);

namespace Porta\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Porta\Ui\NonBlockingTerminal;
use Revolt\EventLoop;
use Symfony\Component\Tui\Terminal\VirtualTerminal;

final class NonBlockingTerminalTest extends TestCase
{
    /**
     * The freeze regression (mirrors LspClientTest::testAWedgedServerNeverBlocksTheEventLoop):
     * a terminal that stops draining used to park the whole loop inside the
     * vendor's blocking fwrite — the exact backtrace .porta/freeze.log captured.
     */
    public function testAWedgedTerminalNeverBlocksTheEventLoop(): void
    {
        $pair = stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, 0);
        \assert(false !== $pair);
        stream_set_blocking($pair[1], false);
        // The inner "terminal" is a VirtualTerminal: writes are decorated away
        // from it after start(), it just provides the delegated surface.
        $terminal = new NonBlockingTerminal(new VirtualTerminal(columns: 160, rows: 48), $pair[0]);
        $terminal->start(static function (): void {}, static function (): void {}, static function (): void {});

        $first = "\x1b[?2026h".str_repeat('a', 262144)."\x1b[?2026l"; // an oversized "frame"
        $second = "\x1b[?2026h".str_repeat('b', 262144)."\x1b[?2026l";
        $start = microtime(true);
        $terminal->write($first);
        $terminal->hideCursor();
        $terminal->write($second);
        $terminal->showCursor();
        $this->assertLessThan(0.5, microtime(true) - $start, 'frame writes return immediately, bytes queue');

        // The loop stays live: this timer never fires on the vendor blocking path.
        $ticked = false;
        EventLoop::delay(0.05, static function () use (&$ticked): void {
            $ticked = true;
        });
        $this->await(static fn (): bool => $ticked, 2.0);
        $this->assertTrue($ticked, 'the event loop kept running while the terminal sat on a full buffer');

        // Once the terminal drains, everything arrives byte-identical and in order.
        $drained = '';
        $expected = $first."\x1b[?25l".$second."\x1b[?25h";
        $this->await(function () use (&$drained, $pair, $expected): bool {
            $chunk = fread($pair[1], 65536);
            if (\is_string($chunk)) {
                $drained .= $chunk;
            }

            return \strlen($drained) >= \strlen($expected);
        });
        $this->assertSame($expected, $drained, 'frames and cursor writes preserved exactly, in order');

        $terminal->stop();
        fclose($pair[1]);
    }

    /** Run the event loop until $done() is true or the timeout elapses. */
    private function await(callable $done, float $timeout = 3.0): void
    {
        $elapsed = 0.0;
        while (!$done() && $elapsed < $timeout) {
            $suspension = EventLoop::getSuspension();
            EventLoop::delay(0.02, static fn () => $suspension->resume());
            $suspension->suspend();
            $elapsed += 0.02;
        }
    }
}
