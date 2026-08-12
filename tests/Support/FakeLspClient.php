<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use Porta\Lsp\LspClientInterface;
use Porta\Support\Outcome;

/**
 * In-memory {@see LspClientInterface} for unit-testing {@see \Porta\Lsp\LspSession}
 * without spawning a language-server process. Records every notify/request and
 * lets a test simulate the initialize handshake synchronously.
 */
class FakeLspClient implements LspClientInterface
{
    public bool $started = false;
    public bool $stopped = false;
    private bool $initialized = false;

    /** @var array<string, mixed> */
    public array $capabilities = [];

    /** @var list<array{method: string, params: mixed}> */
    public array $notifications = [];
    /** @var list<array{method: string, params: mixed, respond?: \Closure}> */
    public array $requests = [];

    /** @var array<string, callable> */
    public array $notificationHandlers = [];
    /** @var (callable(array): bool)|null */
    public $applyEditHandler = null;

    /** @var list<callable(): void> */
    private array $queued = [];

    /**
     * @param array<string, mixed> $capabilities server capabilities to advertise once initialized
     */
    public function __construct(array $capabilities = [])
    {
        $this->capabilities = $capabilities;
    }

    public function isRunning(): bool
    {
        return $this->started && !$this->stopped;
    }

    public function isInitialized(): bool
    {
        return $this->initialized;
    }

    public function serverCapabilities(): array
    {
        return $this->capabilities;
    }

    public function onNotification(string $method, callable $handler): void
    {
        $this->notificationHandlers[$method] = $handler;
    }

    public function onApplyEdit(callable $handler): void
    {
        $this->applyEditHandler = $handler;
    }

    public function start(callable $onReady): void
    {
        $this->started = true;
        $this->initialized = true;
        foreach ($this->queued as $action) {
            $action();
        }
        $this->queued = [];
        $onReady(Outcome::Ok);
    }

    public function whenReady(callable $action): void
    {
        if ($this->initialized) {
            $action();

            return;
        }
        $this->queued[] = $action;
    }

    public function request(string $method, mixed $params, callable $onResult): void
    {
        $this->requests[] = ['method' => $method, 'params' => $params, 'respond' => $onResult(...)];
    }

    public function notify(string $method, mixed $params): void
    {
        $this->notifications[] = ['method' => $method, 'params' => $params];
    }

    public function stop(): void
    {
        $this->stopped = true;
        $this->initialized = false;
    }
}
