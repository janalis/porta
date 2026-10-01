<?php

declare(strict_types=1);

namespace Porta\Support;

use Revolt\EventLoop;

/**
 * The stdio child-process transport {@see \Porta\Lsp\LspClient} and
 * {@see \Porta\Debug\DapClient} share: spawn over three pipes, non-blocking
 * stdin writes through {@see NonBlockingWriter}, and Revolt watchers that feed
 * each Content-Length frame ({@see JsonRpcFramer}) to the owner's message
 * handler and forward stderr lines to the logger. The message envelope
 * (JSON-RPC 2.0 vs DAP's seq/type) is the owner's business — this owns only
 * the wire.
 */
final class JsonRpcTransport
{
    /** @var resource|null */
    private $process;
    /** @var resource|null */
    private $stdin;
    /** @var resource|null */
    private $stdout;
    /** @var resource|null */
    private $stderr;

    private readonly JsonRpcFramer $framer;
    private ?string $stdoutWatcher = null;
    private ?string $stderrWatcher = null;
    /** Queued non-blocking stdin writes — a wedged peer must never block the event loop. */
    private ?NonBlockingWriter $writer = null;

    /** @var (callable(string): void)|null */
    private $logger;

    /** @var (\Closure(): void)|null the owner's "the peer is gone", fired once — by EOF or by a write that cannot land */
    private ?\Closure $gone = null;

    /**
     * @param list<string> $command  e.g. ['phpactor', 'language-server']
     * @param string       $peerName how log lines name the child ("LSP server", "debug adapter")
     */
    public function __construct(
        private readonly array $command,
        private readonly string $rootDir,
        private readonly string $peerName,
        ?callable $logger = null,
        private readonly int $maxWriteBuffer = NonBlockingWriter::DEFAULT_LIMIT,
    ) {
        $this->logger = $logger;
        $this->framer = new JsonRpcFramer(function (string $reason): void {
            $this->log($this->peerName.' sent what cannot be read: '.$reason);
        });
    }

    /**
     * Spawn the child and watch its pipes. $onMessage receives each decoded
     * frame; $onEof fires when the peer closes its stdout (after the
     * "closed its stdout" log line, and at most once — the watcher dies with
     * the pipe).
     *
     * @param callable(array<array-key, mixed>): void $onMessage
     * @param (callable(): void)|null                 $onEof
     */
    public function start(callable $onMessage, ?callable $onEof = null): bool
    {
        if ([] === $this->command || '' === $this->command[0]) {
            $this->log('cannot start the '.$this->peerName.': no command is set');

            return false; // a malformed command setting must not crash proc_open
        }
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        /** @var non-empty-string $cwd */
        $cwd = $this->rootDir;
        $pipes = [];
        // @mago-expect lint:no-error-control-operator -- proc_open on a nonexistent/unusable binary emits a spawn ("posix_spawn() failed") warning that guarding the inputs cannot reliably prevent; the false return is handled below.
        $process = @\proc_open($this->command, $descriptors, $pipes, $cwd);
        if (!\is_resource($process)) {
            $this->log(\sprintf('cannot start the %s: %s could not be run', $this->peerName, $this->command[0]));

            return false;
        }

        $this->process = $process;
        /** @var array{0: resource, 1: resource, 2: resource} $pipes */
        [$this->stdin, $this->stdout, $this->stderr] = [$pipes[0], $pipes[1], $pipes[2]];
        // stdin non-blocking too: a peer that stops draining its stdin must
        // never park the whole event loop inside a blocking fwrite (the classic
        // stdio deadlock once a frame exceeds the OS pipe buffer).
        \stream_set_blocking($this->stdin, enable: false);
        \stream_set_blocking($this->stdout, enable: false);
        \stream_set_blocking($this->stderr, enable: false);
        $this->writer = new NonBlockingWriter($this->stdin, $this->onWriteError(...), $this->maxWriteBuffer);

        $this->gone = null === $onEof ? null : \Closure::fromCallable($onEof);
        $this->stdoutWatcher = EventLoop::onReadable($this->stdout, function () use ($onMessage): void {
            $this->drainStdout($onMessage);
        });
        $this->stderrWatcher = EventLoop::onReadable($this->stderr, function (): void {
            /** @var resource $stderr */
            $stderr = $this->stderr;
            $data = \is_resource($stderr) ? \fread($stderr, length: 8192) : false;
            if (\is_string($data) && '' !== $data && null !== $this->logger) {
                ($this->logger)(\rtrim($data));
            }
        });

        return true;
    }

