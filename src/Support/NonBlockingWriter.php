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

    /** Most handed to one fwrite: a peer takes far less, and the copy is per call. */
    private const int CHUNK = 65_536;

    /** Written bytes at the head of the buffer that are dropped together, not one write at a time. */
    private const int COMPACT_AT = 1_048_576;

    private string $buffer = '';

    /**
     * How much of $buffer is already written. Trimming the buffer after every
     * write copied the whole queue each time: a pty takes about a kilobyte a
     * write, so a 9 MB paste spent six seconds copying alone.
     */
    private int $offset = 0;
    private ?string $watcher = null;
    private bool $failed = false;
    private readonly \Closure $onError;

    /** @var (\Closure(): void)|null fired when a queued backlog finishes draining */
    private ?\Closure $onDrain = null;

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
        if (($this->pendingBytes() + \strlen($bytes)) > $this->limit) {
            $this->fail('write queue overflow');

            return;
        }
        $this->buffer .= $bytes;
        $this->flush();
    }

    /** Bytes queued but not yet accepted by the stream. */
    public function pendingBytes(): int
    {
        return \strlen($this->buffer) - $this->offset;
    }

    /**
     * Install (or clear, with null) a hook fired when a queued BACKLOG empties
     * — i.e. only on the watcher-armed → drained transition, so a write the
     * stream accepted immediately never fires it. An owner that paced itself
     * on {@see pendingBytes()} uses this to resume the moment the peer catches
     * up instead of polling. Not fired by {@see flushBlocking()} (the caller
     * is synchronous and already knows) nor on failure/close.
     *
     * @param (\Closure(): void)|null $hook
     */
    public function onDrain(?\Closure $hook): void
    {
        $this->onDrain = $hook;
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
        $stream = $this->stream;
        \stream_set_blocking($stream, enable: true);
        while ('' !== $this->buffer) {
            // @mago-expect lint:no-error-control-operator -- fwrite to a pipe whose peer has died (the local fd is still a live resource) raises an unavoidable EPIPE warning that no is_resource guard prevents; the false/0 return is the handled failure signal.
            $n = @\fwrite($stream, $this->chunk());
            if (false === $n || 0 === $n) {
                \stream_set_blocking($stream, enable: false);
                $this->fail('write failed');

                return;
            }
            $this->written($n);
        }
        if (\is_resource($stream)) {
            \fflush($stream);
        }
        \stream_set_blocking($stream, enable: false);
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
        $this->offset = 0;
    }

    /** The next bytes to hand fwrite, from where the last write stopped. */
    private function chunk(): string
    {
        return \substr($this->buffer, $this->offset, self::CHUNK);
    }

    /** $n more bytes reached the peer: empty the buffer once all have, compact it now and then. */
    private function written(int $n): void
    {
        $this->offset += $n;
        if ($this->offset >= \strlen($this->buffer)) {
            $this->buffer = '';
            $this->offset = 0;

            return;
        }
        if ($this->offset >= self::COMPACT_AT) {
            $this->buffer = \substr($this->buffer, $this->offset);
            $this->offset = 0;
        }
    }

    private function flush(): void
    {
        if (null === $this->stream || '' === $this->buffer) {
            return;
        }
        $stream = $this->stream;
        // As much as the peer takes now, a chunk at a time: one call per
        // writable event used to leave the rest for the next event.
        do {
            $chunk = $this->chunk();
            // @mago-expect lint:no-error-control-operator -- fwrite to a pipe whose peer has died (the local fd is still a live resource) raises an unavoidable EPIPE warning that no is_resource guard prevents; the false return is the handled failure signal.
            $n = @\fwrite($stream, $chunk);
            if (false === $n) {
                $this->fail('write failed');

                return;
            }
            $this->written($n);
        } while (\strlen($chunk) === $n && '' !== $this->buffer);
        // PHP's stream layer may hold a small tail in its own write buffer
        // (stream_set_write_buffer fails on proc pipes); push it to the fd —
        // on a non-blocking stream this never blocks.
        if (\is_resource($stream)) {
            \fflush($stream);
        }
        // Read once: $onDrain may queue more, and that write flushes itself.
        $drained = '' === $this->buffer;

        if ($drained && null !== $this->watcher) {
            EventLoop::cancel($this->watcher);
            $this->watcher = null;
            // A backlog (not an immediately-accepted write) just drained.
            if (null !== $this->onDrain) {
                ($this->onDrain)();
            }
        }
        // Remainder queued: drain when the peer accepts more. A peer that dies
        // while bytes are queued reports writable-with-error, and the next
        // fwrite returns false → the error path above.
        if (!$drained) {
            $this->watcher ??= EventLoop::onWritable($stream, $this->flush(...));
        }
    }

    private function fail(string $reason): void
    {
        $this->close();
        $this->failed = true;
        ($this->onError)($reason);
    }
}
