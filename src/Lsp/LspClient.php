<?php

declare(strict_types=1);

namespace Porta\Lsp;

use Revolt\EventLoop;

/**
 * Minimal asynchronous Language Server Protocol client.
 *
 * Talks JSON-RPC 2.0 over the stdio of a spawned server process. Reads are
 * driven by the Revolt event loop (the same loop symfony/tui runs on), so
 * responses and server notifications arrive without blocking the UI.
 */
final class LspClient implements LspClientInterface
{
    /** @var resource|null */
    private $process;
    /** @var resource|null */
    private $stdin;
    /** @var resource|null */
    private $stdout;
    /** @var resource|null */
    private $stderr;

    private string $readBuffer = '';
    private ?string $stdoutWatcher = null;
    private ?string $stderrWatcher = null;

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
     * @param list<string> $command e.g. ['phpactor', 'language-server']
     */
    public function __construct(
        private readonly array $command,
        private readonly string $rootDir,
        ?callable $logger = null,
    ) {
        $this->logger = $logger;
    }

    public function isRunning(): bool
    {
        return null !== $this->process && proc_get_status($this->process)['running'];
    }

    public function isInitialized(): bool
    {
        return $this->initialized;
    }

    /** @return array<string, mixed> the server's advertised capabilities (empty until initialized) */
    public function serverCapabilities(): array
    {
        return $this->serverCapabilities;
    }

    public function onNotification(string $method, callable $handler): void
    {
        $this->notificationHandlers[$method] = $handler;
    }

    /**
     * @param callable(array): bool $handler receives the WorkspaceEdit, returns whether it was applied
     */
    public function onApplyEdit(callable $handler): void
    {
        $this->applyEditHandler = $handler;
    }

    /**
     * Spawn the server and run the initialize handshake.
     *
     * @param callable(bool): void $onReady called with true on success
     */
    public function start(callable $onReady): void
    {
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $pipes = [];
        /** @var non-empty-string $cwd */
        $cwd = $this->rootDir;
        $process = @proc_open($this->command, $descriptors, $pipes, $cwd);

        if (!\is_resource($process)) {
            $onReady(false);

            return;
        }

        $this->process = $process;
        /** @var array{0: resource, 1: resource, 2: resource} $pipes */
        [$this->stdin, $this->stdout, $this->stderr] = [$pipes[0], $pipes[1], $pipes[2]];
        stream_set_blocking($this->stdout, false);
        stream_set_blocking($this->stderr, false);

        $this->stdoutWatcher = EventLoop::onReadable($this->stdout, fn () => $this->drainStdout());
        $this->stderrWatcher = EventLoop::onReadable($this->stderr, function (): void {
            /** @var resource $stderr */
            $stderr = $this->stderr;
            $data = @fread($stderr, 8192);
            if (\is_string($data) && '' !== $data && null !== $this->logger) {
                ($this->logger)(rtrim($data));
            }
        });

        $rootUri = 'file://'.str_replace(\DIRECTORY_SEPARATOR, '/', $this->rootDir);

        $this->request(
            'initialize',
            [
                'processId' => getmypid(),
                'rootUri' => $rootUri,
                'workspaceFolders' => [['uri' => $rootUri, 'name' => basename($this->rootDir)]],
                'clientInfo' => ['name' => 'porta', 'version' => '0.1.0'],
                'capabilities' => [
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
                        'formatting' => ['dynamicRegistration' => false],
                    ],
                    'workspace' => ['configuration' => false, 'workspaceFolders' => true, 'symbol' => ['dynamicRegistration' => false]],
                ],
            ],
            function (mixed $result, ?array $error) use ($onReady): void {
                if (null !== $error) {
                    $this->log('initialize failed: '.(string) json_encode($error));
                    $onReady(false);

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
                $onReady(true);
            },
        );
    }

    /**
     * Run $action now if initialized, otherwise queue it.
     */
    public function whenReady(callable $action): void
    {
        if ($this->initialized) {
            $action();
        } else {
            $this->whenReady[] = $action;
        }
    }

