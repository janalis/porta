<?php

declare(strict_types=1);

namespace Porta\Lsp;

/**
 * The transport surface an {@see LspSession} drives. Extracting it lets the
 * session (and the feature controllers above it) be unit-tested against an
 * in-memory fake instead of a spawned server process.
 */
interface LspClientInterface
{
    public function isRunning(): bool;

    public function isInitialized(): bool;

    /** @return array<string, mixed> the server's advertised capabilities (empty until initialized) */
    public function serverCapabilities(): array;

    public function onNotification(string $method, callable $handler): void;

    /**
     * @param callable(array): bool $handler receives the WorkspaceEdit, returns whether it was applied
     */
    public function onApplyEdit(callable $handler): void;

    /**
     * @param callable(bool): void $onReady called with true on success
     */
    public function start(callable $onReady): void;

    public function whenReady(callable $action): void;

    /**
     * @param callable(mixed, ?array): void $onResult called with (result, error)
     */
    public function request(string $method, mixed $params, callable $onResult): void;

    public function notify(string $method, mixed $params): void;

    public function stop(): void;
}
