<?php

declare(strict_types=1);

namespace Porta\Lsp;

use Porta\Editor\Document;
use Porta\Editor\TextDiff;
use Porta\Support\Outcome;
use Porta\Support\ProjectPaths;
use Revolt\EventLoop;

/**
 * Owns the language-server transport: the {@see LspClient} connection, the
 * per-file synced-text baseline used for incremental didChange, and the change
 * debounce. Feature code (completion, definition, …) stays
 * in the coordinator and drives the server through this session's request /
 * readiness surface, so the transport and the features are no longer tangled in
 * one object.
 */
final class LspSession
{
    private ?LspClientInterface $client = null;
    /** @var array<string, string> path => last text synced to the server (incremental baseline) */
    private array $syncedText = [];
    private ?string $changeDebounce = null;

    /** @var \Closure(list<string>, string, \Closure(string): void, array<string, mixed>|null): LspClientInterface */
    private \Closure $clientFactory;

    /**
     * @param (\Closure(list<string>, string, \Closure(string): void, array<string, mixed>|null): LspClientInterface)|null $clientFactory
     *        builds the transport (defaults to a real {@see LspClient}); inject a
     *        fake to unit-test without spawning a server process
     */
    public function __construct(
        private readonly string $rootDir,
        ?\Closure $clientFactory = null,
    ) {
        $this->clientFactory = $clientFactory ?? /**
         * @param list<string>              $command
         * @param array<string, mixed>|null $initializationOptions
         */
        static fn (array $command, string $rootDir, \Closure $onLog, ?array $initializationOptions): LspClientInterface => new LspClient(
            $command,
            $rootDir,
            $onLog,
            initializationOptions: $initializationOptions,
        );
    }

    /**
     * Launch the server and wire its inbound hooks.
     *
     * @param list<string>          $command
     * @param \Closure(string): void $onLog         raw server log line
     * @param \Closure(array): void  $onDiagnostics textDocument/publishDiagnostics params
     * @param \Closure(array): bool  $onApplyEdit   workspace/applyEdit handler (returns applied)
     * @param \Closure(Outcome): void   $onStarted     start result (true when initialized)
     * @param array<string, mixed>|null $initializationOptions forwarded into the `initialize` request
     * @param \Closure(array): void|null $onShowMessage `window/showMessage` params — what the server tells the USER
     * @param \Closure(array): void|null $onProgress    `$/progress` params — what the server is busy doing
     */
    public function start(
        array $command,
        \Closure $onLog,
        \Closure $onDiagnostics,
        \Closure $onApplyEdit,
        \Closure $onStarted,
        ?array $initializationOptions = null,
        ?\Closure $onShowMessage = null,
        ?\Closure $onProgress = null,
    ): void {
        $this->client = ($this->clientFactory)($command, $this->rootDir, $onLog, $initializationOptions);
        $this->client->onNotification('textDocument/publishDiagnostics', $onDiagnostics);
        // Many servers log through LSP rather than stderr — same sink.
        $this->client->onNotification('window/logMessage', static function (array $params) use ($onLog): void {
            $onLog(\is_string($params['message'] ?? null) ? $params['message'] : '');
        });
        // Both are optional so a pool configured without them behaves exactly
        // as before: the notification is simply never registered.
        if (null !== $onShowMessage) {
            $this->client->onNotification('window/showMessage', $onShowMessage);
        }
        if (null !== $onProgress) {
            $this->client->onNotification('$/progress', $onProgress);
        }
        $this->client->onApplyEdit($onApplyEdit);
        $this->client->start(function (Outcome $ok) use ($onStarted): void {
            if (!$ok->isOk()) {
                // stop() before dropping the reference: a server that closed
                // stdout but is still alive (a wrapper script, a crashed I/O
                // thread) must be terminated and reaped — an orphan would
                // outlive the IDE, and its GC'd proc handle does a BLOCKING
                // waitpid on the loop. On a never-spawned or already-dead
                // process, stop() is a safe no-op.
                $this->client?->stop();
                $this->client = null;
            }
            $onStarted($ok);
        });
    }

    public function stop(): void
    {
        // A debounce armed at shutdown would fire a didChange into the next
        // event-loop consumer (in tests: the next test) 0.3s later.
        if (null !== $this->changeDebounce) {
            EventLoop::cancel($this->changeDebounce);
            $this->changeDebounce = null;
        }
        $this->client?->stop();
    }

    /** A server process exists (may still be initializing). */
    public function isActive(): bool
    {
        return null !== $this->client;
    }

    /** The server is up and past initialize — safe to send feature requests. */
    public function isInitialized(): bool
    {
        return null !== $this->client && $this->client->isInitialized();
    }

    // --- requests -----------------------------------------------------------

    public function request(string $method, mixed $params, callable $onResult): void
    {
        $this->client?->request($method, $params, $onResult);
    }

    public function whenReady(callable $action): void
    {
        $this->client?->whenReady($action);
    }

    /**
     * Precondition for a feature request against the current file $path: an
     * initialized server. Flushes any pending debounced edit first (unless
     * {@see FlushMode::Keep}, for navigation that must not disturb the debounce),
     * then returns $path — or null when a request cannot be made.
     */
    public function ready(?string $path, Document $document, FlushMode $flush = FlushMode::Flush): ?string
    {
        if (null === $path || !$this->isInitialized()) {
            return null;
        }
        if (FlushMode::Flush === $flush) {
            $this->flushPendingChange($path, $document);
        }

        return $path;
    }

