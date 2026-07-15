<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use Porta\Debug\DapClientInterface;

/**
 * In-memory {@see DapClientInterface} for unit-testing {@see \Porta\Debug\DapSession}
 * without a process. Records every request; a test scripts responses per
 * command (or leaves them pending) and pushes events/close synchronously.
 */
class FakeDapClient implements DapClientInterface
{
    public bool $started = false;
    public bool $stopped = false;
    public bool $failToStart = false;

    /** @var list<array{command: string, arguments: array<string, mixed>}> */
    public array $requests = [];
    /** @var array<string, array{0: array<string, mixed>, 1: ?string}> command => scripted [body, error] */
    public array $responses = [];
    /** @var list<array{command: string, callback: callable(array<string, mixed>, ?string): void}> */
    public array $pending = [];

    /** @var array<string, callable(array<string, mixed>): void> */
    private array $eventHandlers = [];
    /** @var (callable(): void)|null */
    private $onClose = null;

    public function start(): bool
    {
        if ($this->failToStart) {
            return false;
        }

        return $this->started = true;
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
        $this->requests[] = ['command' => $command, 'arguments' => $arguments];
        if (null === $onResult) {
            return;
        }
        if (array_key_exists($command, $this->responses)) {
            [$body, $error] = $this->responses[$command];
            $onResult($body, $error);

            return;
        }
        $this->pending[] = ['command' => $command, 'callback' => $onResult];
    }

    public function isRunning(): bool
    {
        return $this->started && !$this->stopped;
    }

    public function stop(): void
    {
        $this->stopped = true;
    }

    /** Deliver the response for the oldest pending request of $command. */
    public function respond(string $command, array $body = [], ?string $error = null): void
    {
        foreach ($this->pending as $i => $entry) {
            if ($entry['command'] !== $command) {
                continue;
            }

            array_splice($this->pending, $i, length: 1);
            $entry['callback']($body, $error);

            return;
        }
    }

    /** Push a server event into the session. */
    public function emit(string $event, array $body = []): void
    {
        if (array_key_exists($event, $this->eventHandlers)) {
            $this->eventHandlers[$event]($body);
        }
    }

    /** Simulate the adapter's stdout closing. */
    public function close(): void
    {
        if (null !== $this->onClose) {
            ($this->onClose)();
        }
    }

    /** @return list<string> */
    public function commands(): array
    {
        return array_column($this->requests, 'command');
    }

    /** @return array<string, mixed> the arguments of the most recent $command request */
    public function argumentsOf(string $command): array
    {
        foreach (array_reverse($this->requests) as $request) {
            if ($request['command'] === $command) {
                return $request['arguments'];
            }
        }

        return [];
    }
}
