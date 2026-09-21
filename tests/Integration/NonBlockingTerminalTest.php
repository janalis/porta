<?php

declare(strict_types=1);

namespace Porta\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Porta\Tests\Support\PumpsLoop;
use Porta\Ui\NonBlockingTerminal;
use Revolt\EventLoop;
use Symfony\Component\Tui\Terminal\VirtualTerminal;

final class NonBlockingTerminalTest extends TestCase
{
    use PumpsLoop;

    /**
     * The freeze regression (mirrors LspClientTest::testAWedgedServerNeverBlocksTheEventLoop):
     * a terminal that stops draining used to park the whole loop inside the
     * vendor's blocking fwrite — the exact backtrace .porta/freeze.log captured.
     */
    public function testAWedgedTerminalNeverBlocksTheEventLoop(): void
    {
        $pair = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, protocol: 0);
        \assert(false !== $pair, description: 'stream_socket_pair returned a valid socket pair');
        \stream_set_blocking($pair[1], enable: false);
        // The inner "terminal" is a VirtualTerminal: writes are decorated away
        // from it after start(), it just provides the delegated surface.
        $terminal = new NonBlockingTerminal(new VirtualTerminal(columns: 160, rows: 48), $pair[0]);
        $terminal->start(static function (): void {}, static function (): void {}, static function (): void {});

        $first = "\x1b[?2026h".\str_repeat('a', times: 262_144)."\x1b[?2026l"; // an oversized "frame"
        $second = "\x1b[?2026h".\str_repeat('b', times: 262_144)."\x1b[?2026l";
        $terminal->write($first);
        $terminal->hideCursor();
        $terminal->write($second);
        $terminal->showCursor();
        // No wall-clock assertion: on the vendor blocking path these writes
        // never return, so reaching this line is the proof, and the timer below
        // is what states it. A clock budget added only a way to fail on a GC
        // pause under sixteen workers.

        // The loop stays live: this timer never fires on the vendor blocking path.
        $ticked = false;
        EventLoop::delay(0.05, static function () use (&$ticked): void {
            $ticked = true;
        });
        $this->await(static function () use (&$ticked): bool {
            return $ticked;
        }, 2.0);
        $this->assertTrue($ticked, 'the event loop kept running while the terminal sat on a full buffer');

        // Once the terminal drains, everything arrives byte-identical and in order.
        $drained = '';
        $expected = $first."\x1b[?25l".$second."\x1b[?25h";
        $this->await(static function () use (&$drained, $pair, $expected): bool {
            $chunk = \fread($pair[1], length: 65_536);
            if (\is_string($chunk)) {
                $drained .= $chunk;
            }

            return \strlen($drained) >= \strlen($expected);
        });
        $this->assertSame($expected, $drained, 'frames and cursor writes preserved exactly, in order');

        $terminal->stop();
        \fclose($pair[1]);
    }

    /** Run the event loop until $done() is true or the timeout elapses. */
}
