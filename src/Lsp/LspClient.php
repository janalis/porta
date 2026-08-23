<?php

declare(strict_types=1);

namespace Porta\Lsp;

use Porta\Support\JsonRpcTransport;
use Porta\Support\NonBlockingWriter;
use Porta\Support\Outcome;

/**
 * Minimal asynchronous Language Server Protocol client.
 *
 * Talks JSON-RPC 2.0 over the stdio of a spawned server process (the pipe
 * plumbing and Content-Length framing live in {@see JsonRpcTransport}). Reads
 * are driven by the Revolt event loop (the same loop symfony/tui runs on), so
 * responses and server notifications arrive without blocking the UI.
 */
final class LspClient implements LspClientInterface
{
    private readonly JsonRpcTransport $transport;

    private int $nextId = 1;
    /** @var array<int, callable(mixed, ?array): void> id => callback(result, error) */
    private array $pending = [];

    /** @var array<string, callable(array): void> notification method => handler */
    private array $notificationHandlers = [];

    private bool $initialized = false;

    /** @var array<string, mixed> capabilities advertised by the server's initialize result */
    private array $serverCapabilities = [];

    /** @var list<callable(): void> queued actions until initialize completes */
    private array $whenReady = [];

    /** @var (callable(string): void)|null */
    private $logger;

    /** @var (callable(array): bool)|null handler for server-initiated workspace/applyEdit */
    private $applyEditHandler = null;

    /**
     * @param list<string>              $command               e.g. ['phpactor', 'language-server']
     * @param array<string, mixed>|null $initializationOptions server-specific `initialize` options
     *                                                         (an LSP-provider plugin's settings travel here)
     */
    public function __construct(
        array $command,
        private readonly string $rootDir,
        ?callable $logger = null,
        int $maxWriteBuffer = NonBlockingWriter::DEFAULT_LIMIT,
        private readonly ?array $initializationOptions = null,
    ) {
        $this->logger = $logger;
        $this->transport = new JsonRpcTransport($command, $rootDir, 'LSP server', $logger, $maxWriteBuffer);
    }

    #[\Override]
    public function isRunning(): bool
    {
        return $this->transport->isRunning();
    }

    #[\Override]
    public function isInitialized(): bool
    {
        return $this->initialized;
    }

    /** @return array<string, mixed> the server's advertised capabilities (empty until initialized) */
    #[\Override]
    public function serverCapabilities(): array
    {
        return $this->serverCapabilities;
    }

    #[\Override]
    public function onNotification(string $method, callable $handler): void
    {
        $this->notificationHandlers[$method] = $handler;
    }

    /**
     * @param callable(array): bool $handler receives the WorkspaceEdit, returns whether it was applied
     */
    #[\Override]
    public function onApplyEdit(callable $handler): void
    {
        $this->applyEditHandler = $handler;
    }

    /**
     * Spawn the server and run the initialize handshake.
     *
     * @param callable(Outcome): void $onReady called with true on success
     */
    #[\Override]
    public function start(callable $onReady): void
    {
        $started = $this->transport->start(function (array $message): void {
            /** @var array{id?: mixed, method?: string, result?: mixed, error?: array<array-key, mixed>, params?: array{items?: array<array-key, mixed>, edit?: array<array-key, mixed>}} $message */
            $this->handleMessage($message);
        });
        if (!$started) {
            $onReady(Outcome::Failed);

            return;
        }

        $this->request(
            'initialize',
            $this->initializeParams(),
            function (mixed $result, ?array $error) use ($onReady): void {
                if (null !== $error) {
                    $this->log('initialize failed: '.(string) json_encode($error));
                    $onReady(Outcome::Failed);

                    return;
                }
                /** @var array<string, mixed> $capabilities */
                $capabilities = \is_array($result) && \is_array($result['capabilities'] ?? null) ? $result['capabilities'] : [];
                $this->serverCapabilities = $capabilities;
                $this->notify('initialized', new \stdClass());
                $this->initialized = true;
                foreach ($this->whenReady as $action) {
                    $action();
                }
                $this->whenReady = [];
                $onReady(Outcome::Ok);
            },
        );
    }

    /**
     * The `initialize` request params: process/workspace identity plus the
     * capabilities we advertise.
     *
     * @return array<string, mixed>
     */
    private function initializeParams(): array
    {
        $rootUri = 'file://'.str_replace(\DIRECTORY_SEPARATOR, replace: '/', subject: $this->rootDir);

        $params = [
            'processId' => getmypid(),
            'rootUri' => $rootUri,
            'workspaceFolders' => [['uri' => $rootUri, 'name' => basename($this->rootDir)]],
            'clientInfo' => ['name' => 'porta', 'version' => '0.1.0'],
            'capabilities' => $this->clientCapabilities(),
        ];
        if (null !== $this->initializationOptions) {
            $params['initializationOptions'] = $this->initializationOptions;
        }

        return $params;
    }

