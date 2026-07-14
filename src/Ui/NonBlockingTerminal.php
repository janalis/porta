<?php

declare(strict_types=1);

namespace Porta\Ui;

use Porta\Support\NonBlockingWriter;
use Symfony\Component\Tui\Terminal\TerminalInterface;

/**
 * Decorates the real Terminal so every escape write rides a
 * {@see NonBlockingWriter} queue on a non-blocking STDOUT instead of the
 * vendor's blocking fwrite — the event loop can never park inside a terminal
 * write when the terminal stops draining (stalled SSH, XOFF flow control, a
 * paused pane): the proven freeze cause captured in `.porta/freeze.log`.
 *
 * Bytes are NEVER dropped: the differential renderer commits its screen model
 * assuming every write is delivered, so a backlog past $limit falls back to
 * one synchronous blocking flush — worst case equals the old vendor behavior,
 * but only after megabytes of a wedged terminal (and the watchdog still logs
 * it). Writes before start() / after stop() (the coordinator's alternate-
 * screen bracket) pass through to the inner terminal in blocking mode, as do
 * the inner terminal's own boot/teardown mode writes; stop() flushes the
 * queue synchronously so teardown escapes always reach the tty.
 *
 * Every writing method is reimplemented byte-identically through the queue —
 * delegating them to the inner terminal would emit direct, blocking writes
 * that also reorder around queued frame bytes. Known deviations/bypasses:
 * bell() emits BEL through the queue on all platforms (the vendor plays a
 * sound via afplay on macOS), and the inner terminal's one-shot 7-byte kitty
 * enable still writes directly at boot.
 */
final class NonBlockingTerminal implements TerminalInterface
{
    /** @var resource */
    private $stream;

    /** Non-null only between start() and stop() — the queueing window. */
    private ?NonBlockingWriter $writer = null;

    /** Whether this decorator switched the stream to non-blocking mode. */
    private bool $nonBlockingActive = false;

    /**
     * @param TerminalInterface $inner  the real terminal (raw mode, stdin, dimensions)
     * @param resource|null     $stream defaults to STDOUT; injectable for tests
     * @param int               $limit  backlog that triggers the blocking-flush fallback
     */
    public function __construct(
        private readonly TerminalInterface $inner,
        $stream = null,
        private readonly int $limit = NonBlockingWriter::DEFAULT_LIMIT,
    ) {
        $this->stream = $stream ?? \STDOUT;
    }

    public function start(callable $onInput, callable $onResize, callable $onKittyProtocolActivated): void
    {
        // The inner terminal's boot writes (bracketed paste, kitty query) run
        // while the stream is still blocking; only then flip to non-blocking.
        $this->inner->start($onInput, $onResize, $onKittyProtocolActivated);
        stream_set_blocking($this->stream, false);
        // The writer's own cap is out of the picture (PHP_INT_MAX): overflow is
        // enforced here as a flush-first pre-check, because the writer's cap
        // would DROP the queue — never acceptable for terminal bytes.
        $this->writer = new NonBlockingWriter($this->stream, $this->onWriteError(...), \PHP_INT_MAX);
        $this->nonBlockingActive = true;
    }

    public function stop(): void
    {
        if (null !== $this->writer) {
            // Teardown escapes (cursor restore, alternate-screen exit) must hit
            // the tty before the process exits — deliver synchronously.
            $this->writer->flushBlocking();
            $this->writer->close();
            $this->writer = null;
        }
        if ($this->nonBlockingActive) {
            @stream_set_blocking($this->stream, true);
            $this->nonBlockingActive = false;
        }
        $this->inner->stop();
    }

    /**
     * Bytes queued but not yet accepted by the tty — the render loop's pacing
     * signal ({@see BidiTui::processRender} skips frame generation while the
     * previous frame is still draining, so a slow terminal can never fall
     * hundreds of stale frames behind the input).
     */
    public function pendingBytes(): int
    {
        return $this->writer?->pendingBytes() ?? 0;
    }

    public function write(string $data): void
    {
        if (null !== $this->writer) {
            if (($this->writer->pendingBytes() + \strlen($data)) > $this->limit) {
                $this->writer->flushBlocking(); // never drop; a failure nulls the writer below
            }
            $this->writer?->write($data);

            return;
        }
        if ($this->nonBlockingActive) {
            return; // the tty died mid-session: writes are silent no-ops
        }
        $this->inner->write($data); // before start() / after stop(): blocking pass-through
    }

    public function getColumns(): int
    {
        return $this->inner->getColumns();
    }

    public function getRows(): int
    {
        return $this->inner->getRows();
    }

    public function isKittyProtocolActive(): bool
    {
        return $this->inner->isKittyProtocolActive();
    }

    public function moveBy(int $lines): void
    {
        if ($lines > 0) {
            $this->write("\x1b[{$lines}B");
        } elseif ($lines < 0) {
            $this->write("\x1b[".-$lines.'A');
        }
    }

    public function hideCursor(): void
    {
        $this->write("\x1b[?25l");
    }

    public function showCursor(): void
    {
        $this->write("\x1b[?25h");
    }

    public function clearLine(): void
    {
        $this->write("\x1b[2K");
    }

    public function clearFromCursor(): void
    {
        $this->write("\x1b[0J");
    }

    public function clearScreen(): void
    {
        $this->write("\x1b[2J\x1b[H");
    }

    public function setTitle(string $title): void
    {
        $safe = (string) preg_replace("/[\x00-\x1f\x7f]|\xc2[\x80-\x9f]/", '', $title);
        $this->write("\x1b]0;{$safe}\x07");
    }

    public function bell(): void
    {
        $this->write("\x07");
    }

    public function isVirtual(): bool
    {
        return $this->inner->isVirtual();
    }

    /** The tty is gone (or a blocking flush failed): drop the queueing layer. */
    private function onWriteError(string $reason): void
    {
        $this->writer = null; // the writer already closed itself
    }
}