    public function isRunning(): bool
    {
        return null !== $this->process && \proc_get_status($this->process)['running'];
    }

    /** Frame and queue one outbound message; never throws (a dead writer is a no-op). */
    public function send(array $message): void
    {
        $this->writer?->write(JsonRpcFramer::encode($message));
    }

    public function stop(): void
    {
        $this->gone = null; // a deliberate stop is not the peer going
        if (null !== $this->stdoutWatcher) {
            EventLoop::cancel($this->stdoutWatcher);
            $this->stdoutWatcher = null;
        }
        if (null !== $this->stderrWatcher) {
            EventLoop::cancel($this->stderrWatcher);
            $this->stderrWatcher = null;
        }
        if (null !== $this->process) {
            $process = $this->process;
            // The drain watcher must die before its fd is closed below.
            $this->writer?->close();
            $this->writer = null;
            foreach ([$this->stdin, $this->stdout, $this->stderr] as $pipe) {
                if (!\is_resource($pipe)) {
                    continue;
                }

                \fclose($pipe);
            }
            if (\is_resource($process)) {
                ProcessReaper::terminate($process);
            }
            $this->process = null;
        }
    }

    /** @param callable(array<array-key, mixed>): void $onMessage */
    private function drainStdout(callable $onMessage): void
    {
        if (!\is_resource($this->stdout)) {
            return;
        }
        $data = \fread($this->stdout, length: 65_536);
        if (!\is_string($data) || '' === $data) {
            if (\feof($this->stdout)) {
                $this->log($this->peerName.' closed its stdout');
                $this->gone();
            }

            return;
        }

        foreach ($this->framer->feed($data) as $message) {
            // The frames after a throwing one were read off the pipe already:
            // dropped with it, their replies would leave requests waiting
            // forever. So each is handled, and what threw is thrown again on
            // the loop's next tick — where its error handler still hears it.
            try {
                $onMessage($message);
            } catch (\Throwable $error) {
                EventLoop::queue(static fn () => throw $error);
            }
        }
    }

    /**
     * The peer is gone: stop listening — stderr too, which left armed would
     * spin on every tick of a dead pipe and anchor the transport until an
     * owner stop() an abandoned client never issues — and tell the owner,
     * once.
     */
    private function gone(): void
    {
        if (null !== $this->stdoutWatcher) {
            EventLoop::cancel($this->stdoutWatcher);
            $this->stdoutWatcher = null;
        }
        if (null !== $this->stderrWatcher) {
            EventLoop::cancel($this->stderrWatcher);
            $this->stderrWatcher = null;
        }
        $gone = $this->gone;
        $this->gone = null;
        if (null !== $gone) {
            $gone();
        }
    }

    /**
     * A stdin write failed (dead peer) or the outbound queue overflowed
     * (wedged peer). Either way nothing sent from now on lands, so the peer
     * is gone NOW — not when an EOF comes, which a wedged child that shrugs
     * off SIGTERM never sends. The owner hears it as any crash and stops the
     * transport, whose reap ends the child however stubborn it is.
     */
    private function onWriteError(string $reason): void
    {
        $this->log('write to '.$this->peerName.' failed: '.$reason);
        if (\is_resource($this->process)) {
            \proc_terminate($this->process);
        }
        $this->gone();
    }

    private function log(string $line): void
    {
        if (null !== $this->logger) {
            ($this->logger)($line);
        }
    }
}
