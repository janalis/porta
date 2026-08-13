<?php

declare(strict_types=1);

namespace Porta\Debug;

use Porta\Support\JsonRpcTransport;
use Porta\Support\NonBlockingWriter;

/**
 * Asynchronous Debug Adapter Protocol transport over a child process's stdio,
 * driven by the shared Revolt loop (the DAP wire format reuses LSP's
 * Content-Length framing and pipe plumbing via {@see JsonRpcTransport}; only
 * the message envelope differs). Reverse requests (e.g. runInTerminal) are answered with a failure —
 * the initialize handshake advertises no support for them.
 */
final class DapClient implements DapClientInterface
{
    private readonly JsonRpcTransport $transport;

    private int $nextSeq = 1;
    /** @var array<int, callable(array<string, mixed>, ?string): void> request seq => callback */
    private array $pending = [];
    /** @var array<string, callable(array<string, mixed>): void> event name => handler */
    private array $eventHandlers = [];
    /** @var (callable(): void)|null */
    private $onClose = null;
    private bool $closed = false;

    /** @param list<string> $command */
    public function __construct(array $command, string $rootDir, ?callable $logger = null, int $maxWriteBuffer = NonBlockingWriter::DEFAULT_LIMIT)
    {
        $this->transport = new JsonRpcTransport($command, $rootDir, 'debug adapter', $logger, $maxWriteBuffer);
    }

    #[\Override]
    public function start(): bool
    {
        return $this->transport->start(
            $this->handleMessage(...),
            function (): void {
                if (!$this->closed) {
                    $this->closed = true;
                    if (null !== $this->onClose) {
                        ($this->onClose)();
                    }
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
        $seq = $this->nextSeq++;
        if (null !== $onResult) {
            $this->pending[$seq] = $onResult;
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
        $this->transport->stop();
        $this->pending = [];
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
        $this->transport->send($message);
    }
}
