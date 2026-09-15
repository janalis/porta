<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use Revolt\EventLoop;

/**
 * Read the far end of a socket pair the way a terminal does: from an
 * onReadable watcher on the loop, while a non-blocking writer refills it.
 *
 * Polling the peer inside an await() condition reads one socket buffer per
 * 0.02s turn, and a macOS unix socket buffers 8 KB — so a megabyte of
 * backlog took 2.6s to drain there. A watcher drains as fast as the writer's
 * own watcher refills, and needs no more loop turns than the await itself.
 */
trait DrainsPeer
{
    /** Satisfied by {@see PumpsLoop::await()} on the composing class. */
    abstract private function await(callable $done, float $timeout = 10.0, string $what = 'condition'): void;

    /**
     * Drain $peer until $done($received) holds; returns everything read.
     *
     * @param resource               $peer a non-blocking stream
     * @param callable(string): bool $done
     */
    private function drainPeer($peer, callable $done, string $what = 'the peer to drain'): string
    {
        $received = '';
        $watcher = EventLoop::onReadable($peer, static function () use ($peer, &$received): void {
            $chunk = \fread($peer, length: 65_536);
            if (\is_string($chunk)) {
                $received .= $chunk;
            }
        });
        try {
            $this->await(static function () use (&$received, $done): bool {
                return $done($received);
            }, what: $what);
        } finally {
            EventLoop::cancel($watcher);
        }

        return $received;
    }
}
