<?php

declare(strict_types=1);

namespace Porta\Debug;

use Porta\Support\JsonRpcTransport;
use Porta\Support\NonBlockingWriter;
use Porta\Support\PendingRequests;
use Revolt\EventLoop;

/**
 * Asynchronous Debug Adapter Protocol transport over a child process's stdio,
 * driven by the shared Revolt loop (the DAP wire format reuses LSP's
 * Content-Length framing and pipe plumbing via {@see JsonRpcTransport}; only
 * the message envelope differs). Reverse requests (e.g. runInTerminal) are answered with a failure —
 * the initialize handshake advertises no support for them.
 *
 * Nothing waits on an adapter forever: a request unanswered in
 * {@see REQUEST_TIMEOUT} fails, and when the adapter stops or dies every
 * request still waiting fails on the next tick — as does one asked after.
 */
final class DapClient implements DapClientInterface
{
    private readonly JsonRpcTransport $transport;

    /** Seconds a request may go unanswered before it fails. */
    public const float REQUEST_TIMEOUT = 30.0;

    /** Seconds a stopped adapter has to end what it debugs, and itself, before it is terminated. */
    public const float EXIT_WITHIN = 1.0;

    private int $nextSeq = 1;
    /** @var PendingRequests<callable(array<string, mixed>, ?string): void> request seq => callback */
    private readonly PendingRequests $pending;
    /** @var array<string, callable(array<string, mixed>): void> event name => handler */
    private array $eventHandlers = [];
    /** @var (callable(): void)|null */
    private $onClose;
    private bool $closed = false;

    /** @param list<string> $command */
    public function __construct(
        array $command,
        string $rootDir,
        ?callable $logger = null,
        int $maxWriteBuffer = NonBlockingWriter::DEFAULT_LIMIT,
        private readonly float $requestTimeout = self::REQUEST_TIMEOUT,
    ) {
        $this->pending = new PendingRequests();
        $this->transport = new JsonRpcTransport($command, $rootDir, 'debug adapter', $logger, $maxWriteBuffer);
    }

    #[\Override]
    public function start(): bool
    {
        return $this->transport->start(
            $this->handleMessage(...),
            function (): void {
                $this->fail();
                if (null !== $this->onClose) {
                    ($this->onClose)();
                }
            },
        );
    }

    #[\Override]
    public function onEvent(string $event, callable $handler): void
    {
        $this->eventHandlers[$event] = $handler;
    }

    #[\Override]
    public function onClose(callable $handler): void
    {
        $this->onClose = $handler;
    }

    #[\Override]
    public function request(string $command, array $arguments = [], ?callable $onResult = null): void
    {
        if ($this->closed) {
            if (null !== $onResult) {
                EventLoop::queue($onResult(...), [], self::stopped());
            }

            return;
        }
        $seq = $this->nextSeq++;
        if (null !== $onResult) {
            $timeout = $this->requestTimeout;
            $this->pending->add($seq, $onResult, $timeout, static function (callable $callback) use ($command, $timeout): void {
                $callback([], \sprintf('the debug adapter did not answer %s in %s seconds', $command, $timeout));
            });
        }
        $this->send(['seq' => $seq, 'type' => 'request', 'command' => $command, 'arguments' => (object) $arguments]);
    }

    #[\Override]
    public function isRunning(): bool
    {
        return $this->transport->isRunning();
    }

    #[\Override]
    public function stop(): void
    {
        // A moment to act on what it was just sent — a disconnect that ends
        // the debuggee — before it is ended itself.
        $this->transport->stop(self::EXIT_WITHIN);
        $this->fail();
    }

    /** Stopped or dead: everything still waiting fails, on the next tick, and so does anything asked from now on. */
    private function fail(): void
    {
        $this->closed = true;
        foreach ($this->pending->drain() as $callback) {
            EventLoop::queue($callback(...), [], self::stopped());
        }
    }

    private static function stopped(): string
    {
        return 'the debug adapter stopped';
    }

    /** @param array<array-key, mixed> $message */
    private function handleMessage(array $message): void
    {
        /** @var mixed $type */
        $type = $message['type'] ?? null;
        if ('response' === $type) {
            $callback = $this->pending->take(\is_int($message['request_seq'] ?? null) ? $message['request_seq'] : -1);
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
        $this->transport->send($message);
    }
}
