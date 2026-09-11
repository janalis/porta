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
 * sound via afplay on macOS), and the inner terminal's one-shot kitty enable
 * (`CSI >7u`) still writes DIRECTLY, bypassing the queue.
 *
 * That last one is not the harmless boot write this comment used to claim it
 * was. The inner terminal sends it from its stdin handler, when the host
 * answers the `CSI ?u` query — which is whenever the host gets round to it,
 * long after the stream went non-blocking and while a frame may be half
 * flushed. Captured from a real session, it landed inside an SGR:
 *
 *     ESC[38;   ESC[>7u   5;240m
 *
 * leaving `5;240m` to print as six literal characters, making the row six
 * columns too wide. The tty wraps it and every row below is one off — for the
 * rest of the session, because the frame writer only ever repositions
 * relatively. The bytes cannot be intercepted (the inner terminal is final and
 * writes to STDOUT itself), so the damage is REPAIRED instead:
 * {@see onKittyActivated()} fires after the enable, and the owner resyncs the
 * screen with a full clear.
 */
final class NonBlockingTerminal implements TerminalInterface
{
    /** @var resource */
    private $stream;

    /** Non-null only between start() and stop() — the queueing window. */
    private ?NonBlockingWriter $writer = null;

    /** @var (\Closure(): void)|null forwarded to the writer, surviving stop()/start() cycles */
    private ?\Closure $onDrain = null;

    /** @var (\Closure(): void)|null fired just after the inner terminal's direct kitty enable */
    private ?\Closure $onKittyActivated = null;

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

    #[\Override]
    public function start(callable $onInput, callable $onResize, callable $onKittyProtocolActivated): void
    {
        // The inner terminal's boot writes (bracketed paste, kitty query) run
        // while the stream is still blocking; only then flip to non-blocking.
        //
        // The kitty callback is wrapped rather than passed through: the inner
        // terminal writes `CSI >7u` straight to stdout immediately BEFORE
        // invoking it, so this is the first moment the owner can learn that a
        // stray sequence may have been spliced into a half-flushed frame.
        $this->inner->start($onInput, $onResize, function () use ($onKittyProtocolActivated): void {
            $onKittyProtocolActivated();
            if (null !== $this->onKittyActivated) {
                ($this->onKittyActivated)();
            }
        });
        stream_set_blocking($this->stream, enable: false);
        // The writer's own cap is out of the picture (PHP_INT_MAX): overflow is
        // enforced here as a flush-first pre-check, because the writer's cap
        // would DROP the queue — never acceptable for terminal bytes.
        $this->writer = new NonBlockingWriter($this->stream, $this->onWriteError(...), \PHP_INT_MAX);
        $this->writer->onDrain($this->onDrain);
        $this->nonBlockingActive = true;
    }

    /**
     * Install (or clear, with null) the hook fired right after the inner
     * terminal has answered the kitty keyboard query with its own direct,
     * unqueued write — the one moment a foreign sequence can be spliced into a
     * frame. The owner repairs the screen from here; see the class docblock.
     *
     * @param (\Closure(): void)|null $hook
     */
    public function onKittyActivated(?\Closure $hook): void
    {
        $this->onKittyActivated = $hook;
    }

    /**
     * Install (or clear, with null) the backlog-drained hook — the render
     * loop's wake-up call: a frame skipped while {@see pendingBytes()} was
     * positive repaints the moment the tty catches up instead of waiting for
     * the ticker. Survives set-before-start and a stop()/start() cycle.
     *
     * @param (\Closure(): void)|null $hook
     */
    public function onDrain(?\Closure $hook): void
    {
        $this->onDrain = $hook;
        $this->writer?->onDrain($hook);
    }

    #[\Override]
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
            if (\is_resource($this->stream)) {
                stream_set_blocking($this->stream, enable: true);
            }
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

    #[\Override]
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

    #[\Override]
    public function getColumns(): int
    {
        return $this->inner->getColumns();
    }

    #[\Override]
    public function getRows(): int
    {
        return $this->inner->getRows();
    }

    #[\Override]
    public function isKittyProtocolActive(): bool
    {
        return $this->inner->isKittyProtocolActive();
    }

    #[\Override]
    public function moveBy(int $lines): void
    {
        if ($lines > 0) {
            $this->write("\x1b[{$lines}B");

            return;
        }
        if ($lines < 0) {
            $this->write("\x1b[".-$lines.'A');
        }
    }

    #[\Override]
    public function hideCursor(): void
    {
        $this->write("\x1b[?25l");
    }

    #[\Override]
    public function showCursor(): void
    {
        $this->write("\x1b[?25h");
    }

    #[\Override]
    public function clearLine(): void
    {
        $this->write("\x1b[2K");
    }

    #[\Override]
    public function clearFromCursor(): void
    {
        $this->write("\x1b[0J");
    }

    #[\Override]
    public function clearScreen(): void
    {
        $this->write("\x1b[2J\x1b[H");
    }

    #[\Override]
    public function setTitle(string $title): void
    {
        $safe = (string) preg_replace("/[\x00-\x1f\x7f]|\xc2[\x80-\x9f]/", replacement: '', subject: $title);
        $this->write("\x1b]0;{$safe}\x07");
    }

    #[\Override]
    public function bell(): void
    {
        $this->write("\x07");
    }

    #[\Override]
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