    /**
     * @param callable(mixed, ?array): void $onResult called with (result, error)
     */
    public function request(string $method, mixed $params, callable $onResult): void
    {
        $id = $this->nextId++;
        $this->pending[$id] = $onResult;
        $this->send(['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params]);
    }

    public function notify(string $method, mixed $params): void
    {
        $this->send(['jsonrpc' => '2.0', 'method' => $method, 'params' => $params]);
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
            // Best-effort polite shutdown (send() never throws), then terminate
            $this->send(['jsonrpc' => '2.0', 'id' => $this->nextId++, 'method' => 'shutdown', 'params' => null]);
            $this->send(['jsonrpc' => '2.0', 'method' => 'exit', 'params' => null]);

            foreach ([$this->stdin, $this->stdout, $this->stderr] as $pipe) {
                if (\is_resource($pipe)) {
                    @fclose($pipe);
                }
            }
            @proc_terminate($process);
            @proc_close($process);
            $this->process = null;
        }

        $this->initialized = false;
        $this->serverCapabilities = [];
        $this->pending = [];
    }

    // --- Wire protocol ------------------------------------------------------

    private function send(array $message): void
    {
        if (!\is_resource($this->stdin)) {
            return;
        }
        $json = json_encode($message, JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE);
        $frame = 'Content-Length: '.\strlen($json)."\r\n\r\n".$json;

        $total = \strlen($frame);
        $written = 0;
        while ($written < $total) {
            $n = @fwrite($this->stdin, substr($frame, $written));
            if (false === $n || 0 === $n) {
                $this->log('write to LSP server failed');

                return;
            }
            $written += $n;
        }
        @fflush($this->stdin);
    }

    private function drainStdout(): void
    {
        if (!\is_resource($this->stdout)) {
            return;
        }
        $data = @fread($this->stdout, 65536);
        if (!\is_string($data) || '' === $data) {
            if (feof($this->stdout)) {
                $this->log('LSP server closed its stdout');
                if (null !== $this->stdoutWatcher) {
                    EventLoop::cancel($this->stdoutWatcher);
                    $this->stdoutWatcher = null;
                }
            }

            return;
        }

        $this->readBuffer .= $data;

        while (true) {
            $headerEnd = strpos($this->readBuffer, "\r\n\r\n");
            if (false === $headerEnd) {
                return;
            }

            $headers = substr($this->readBuffer, 0, $headerEnd);
            $m = [];
            if (!preg_match('/Content-Length:\s*(\d+)/i', $headers, $m)) {
                // Corrupt frame; drop the header block and resync
                $this->readBuffer = substr($this->readBuffer, $headerEnd + 4);
                continue;
            }

            $length = (int) $m[1];
            $bodyStart = $headerEnd + 4;
            if (\strlen($this->readBuffer) < ($bodyStart + $length)) {
                return; // wait for more data
            }

            $body = substr($this->readBuffer, $bodyStart, $length);
            $this->readBuffer = substr($this->readBuffer, $bodyStart + $length);

            /** @var array{id?: mixed, method?: string, result?: mixed, error?: array<array-key, mixed>, params?: array{items?: array<array-key, mixed>, edit?: array<array-key, mixed>}}|null $message */
            $message = json_decode($body, true);
            if (\is_array($message)) {
                $this->handleMessage($message);
            }
        }
    }

    /**
     * @param array{id?: mixed, method?: string, result?: mixed, error?: array<array-key, mixed>, params?: array{items?: array<array-key, mixed>, edit?: array<array-key, mixed>}} $message
     */
    private function handleMessage(array $message): void
    {
        // Response to one of our requests
        if (isset($message['id']) && !isset($message['method'])) {
            $id = (int) $message['id'];
            $callback = $this->pending[$id] ?? null;
            unset($this->pending[$id]);
            if (null !== $callback) {
                $callback($message['result'] ?? null, $message['error'] ?? null);
            }

            return;
        }

        // Request from the server: answer with a sane default so it does not hang
        if (isset($message['id'], $message['method'])) {
            $result = match ($message['method']) {
                'workspace/configuration' => array_fill(0, \count($message['params']['items'] ?? [null]), null),
                'window/workDoneProgress/create' => null,
                'client/registerCapability', 'client/unregisterCapability' => null,
                'workspace/applyEdit' => [
                    'applied' => null !== $this->applyEditHandler && ($this->applyEditHandler)($message['params']['edit'] ?? []),
                ],
                default => null,
            };
            $this->send(['jsonrpc' => '2.0', 'id' => $message['id'], 'result' => $result]);

            return;
        }

        // Notification from the server
        if (isset($message['method'])) {
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
