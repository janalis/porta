<?php

declare(strict_types=1);

namespace Porta\Lsp;

use Porta\Support\JsonRpcTransport;
use Porta\Support\NonBlockingWriter;
use Porta\Support\OrphanGuard;
use Porta\Support\Outcome;
use Porta\Support\PendingRequests;
use Revolt\EventLoop;

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
    /** Seconds a request may go unanswered before it fails — and is cancelled at the server. */
    public const float REQUEST_TIMEOUT = 30.0;

    /** Seconds a server told to exit has to do it its own way before it is terminated. */
    public const float EXIT_WITHIN = 1.0;

    /** Seconds a server has to answer `initialize` before it counts as failed to start. */
    public const float INITIALIZE_TIMEOUT = 30.0;

    /** JSON-RPC's code for a request cancelled before its answer — here, one nobody answered. */
    private const int CANCELLED = -32_800;

    private readonly JsonRpcTransport $transport;

    private int $nextId = 1;

    /** @var PendingRequests<callable(mixed, ?array): void> id => callback(result, error) */
    private readonly PendingRequests $pending;

    /** Stopped, or dead: from here on every request fails at once and nothing waits for a ready that cannot come. */
    private bool $stopped = false;

    private bool $failureReported = false;

    /** @var array<string, callable(array): void> notification method => handler */
    private array $notificationHandlers = [];

    public private(set) bool $isInitialized = false;

    /** @var array<string, mixed> capabilities advertised by the server's initialize result */
    public private(set) array $serverCapabilities = [];

    /** @var array<string, list<array{glob: string, kind: int}>> registration id => its `workspace/didChangeWatchedFiles` watchers */
    private array $watcherRegistrations = [];

    /**
     * The files the server asked to hear about: each glob, relative to the
     * project root, with the LSP `WatchKind` bits (1 create, 2 change, 4
     * delete) it wants. Empty until the server registers some.
     *
     * @var list<array{glob: string, kind: int}>
     */
    public array $fileWatchers {
        get => \array_merge(...\array_values($this->watcherRegistrations));
    }

    /** @var list<callable(): void> queued actions until initialize completes */
    private array $whenReady = [];

    /** @var (callable(string): void)|null */
    private $logger;

    /** @var (callable(array): bool)|null handler for server-initiated workspace/applyEdit */
    private $applyEditHandler;

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
        private readonly float $requestTimeout = self::REQUEST_TIMEOUT,
        private readonly float $initializeTimeout = self::INITIALIZE_TIMEOUT,
        ?OrphanGuard $guard = null,
    ) {
        $this->logger = $logger;
        $this->pending = new PendingRequests();
        $this->transport = new JsonRpcTransport($command, $rootDir, 'LSP server', $logger, $maxWriteBuffer, $guard);
    }

    #[\Override]
    public function isRunning(): bool
    {
        return $this->transport->isRunning();
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
        // Failure is reported ONCE, whichever way it came — a timed-out
        // initialize whose server then dies must not count as two failed starts.
        $onReady = function (Outcome $outcome) use ($onReady): void {
            if ($this->failureReported) {
                return;
            }
            $this->failureReported = !$outcome->isOk();
            $onReady($outcome);
        };
        $started = $this->transport->start(
            function (array $message): void {
                /** @var array{id?: mixed, method?: string, result?: mixed, error?: array<array-key, mixed>, params?: array{items?: array<array-key, mixed>, edit?: array<array-key, mixed>, registrations?: mixed, unregisterations?: mixed}} $message */
                $this->handleMessage($message);
            },
            function () use ($onReady): void {
                // The server closed stdout: it is gone. Before initialize
                // that is a deferred exec failure or an instant crash (a
                // spawn that never happened already failed below) and the
                // handshake can never complete; after initialize it is a
                // mid-session death, and the status must flip from the
                // server's name to "failed" rather than keep advertising a
                // dead process. $onReady reports a failure once, however it
                // came, which is what makes reusing it for both phases safe.
                // stop() cancels the watcher first, so a deliberate shutdown
                // never lands here, and the transport fires EOF at most once.
                $this->log($this->isInitialized ? 'server exited' : 'server exited before initialize completed');
                // Feature requests gate on isInitialized; a dead server must
                // decline them like an unserved language, and fail every
                // request still waiting on it rather than leave it waiting.
                $this->stop();
                $onReady(Outcome::Failed);
            },
        );
        if (!$started) {
            $this->stop();
            $onReady(Outcome::Failed);

            return;
        }

        $id = $this->nextId++;
        $this->await(
            $id,
            $this->initializeTimeout,
            'initialize',
            function (mixed $result, ?array $error) use ($onReady): void {
                if (null !== $error) {
                    // @noinspection JsonEncodingApiUsageInspection -- a log line: what cannot be encoded is no reason to throw
                    $this->log('initialize failed: '.(string) \json_encode($error));
                    $onReady(Outcome::Failed);

                    return;
                }
                /** @var array<string, mixed> $capabilities */
                $capabilities = \is_array($result) && \is_array($result['capabilities'] ?? null) ? $result['capabilities'] : [];
                $this->serverCapabilities = $capabilities;
                $this->notify('initialized', new \stdClass());
                $this->isInitialized = true;
                foreach ($this->whenReady as $action) {
                    $action();
                }
                $this->whenReady = [];
                $onReady(Outcome::Ok);
            },
        );
        $this->send(['jsonrpc' => '2.0', 'id' => $id, 'method' => 'initialize', 'params' => $this->initializeParams()]);
    }

    /**
     * The `initialize` request params: process/workspace identity plus the
     * capabilities we advertise.
     *
     * @return array<string, mixed>
     */
    private function initializeParams(): array
    {
        $rootUri = 'file://'.\str_replace(\DIRECTORY_SEPARATOR, replace: '/', subject: $this->rootDir);

        $params = [
            'processId' => \getmypid(),
            'rootUri' => $rootUri,
            'workspaceFolders' => [['uri' => $rootUri, 'name' => \basename($this->rootDir)]],
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
                    // Tag 1 is Deprecated: said so, a server marks the item and
                    // the popup draws it struck through instead of offering an
                    // API it is about to remove as if it were current.
                    'completionItem' => [
                        'snippetSupport' => true,
                        'documentationFormat' => ['plaintext', 'markdown'],
                        'tagSupport' => ['valueSet' => [CompletionDeprecation::TAG]],
                        'deprecatedSupport' => true,
                    ],
                ],
                // `tagSupport` says the editor can DRAW an unused or deprecated
                // symbol differently rather than only listing it. phpactor sends
                // the tags whether or not it is declared; a server that gates on
                // the declaration sends nothing without it, and it costs a line.
                'publishDiagnostics' => [
                    'relatedInformation' => false,
                    'tagSupport' => ['valueSet' => [DiagnosticTag::Unnecessary->value, DiagnosticTag::Deprecated->value]],
                ],
                'hover' => ['contentFormat' => ['plaintext', 'markdown']],
                'definition' => ['linkSupport' => false],
                'references' => ['dynamicRegistration' => false],
                'rename' => ['prepareSupport' => true],
                'signatureHelp' => ['signatureInformation' => ['documentationFormat' => ['plaintext']]],
                'codeAction' => [
                    // The extract/inline families are named because a server
                    // filters what it offers by what the client understands:
                    // asking with only the three coarse kinds is asking not to
                    // be told about them.
                    'codeActionLiteralSupport' => [
                        'codeActionKind' => ['valueSet' => [
                            'quickfix',
                            'refactor',
                            'refactor.extract',
                            'refactor.inline',
                            'refactor.rewrite',
                            'source',
                            'source.organizeImports',
                            'source.fixAll',
                        ]],
                    ],
                    // phpantom answers extract and inline with the title alone
                    // and the edit behind codeAction/resolve; without these an
                    // action arrives with nothing in it and applying it is a
                    // no-op.
                    'resolveSupport' => ['properties' => ['edit']],
                    'dataSupport' => true,
                    'isPreferredSupport' => true,
                    // An action the server knows but cannot run here arrives
                    // with its reason, shown greyed rather than left out.
                    'disabledSupport' => true,
                ],
                // The server colours by MEANING where the lexer colours by
                // shape. `formats: ['relative']` is the only encoding the spec
                // defines, and the type/modifier lists are what THIS client
                // knows how to paint — a server is entitled to drop the rest.
                'semanticTokens' => [
                    'requests' => ['full' => true, 'range' => false],
                    'tokenTypes' => [
                        'namespace',
                        'type',
                        'class',
                        'enum',
                        'interface',
                        'struct',
                        'typeParameter',
                        'parameter',
                        'variable',
                        'property',
                        'enumMember',
                        'event',
                        'function',
                        'method',
                        'macro',
                        'keyword',
                        'modifier',
                        'comment',
                        'string',
                        'number',
                        'regexp',
                        'operator',
                        'decorator',
                    ],
                    'tokenModifiers' => ['declaration', 'definition', 'readonly', 'static', 'deprecated', 'abstract', 'async', 'modification', 'documentation', 'defaultLibrary'],
                    'formats' => ['relative'],
                ],
                'documentSymbol' => ['hierarchicalDocumentSymbolSupport' => true],
                'foldingRange' => ['lineFoldingOnly' => true],
                'callHierarchy' => ['dynamicRegistration' => false],
                'formatting' => ['dynamicRegistration' => false],
                // Sent by requestReformat since before this was declared.
                'rangeFormatting' => ['dynamicRegistration' => false],
            ],
            // Without this a spec-compliant server never sends `$/progress`
            // at all, so declaring it is half of making progress visible — the
            // ACK to `window/workDoneProgress/create` below is the other half.
            'window' => [
                'workDoneProgress' => true,
            ],
            'workspace' => [
                'configuration' => false,
                // A server indexes the project once, then trusts the client to
                // say what changed on disk since: phpantom registers its globs
                // here, and without the notifications its index went stale on
                // every change made outside the editor. {@see WatchedFiles}.
                'didChangeWatchedFiles' => ['dynamicRegistration' => true, 'relativePatternSupport' => false],
                'workspaceFolders' => true,
                'symbol' => ['dynamicRegistration' => false],
                'applyEdit' => true,
                // Declared only because WorkspaceEditor honours them: a server
                // told we handle resource operations will send them, and a
                // client that then drops them half-applies the refactoring.
                'workspaceEdit' => [
                    'documentChanges' => true,
                    'resourceOperations' => ['create', 'rename', 'delete'],
                    'failureHandling' => 'abort',
                ],
            ],
        ];
    }

    /**
     * Run $action now if initialized, otherwise queue it.
     */
    #[\Override]
    public function whenReady(callable $action): void
    {
        if ($this->isInitialized) {
            $action();

            return;
        }
        if ($this->stopped) {
            // Ready will never come. What waited runs anyway, on the next
            // tick: a request in it then fails at once — its caller hears
            // "stopped" rather than nothing, ever.
            EventLoop::queue(static function () use ($action): void {
                $action();
            });

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
        if ($this->stopped) {
            EventLoop::queue($onResult(...), null, self::stoppedError());

            return;
        }
        $id = $this->nextId++;
        // Waiting first: a write that fails stops the client inside send(),
        // and only what already waits hears it at once.
        $this->await($id, $this->requestTimeout, $method, $onResult);
        $this->send(self::message(['jsonrpc' => '2.0', 'id' => $id, 'method' => $method], $params));
    }

    /**
     * Wait for the answer to request $id — for at most $timeout seconds,
     * after which it fails, and the server is told to stop working on it.
     *
     * @param callable(mixed, ?array): void $onResult
     */
    private function await(int $id, float $timeout, string $method, callable $onResult): void
    {
        $this->pending->add($id, $onResult, $timeout, function (callable $callback) use ($id, $timeout, $method): void {
            $this->log(\sprintf('%s got no answer in %s seconds', $method, $timeout));
            $this->notify('$/cancelRequest', ['id' => $id]);
            $callback(null, ['code' => self::CANCELLED, 'message' => \sprintf('The language server did not answer in %s seconds', $timeout)]);
        });
    }

    /** @return array{code: int, message: string} */
    private static function stoppedError(): array
    {
        return ['code' => self::CANCELLED, 'message' => 'The language server stopped'];
    }

    #[\Override]
    public function notify(string $method, mixed $params): void
    {
        $this->send(self::message(['jsonrpc' => '2.0', 'method' => $method], $params));
    }

    /**
     * $message with its params — or without the member at all when there are
     * none. JSON-RPC allows `params` to be an object or an array, or absent;
     * `null` is none of those, and a strict server answers it with -32600
     * Invalid Request. symfony-lsp does, to the `shutdown` and `exit` this
     * client sent that way, so it was never shut down, only terminated.
     *
     * @param array<string, mixed> $message
     *
     * @return array<string, mixed>
     */
    private static function message(array $message, mixed $params): array
    {
        if (null !== $params) {
            $message['params'] = $params;
        }

        return $message;
    }

    #[\Override]
    public function stop(): void
    {
        $polite = $this->transport->isRunning();
        if ($polite) {
            // Best-effort polite shutdown (send() never throws): a server told
            // to exit gets a moment to save what it keeps before it is ended.
            $this->send(['jsonrpc' => '2.0', 'id' => $this->nextId++, 'method' => 'shutdown']);
            $this->send(['jsonrpc' => '2.0', 'method' => 'exit']);
        }
        $this->transport->stop($polite ? self::EXIT_WITHIN : 0.0);

        $this->isInitialized = false;
        $this->serverCapabilities = [];
        $this->watcherRegistrations = [];
        $this->stopped = true;
        // Everything still waiting hears it, on the next tick — never from
        // inside stop(), whose caller is mid-teardown: a request fails, and
        // an action that was waiting for ready runs and fails in turn.
        foreach ($this->pending->drain() as $callback) {
            EventLoop::queue($callback(...), null, self::stoppedError());
        }
        $ready = $this->whenReady;
        $this->whenReady = [];
        foreach ($ready as $action) {
            EventLoop::queue($action(...));
        }
    }

    private function send(array $message): void
    {
        $this->transport->send($message);
    }

    /**
     * @param array{id?: mixed, method?: string, result?: mixed, error?: array<array-key, mixed>, params?: array{items?: array<array-key, mixed>, edit?: array<array-key, mixed>, registrations?: mixed, unregisterations?: mixed}} $message
     */
    private function handleMessage(array $message): void
    {
        // Response to one of our requests — or an error the server could not
        // pin to one: a message it could not read gets `id: null`.
        if (null === ($message['method'] ?? null)) {
            $callback = null === ($message['id'] ?? null) ? null : $this->pending->take((int) $message['id']);
            if (null !== $callback) {
                $callback($message['result'] ?? null, $message['error'] ?? null);
            }
            // Nobody is waiting: a `shutdown` sent without waiting, a request
            // already timed out, or a message the server rejected outright.
            // An answer is dropped; an error is the server saying something
            // went wrong, and it reaches the LSP log rather than nowhere.
            if (null === $callback && null !== ($message['error'] ?? null)) {
                // @noinspection JsonEncodingApiUsageInspection -- a log line: what cannot be encoded is no reason to throw
                $this->log('server error'.(null === ($message['id'] ?? null) ? '' : ' for request '.(string) \json_encode($message['id'])).': '.(string) \json_encode($message['error']));
            }

            return;
        }

        // Request from the server: answer with a sane default so it does not hang
        // (an id-bearing message without a method already returned above).
        if (null !== ($message['id'] ?? null)) {
            // Answered whatever happens: a server waiting on applyEdit waits
            // for THIS reply, and an edit that threw was not applied.
            try {
                $result = match ($message['method']) {
                    'workspace/configuration' => \array_fill(0, \count($message['params']['items'] ?? [null]), value: null),
                    'window/workDoneProgress/create' => null,
                    'client/registerCapability' => $this->register($message['params']['registrations'] ?? []),
                    'client/unregisterCapability' => $this->unregister($message['params']['unregisterations'] ?? []),
                    'workspace/applyEdit' => [
                        'applied' => null !== $this->applyEditHandler && ($this->applyEditHandler)($message['params']['edit'] ?? []),
                    ],
                    default => null,
                };
            } catch (\Throwable $error) {
                $this->send(['jsonrpc' => '2.0', 'id' => $message['id'], 'result' => ['applied' => false, 'failureReason' => $error->getMessage()]]);

                throw $error;
            }
            $this->send(['jsonrpc' => '2.0', 'id' => $message['id'], 'result' => $result]);

            return;
        }

        // Notification from the server (one without a method returned above).
        $handler = $this->notificationHandlers[$message['method']] ?? null;
        if (null !== $handler) {
            $handler($message['params'] ?? []);
        }
    }

    /**
     * Keep the `workspace/didChangeWatchedFiles` registrations among $registrations;
     * every other capability a server registers is acknowledged and ignored,
     * as it always was. A glob that is not a plain string (a RelativePattern)
     * is not one this client declared support for, and is skipped.
     */
    private function register(mixed $registrations): null
    {
        foreach (self::objects($registrations) as $registration) {
            if ('workspace/didChangeWatchedFiles' !== ($registration['method'] ?? null)) {
                continue;
            }
            // @mago-expect analysis:mixed-assignment -- a decoded wire value, narrowed by objects()
            $options = $registration['registerOptions'] ?? null;
            $watchers = [];
            foreach (self::objects(\is_array($options) ? $options['watchers'] ?? null : null) as $watcher) {
                // @mago-expect analysis:mixed-assignment -- narrowed on the next line
                $glob = $watcher['globPattern'] ?? null;
                if (!\is_string($glob)) {
                    continue;
                }
                // @mago-expect analysis:mixed-assignment -- narrowed on the next line
                $kind = $watcher['kind'] ?? null;
                $watchers[] = ['glob' => $glob, 'kind' => \is_int($kind) ? $kind : 7];
            }
            // @noinspection JsonEncodingApiUsageInspection -- a key, not a payload: a value that cannot be encoded maps to the same empty key, it does not throw
            $this->watcherRegistrations[(string) \json_encode($registration['id'] ?? null)] = $watchers;
        }

        return null;
    }

    private function unregister(mixed $unregistrations): null
    {
        foreach (self::objects($unregistrations) as $unregistration) {
            // @noinspection JsonEncodingApiUsageInspection -- a key, not a payload: a value that cannot be encoded maps to the same empty key, it does not throw
            unset($this->watcherRegistrations[(string) \json_encode($unregistration['id'] ?? null)]);
        }

        return null;
    }

    /**
     * The entries of a decoded wire list that are themselves objects; anything
     * else in it — or a value that is no list at all — is not a registration.
     *
     * @return list<array<array-key, mixed>>
     */
    private static function objects(mixed $list): array
    {
        $objects = [];
        // @mago-expect analysis:mixed-assignment -- a decoded wire value, narrowed on the next line
        foreach (\is_array($list) ? $list : [] as $entry) {
            if (!\is_array($entry)) {
                continue;
            }
            $objects[] = $entry;
        }

        return $objects;
    }

    private function log(string $message): void
    {
        if (null !== $this->logger) {
            ($this->logger)($message);
        }
    }
}
