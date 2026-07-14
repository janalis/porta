<?php

declare(strict_types=1);

namespace Porta\Debug;

/**
 * Transport to one Debug Adapter Protocol process. Implemented over child
 * stdio by {@see DapClient}; tests substitute a scripted fake.
 */
interface DapClientInterface
{
    /** Spawn the adapter; false when the process could not be started. */
    public function start(): bool;

    /** Register a handler for a server-pushed event ('stopped', 'output', …). */
    public function onEvent(string $event, callable $handler): void;

    /** Register the single handler fired once when the adapter's stdout closes. */
    public function onClose(callable $handler): void;

    /**
     * Send a request; $onResult receives (body, error-message-or-null).
     *
     * @param array<string, mixed>                 $arguments
     * @param (callable(array, ?string): void)|null $onResult
     */
    public function request(string $command, array $arguments = [], ?callable $onResult = null): void;

    public function isRunning(): bool;

    /** Tear down watchers, pipes and the process. Idempotent. */
    public function stop(): void;
}