    /**
     * The textDocument/workspace features this client supports, sent in the
     * initialize handshake.
     *
     * @return array<string, mixed>
     */
    private function clientCapabilities(): array
    {
        return [
            'textDocument' => [
                'synchronization' => ['didSave' => true],
                'completion' => [
                    'completionItem' => ['snippetSupport' => true, 'documentationFormat' => ['plaintext', 'markdown']],
                ],
                'publishDiagnostics' => ['relatedInformation' => false],
                'hover' => ['contentFormat' => ['plaintext', 'markdown']],
                'definition' => ['linkSupport' => false],
                'references' => ['dynamicRegistration' => false],
                'rename' => ['prepareSupport' => true],
                'signatureHelp' => ['signatureInformation' => ['documentationFormat' => ['plaintext']]],
                'codeAction' => [
                    'codeActionLiteralSupport' => ['codeActionKind' => ['valueSet' => ['quickfix', 'refactor', 'source']]],
                ],
                'documentSymbol' => ['hierarchicalDocumentSymbolSupport' => true],
                'foldingRange' => ['lineFoldingOnly' => true],
                'callHierarchy' => ['dynamicRegistration' => false],
                'formatting' => ['dynamicRegistration' => false],
            ],
            'workspace' => ['configuration' => false, 'workspaceFolders' => true, 'symbol' => ['dynamicRegistration' => false]],
        ];
    }

    /**
     * Run $action now if initialized, otherwise queue it.
     */
    #[\Override]
    public function whenReady(callable $action): void
    {
        if ($this->initialized) {
            $action();

            return;
        }
        $this->whenReady[] = $action;
    }

    /**
     * @param callable(mixed, ?array): void $onResult called with (result, error)
     */
    #[\Override]
    public function request(string $method, mixed $params, callable $onResult): void
    {
        $id = $this->nextId++;
        $this->pending[$id] = $onResult;
        $this->send(['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params]);
    }

    #[\Override]
    public function notify(string $method, mixed $params): void
    {
        $this->send(['jsonrpc' => '2.0', 'method' => $method, 'params' => $params]);
    }

    #[\Override]
    public function stop(): void
    {
        if ($this->transport->isRunning()) {
            // Best-effort polite shutdown (send() never throws), then terminate
            $this->send(['jsonrpc' => '2.0', 'id' => $this->nextId++, 'method' => 'shutdown', 'params' => null]);
            $this->send(['jsonrpc' => '2.0', 'method' => 'exit', 'params' => null]);
        }
        $this->transport->stop();

        $this->initialized = false;
        $this->serverCapabilities = [];
        $this->pending = [];
    }

    private function send(array $message): void
    {
        $this->transport->send($message);
    }

    /**
     * @param array{id?: mixed, method?: string, result?: mixed, error?: array<array-key, mixed>, params?: array{items?: array<array-key, mixed>, edit?: array<array-key, mixed>}} $message
     */
    private function handleMessage(array $message): void
    {
        // Response to one of our requests
        if (($message['id'] ?? null) !== null && ($message['method'] ?? null) === null) {
            $id = (int) $message['id'];
            $callback = $this->pending[$id] ?? null;
            unset($this->pending[$id]);
            if (null !== $callback) {
                $callback($message['result'] ?? null, $message['error'] ?? null);
            }

            return;
        }

        // Request from the server: answer with a sane default so it does not hang
        // (an id-bearing message without a method already returned above).
        if (($message['id'] ?? null) !== null) {
            $result = match ($message['method']) {
                'workspace/configuration' => array_fill(0, \count($message['params']['items'] ?? [null]), value: null),
                'window/workDoneProgress/create', 'client/registerCapability', 'client/unregisterCapability' => null,
                'workspace/applyEdit' => [
                    'applied' => null !== $this->applyEditHandler && ($this->applyEditHandler)($message['params']['edit'] ?? []),
                ],
                default => null,
            };
            $this->send(['jsonrpc' => '2.0', 'id' => $message['id'], 'result' => $result]);

            return;
        }

        // Notification from the server
        if (($message['method'] ?? null) !== null) {
            $handler = $this->notificationHandlers[$message['method']] ?? null;
            if (null !== $handler) {
                $handler($message['params'] ?? []);
            }
        }
    }

    private function log(string $message): void
    {
        if (null !== $this->logger) {
            ($this->logger)($message);
        }
    }
}