    // --- document sync ------------------------------------------------------

    public function notifyDidOpen(string $path, string $text, string $languageId, int $version): void
    {
        $this->syncedText[$path] = $text; // baseline for incremental didChange
        $uri = Uri::fromPath($path);
        $this->whenReady(fn () => $this->client?->notify('textDocument/didOpen', [
            'textDocument' => [
                'uri' => $uri,
                'languageId' => $languageId,
                'version' => $version,
                'text' => $text,
            ],
        ]));
    }

    public function notifyDidClose(string $path): void
    {
        unset($this->syncedText[$path]);
        $doc = Uri::textDocument($path);
        $this->whenReady(fn () => $this->client?->notify('textDocument/didClose', [
            'textDocument' => $doc,
        ]));
    }

    /**
     * Close every open document at or under $root — the file itself when $root
     * is one, the whole subtree when it is a directory.
     *
     * The session's own open set is the authority: iterating $syncedText means
     * a path the server was never told about is never closed either, and a
     * folder rename closes exactly the files inside it without the caller
     * having to know which of them were open.
     *
     * One test, not two: {@see ProjectPaths::isUnder()} is reflexive, so it
     * already answers the plain-file case. An explicit `$path === $root` arm
     * was written here first and survived its mutant untouched — it can never
     * be the only true half.
     */
    public function closeUnder(string $root): void
    {
        foreach (\array_keys($this->syncedText) as $path) {
            if (!ProjectPaths::isUnder($path, $root)) {
                continue;
            }
            $this->notifyDidClose($path);
        }
    }

    public function notifyDidSave(string $path): void
    {
        $doc = Uri::textDocument($path);
        $this->whenReady(fn () => $this->client?->notify('textDocument/didSave', [
            'textDocument' => $doc,
        ]));
    }

    /**
     * Debounce a didChange for $doc; resolve the file path at fire time (so a
     * mid-debounce buffer switch is respected) and run $afterSync afterwards.
     *
     * @param \Closure(): ?string $resolvePath current file path, evaluated on fire
     * @param \Closure(): void    $afterSync   e.g. re-request inlay hints
     */
    public function scheduleDidChange(Document $doc, \Closure $resolvePath, \Closure $afterSync): void
    {
        if (null === $this->client) {
            return;
        }
        if (null !== $this->changeDebounce) {
            EventLoop::cancel($this->changeDebounce);
        }

        $this->changeDebounce = EventLoop::delay(0.3, function () use ($doc, $resolvePath, $afterSync): void {
            $this->changeDebounce = null;
            $path = $resolvePath();
            if (null === $path) {
                return;
            }
            $this->sendDidChange($path, $doc);
            $this->whenReady($afterSync);
        });
    }

    /** Send any pending debounced edit immediately (before a feature request). */
    public function flushPendingChange(?string $path, ?Document $document): void
    {
        if (null === $this->changeDebounce || null === $this->client) {
            return;
        }
        EventLoop::cancel($this->changeDebounce);
        $this->changeDebounce = null;
        if (null !== $path && null !== $document) {
            // The same route the debounce itself takes: a minimal incremental
            // change when the server syncs incrementally, full text otherwise.
            // Ready() flushes before every feature request — completion fires
            // on `->` — so a full-text flush here shipped the whole file per
            // trigger character and left the diff path nearly unreachable.
            $this->sendDidChange($path, $document);
        }
    }

    /**
     * Send a full-text didChange and reset the incremental-sync baseline so the
     * next delta is diffed against what the server now has.
     */
    public function notifyFullChange(string $path, Document $document): void
    {
        $text = $document->getText();
        $this->syncedText[$path] = $text;
        $uri = Uri::fromPath($path);
        $version = $document->getVersion();
        $this->whenReady(fn () => $this->client?->notify('textDocument/didChange', [
            'textDocument' => ['uri' => $uri, 'version' => $version],
            'contentChanges' => [['text' => $text]],
        ]));
    }

    /**
     * Send a textDocument/didChange for $path — a minimal incremental change
     * (prefix/suffix diff against the last synced text) when the server
     * advertises incremental sync, otherwise a full-text replacement. Either way
     * the per-file baseline is refreshed.
     */
    private function sendDidChange(string $path, Document $document): void
    {
        $new = $document->getText();
        $old = $this->syncedText[$path] ?? null;
        $this->syncedText[$path] = $new;

        $changes = null !== $old && $this->serverUsesIncrementalSync()
            ? [TextDiff::incrementalChange($old, $new)]
            : [['text' => $new]];

        $uri = Uri::fromPath($path);
        $version = $document->getVersion();
        $this->whenReady(fn () => $this->client?->notify('textDocument/didChange', [
            'textDocument' => ['uri' => $uri, 'version' => $version],
            'contentChanges' => $changes,
        ]));
    }

    /** A single advertised server capability, null while no server is up (or none advertised). */
    public function serverCapability(string $name): mixed
    {
        return $this->client?->serverCapabilities()[$name] ?? null;
    }

    /** Whether the language server declared incremental (kind 2) document sync. */
    private function serverUsesIncrementalSync(): bool
    {
        /** @var int|array<array-key, mixed> $sync */
        $sync = $this->client?->serverCapabilities()['textDocumentSync'] ?? 1;
        $kind = \is_array($sync) ? (int) ($sync['change'] ?? 0) : $sync;

        return 2 === $kind;
    }
}
