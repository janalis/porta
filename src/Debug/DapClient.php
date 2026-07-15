<?php

declare(strict_types=1);

namespace Porta\Debug;

use Porta\Support\JsonRpcFramer;
use Porta\Support\NonBlockingWriter;
use Revolt\EventLoop;

/**
 * Asynchronous Debug Adapter Protocol transport over a child process's stdio,
 * driven by the shared Revolt loop (the DAP wire format reuses LSP's
 * Content-Length framing via {@see JsonRpcFramer}; only the message envelope
 * differs). Reverse requests (e.g. runInTerminal) are answered with a failure —
 * the initialize handshake advertises no support for them.
 */
final class DapClient implements DapClientInterface
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
    /** Queued non-blocking stdin writes — a wedged adapter must never block the event loop. */
    private ?NonBlockingWriter $writer = null;

    private int $nextSeq = 1;
    /** @var array<int, callable(array<string, mixed>, ?string): void> request seq => callback */
    private array $pending = [];
    /** @var array<string, callable(array<string, mixed>): void> event name => handler */
    private array $eventHandlers = [];
    /** @var (callable(): void)|null */
    private $onClose = null;
    private bool $closed = false;

    /** @var (callable(string): void)|null */
    private $logger;

    /** @param list<string> $command */
    public function __construct(
        private readonly array $command,
        private readonly string $rootDir,
        ?callable $logger = null,
        private readonly int $maxWriteBuffer = NonBlockingWriter::DEFAULT_LIMIT,
    ) {
        $this->logger = $logger;
        $this->framer = new JsonRpcFramer();
    }

    public function start(): bool
    {
        if ([] === $this->command || '' === $this->command[0]) {
            return false; // a malformed adapter setting must not crash proc_open
        }
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        /** @var non-empty-string $cwd */
        $cwd = $this->rootDir;
        $pipes = [];
        // @mago-expect lint:no-error-control-operator -- proc_open on a nonexistent/unusable binary emits a spawn ("posix_spawn() failed") warning that guarding the inputs cannot reliably prevent; the false return is handled below.
        $process = @proc_open($this->command, $descriptors, $pipes, $cwd);
        if (!\is_resource($process)) {
            return false;
        }

        $this->process = $process;
        /** @var array{0: resource, 1: resource, 2: resource} $pipes */
        [$this->stdin, $this->stdout, $this->stderr] = [$pipes[0], $pipes[1], $pipes[2]];
        // stdin non-blocking too: an adapter that stops draining its stdin must
        // never park the whole event loop inside a blocking fwrite.
        stream_set_blocking($this->stdin, enable: false);
        stream_set_blocking($this->stdout, enable: false);
        stream_set_blocking($this->stderr, enable: false);
        $this->writer = new NonBlockingWriter($this->stdin, $this->onWriteError(...), $this->maxWriteBuffer);

        $this->stdoutWatcher = EventLoop::onReadable($this->stdout, $this->drainStdout(...));
        $this->stderrWatcher = EventLoop::onReadable($this->stderr, function (): void {
            /** @var resource $stderr */
            $stderr = $this->stderr;
            $data = \is_resource($stderr) ? fread($stderr, length: 8192) : false;
            if (\is_string($data) && '' !== $data && null !== $this->logger) {
                ($this->logger)(rtrim($data));
            }
        });

        return true;
    }

    public function onEvent(string $event, callable $handler): void
    {
        $this->eventHandlers[$event] = $handler;
    }

    public function onClose(callable $handler): void
    {
        $this->onClose = $handler;
    }

    public function request(string $command, array $arguments = [], ?callable $onResult = null): void
    {
        $seq = $this->nextSeq++;
        if (null !== $onResult) {
            $this->pending[$seq] = $onResult;
        }
        $this->send(['seq' => $seq, 'type' => 'request', 'command' => $command, 'arguments' => (object) $arguments]);
    }

    public function isRunning(): bool
    {
        return null !== $this->process && proc_get_status($this->process)['running'];
    }

    public function stop(): void
    {
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

                fclose($pipe);
            }
            if (\is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
            $this->process = null;
        }
        $this->pending = [];
    }

    private function drainStdout(): void
    {
        if (!\is_resource($this->stdout)) {
            return;
        }
        $data = fread($this->stdout, length: 65_536);
        if (!\is_string($data) || '' === $data) {
            if (feof($this->stdout)) {
                $this->log('debug adapter closed its stdout');
                if (null !== $this->stdoutWatcher) {
                    EventLoop::cancel($this->stdoutWatcher);
                    $this->stdoutWatcher = null;
                }
                if (!$this->closed) {
                    $this->closed = true;
                    if (null !== $this->onClose) {
                        ($this->onClose)();
                    }
                }
            }

            return;
        }

        foreach ($this->framer->feed($data) as $message) {
            $this->handleMessage($message);
        }
    }

    /** @param array<array-key, mixed> $message */
    private function handleMessage(array $message): void
    {
        /** @var mixed $type */
        $type = $message['type'] ?? null;
        if ('response' === $type) {
            $seq = \is_int($message['request_seq'] ?? null) ? $message['request_seq'] : -1;
            $callback = $this->pending[$seq] ?? null;
            unset($this->pending[$seq]);
            if (null !== $callback) {
                /** @var array<string, mixed> $body */
                $body = \is_array($message['body'] ?? null) ? $message['body'] : [];
                $success = true === ($message['success'] ?? false);
                $error = \is_string($message['message'] ?? null) ? $message['message'] : 'request failed';
                $callback($body, $success ? null : $error);
            }

            return;
        }
        if ('event' === $type) {
            $name = \is_string($message['event'] ?? null) ? $message['event'] : '';
            $handler = $this->eventHandlers[$name] ?? null;
            if (null !== $handler) {
                /** @var array<string, mixed> $body */
                $body = \is_array($message['body'] ?? null) ? $message['body'] : [];
                $handler($body);
            }

            return;
        }
        if ('request' === $type) {
            // Reverse request (e.g. runInTerminal) — we advertise no support.
            $this->send([
                'seq' => $this->nextSeq++,
                'type' => 'response',
                'request_seq' => \is_int($message['seq'] ?? null) ? $message['seq'] : 0,
                'command' => \is_string($message['command'] ?? null) ? $message['command'] : '',
                'success' => false,
                'message' => 'unsupported',
            ]);
        }
    }

    /** @param array<string, mixed> $message */
    private function send(array $message): void
    {
        $this->writer?->write(JsonRpcFramer::encode($message));
    }

    /**
     * A stdin write failed (dead adapter) or the outbound queue overflowed
     * (wedged adapter). Terminating the child converges both on the existing
     * stdout-EOF path, so {@see onClose} fires exactly like any adapter crash.
     */
    private function onWriteError(string $reason): void
    {
        $this->log('write to debug adapter failed: '.$reason);
        if (\is_resource($this->process)) {
            proc_terminate($this->process);
        }
    }

    private function log(string $line): void
    {
        if (null !== $this->logger) {
            ($this->logger)($line);
        }
    }
}
