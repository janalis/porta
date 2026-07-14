<?php

declare(strict_types=1);

namespace Porta\Support;

use Revolt\EventLoop;

/**
 * An outbound byte queue for a non-blocking stream — the write-side sibling of
 * {@see AsyncProcess}. Appended bytes are flushed immediately when the stream
 * accepts them; a short write leaves the remainder queued and arms a single
 * `onWritable` watcher that drains it as the peer catches up. The owner keeps
 * the whole event loop non-blocking: a peer that stops reading (a wedged LSP
 * server) can no longer park the loop inside a blocking `fwrite`.
 *
 * A failed write (dead peer) or a queue overflow (peer wedged while the app
 * keeps sending) drops the queue, fires `$onError` once with a reason, and
 * leaves the writer permanently dead — further writes are silent no-ops.
 * Frame ordering is inherent: one buffer, one watcher.
 */
final class NonBlockingWriter
{
    /** Queue cap: a peer this far behind is treated as wedged. */
    public const int DEFAULT_LIMIT = 8_388_608; // 8 MiB

    /** @var resource|null the target stream, already in non-blocking mode; null once closed */
    private $stream;

    private string $buffer = '';
    private ?string $watcher = null;
    private bool $failed = false;
    private readonly \Closure $onError;

    /**
     * @param resource               $stream  a stream the OWNER has switched to non-blocking mode
     * @param \Closure(string): void $onError fired once with a reason; the writer is dead afterwards
     */
    public function __construct(
        $stream,
        \Closure $onError,
        private readonly int $limit = self::DEFAULT_LIMIT,
    ) {
        $this->stream = $stream;
        $this->onError = $onError;
    }

    /** Queue $bytes and flush as much as the stream accepts right now. */
    public function write(string $bytes): void
    {
        if ($this->failed || null === $this->stream) {
            return;
        }
        if ((\strlen($this->buffer) + \strlen($bytes)) > $this->limit) {
            $this->fail('write queue overflow');

            return;
        }
        $this->buffer .= $bytes;
        $this->flush();
    }

    /** Bytes queued but not yet accepted by the stream. */
    public function pendingBytes(): int
    {
        return \strlen($this->buffer);
    }

    /**
     * Synchronously deliver everything queued: switch the stream to blocking
     * mode, write the whole buffer out, restore non-blocking mode. The owner's
     * escape hatch for streams where dropping bytes is worse than blocking
     * (terminal STDOUT — a dropped frame corrupts the differential renderer's
     * screen model for the rest of the session). A no-op when nothing is
     * queued or the writer is dead.
     */
    public function flushBlocking(): void
    {
        if ($this->failed || null === $this->stream || '' === $this->buffer) {
            return;
        }
        if (null !== $this->watcher) {
            EventLoop::cancel($this->watcher);
            $this->watcher = null;
        }
        stream_set_blocking($this->stream, true);
        while ('' !== $this->buffer) {
            $n = @fwrite($this->stream, $this->buffer);
            if (false === $n || 0 === $n) {
                stream_set_blocking($this->stream, false);
                $this->fail('write failed');

                return;
            }
            $this->buffer = substr($this->buffer, $n);
        }
        @fflush($this->stream);
        stream_set_blocking($this->stream, false);
    }

    /** Cancel the drain watcher and forget the stream (the owner fcloses the fd). */
    public function close(): void
    {
        if (null !== $this->watcher) {
            EventLoop::cancel($this->watcher);
            $this->watcher = null;
        }
        $this->stream = null;
        $this->buffer = '';
    }

    private function flush(): void
    {
        if (null === $this->stream || '' === $this->buffer) {
            return;
        }
        $n = @fwrite($this->stream, $this->buffer);
        if (false === $n) {
            $this->fail('write failed');

            return;
        }
        // PHP's stream layer may hold a small tail in its own write buffer
        // (stream_set_write_buffer fails on proc pipes); push it to the fd —
        // on a non-blocking stream this never blocks.
        @fflush($this->stream);
        $this->buffer = substr($this->buffer, $n);

        if ('' === $this->buffer) {
            if (null !== $this->watcher) {
                EventLoop::cancel($this->watcher);
                $this->watcher = null;
            }

            return;
        }
        // Remainder queued: drain when the peer accepts more. A peer that dies
        // while bytes are queued reports writable-with-error, and the next
        // fwrite returns false → the error path above.
        $this->watcher ??= EventLoop::onWritable($this->stream, fn () => $this->flush());
    }

    private function fail(string $reason): void
    {
        $this->close();
        $this->failed = true;
        ($this->onError)($reason);
    }
}
