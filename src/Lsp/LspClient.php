<?php

declare(strict_types=1);

namespace Porta\Lsp;

use Porta\Support\JsonRpcFramer;
use Porta\Support\NonBlockingWriter;
use Porta\Support\Outcome;
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

    private readonly JsonRpcFramer $framer;
    private ?string $stdoutWatcher = null;
    private ?string $stderrWatcher = null;
    /** Queued non-blocking stdin writes — a wedged server must never block the event loop. */
    private ?NonBlockingWriter $writer = null;

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
        private readonly int $maxWriteBuffer = NonBlockingWriter::DEFAULT_LIMIT,
    ) {
        $this->logger = $logger;
        $this->framer = new JsonRpcFramer();
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
     * @param callable(Outcome): void $onReady called with true on success
     */
    public function start(callable $onReady): void
    {
        $pipes = [];
        $process = $this->openServerProcess($pipes);
        if (null === $process) {
            $onReady(Outcome::Failed);

            return;
        }

        $this->process = $process;
        $this->attachStreamWatchers($pipes);

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
     * Spawn the server over three stdio pipes. Returns the process handle, or
     * null when proc_open fails; $pipes is populated with [stdin, stdout, stderr].
     *
     * @param array<int, resource> $pipes
     *
     * @return resource|null
     */
    private function openServerProcess(array &$pipes)
    {
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        /** @var non-empty-string $cwd */
        $cwd = $this->rootDir;
        // @mago-expect lint:no-error-control-operator -- proc_open on a nonexistent/unusable binary emits a spawn ("posix_spawn() failed") warning that guarding the inputs cannot reliably prevent; the false return is handled below.
        $process = @proc_open($this->command, $descriptors, $pipes, $cwd);

        return \is_resource($process) ? $process : null;
    }

    /**
     * Keep the pipes, put reads in non-blocking mode and register the event-loop
     * watchers that drain stdout (JSON-RPC) and forward stderr to the logger.
     *
     * @param array<int, resource> $pipes the [stdin, stdout, stderr] from openServerProcess
     */
    private function attachStreamWatchers(array $pipes): void
    {
        [$this->stdin, $this->stdout, $this->stderr] = [$pipes[0], $pipes[1], $pipes[2]];
        // stdin non-blocking too: a server that stops draining its stdin must
        // never park the whole event loop inside a blocking fwrite (the classic
        // stdio deadlock once a frame exceeds the OS pipe buffer).
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

        return [
            'processId' => getmypid(),
            'rootUri' => $rootUri,
            'workspaceFolders' => [['uri' => $rootUri, 'name' => basename($this->rootDir)]],
            'clientInfo' => ['name' => 'porta', 'version' => '0.1.0'],
            'capabilities' => $this->clientCapabilities(),
        ];
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

        $this->initialized = false;
        $this->serverCapabilities = [];
        $this->pending = [];
    }

    // --- Wire protocol ------------------------------------------------------

    private function send(array $message): void
    {
        $this->writer?->write(JsonRpcFramer::encode($message));
    }

    /**
     * A stdin write failed (dead server) or the outbound queue overflowed
     * (wedged server). Terminating the child converges both on the existing
     * stdout-EOF path, so the session ends exactly like any server crash.
     */
    private function onWriteError(string $reason): void
    {
        $this->log('write to LSP server failed: '.$reason);
        if (\is_resource($this->process)) {
            proc_terminate($this->process);
        }
    }

    private function drainStdout(): void
    {
        if (!\is_resource($this->stdout)) {
            return;
        }
        $data = fread($this->stdout, length: 65_536);
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

        foreach ($this->framer->feed($data) as $message) {
            /** @var array{id?: mixed, method?: string, result?: mixed, error?: array<array-key, mixed>, params?: array{items?: array<array-key, mixed>, edit?: array<array-key, mixed>}} $message */
            $this->handleMessage($message);
        }
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
