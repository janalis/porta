<?php

declare(strict_types=1);

namespace Porta\Lsp;

use Porta\Container\AsIdeService;
use Porta\Editor\ActiveFileProvider;
use Porta\Editor\Document;
use Porta\Event\LspStatusChanged;
use Porta\I18n\Translator;
use Porta\Support\Outcome;
use Porta\Support\ProjectPaths;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Routes LSP traffic to per-language sessions. Servers are declared once via
 * {@see configure()} (languageId => command, '*' = catch-all) and each session
 * starts lazily on the first didOpen of a matching file, so configuring a
 * Python server costs nothing until a .py buffer opens. Sessions are keyed by
 * their command plus initializationOptions, so JS and TS configured with the
 * same server share one process — while the same argv under different options
 * (a config override colliding with a plugin's entry) launches separately
 * rather than nondeterministically reusing whichever started first.
 * Mirrors {@see LspSession}'s public surface: path-taking calls route
 * by that path, the rest route by the active editor file — feature controllers
 * keep their single-session call shape.
 */
#[AsIdeService]
final class LspSessionPool
{
    /** @var array<string, array{command: list<string>, name: string, initializationOptions?: array<string, mixed>}> languageId ('*' = any) => server */
    private array $servers = [];
    /** @var array<string, LspSession> server signature => session */
    private array $sessions = [];
    /** @var array<string, string> server signature => 'starting…' | server name | 'failed' */
    private array $statuses = [];
    /** @var array<string, int> server signature => failed starts since the last reconfigure/success */
    private array $startFailures = [];

    /** Failed starts per signature before the failure goes STICKY (until a reconfigure). */
    private const int MAX_START_ATTEMPTS = 3;

    private bool $disabled = false;
    private bool $configured = false;

    /** @var (\Closure(string): void)|null */
    private ?\Closure $onLog = null;
    /** @var (\Closure(array): void)|null */
    private ?\Closure $onDiagnostics = null;
    /** @var (\Closure(array): bool)|null */
    private ?\Closure $onApplyEdit = null;

    private ?\Closure $onShowMessage = null;

    private ?\Closure $onProgress = null;

    /**
     * @param (\Closure(list<string>, string, \Closure(string): void, array<string, mixed>|null): LspClientInterface)|null $clientFactory
     *        forwarded to every session; inject a fake to test without processes
     */
    public function __construct(
        private readonly string $rootDir,
        private readonly ActiveFileProvider $activeFile,
        private readonly Translator $translator,
        private readonly ?\Closure $clientFactory = null,
        private readonly ?EventDispatcherInterface $dispatcher = null,
    ) {}

    /**
     * Declare the available servers and the shared inbound hooks. Sessions are
     * not started here — each launches on its language's first didOpen.
     *
     * @param array<string, array{command: list<string>, name: string, initializationOptions?: array<string, mixed>}> $servers
     * @param \Closure(string): void $onLog
     * @param \Closure(array): void  $onDiagnostics
     * @param \Closure(array): bool  $onApplyEdit
     * @param \Closure(array): void|null $onShowMessage `window/showMessage` params, already server-prefixed
     * @param \Closure(array): void|null $onProgress    `$/progress` params
     */
    public function configure(array $servers, \Closure $onLog, \Closure $onDiagnostics, \Closure $onApplyEdit, ?\Closure $onShowMessage = null, ?\Closure $onProgress = null): void
    {
        $this->servers = $servers;
        $this->onLog = $onLog;
        $this->onDiagnostics = $onDiagnostics;
        $this->onApplyEdit = $onApplyEdit;
        $this->onShowMessage = $onShowMessage;
        $this->onProgress = $onProgress;
        $this->configured = true;
    }

    public function disable(): void
    {
        $this->disabled = true;
        $this->configured = true;
    }

    /**
     * Swap the server map at runtime (e.g. after editing an LSP command in
     * settings): stop every running session and forget it, so the next didOpen
     * relaunches under the new configuration. The inbound hooks are kept.
     *
     * An IDENTICAL map is a no-op returning false — the provider-setting hook
     * reconfigures on every setting of a provider plugin, and a toggle that
     * changes no command must not cost every language its running session.
     *
     * @param array<string, array{command: list<string>, name: string, initializationOptions?: array<string, mixed>}> $servers
     *
     * @return bool whether the map changed (callers skip the buffer re-open when it did not)
     */
    public function reconfigure(array $servers): bool
    {
        if ($this->configured && !$this->disabled && $servers === $this->servers) {
            return false;
        }
        $this->reload($servers);

        return true;
    }

    /**
     * Take $servers and drop every live session, whether or not the map moved.
     *
     * {@see reconfigure()} skips an identical map on purpose: the
     * provider-setting hook fires for every setting of a provider plugin, and
     * without that diff a palette toggle would tear down every server. There is
     * exactly one case where an unchanged map must still relaunch — a server's
     * BINARY was replaced on disk. An upgrade lands at the same path, so the
     * argv is byte-identical and the skip declines to act, leaving the old
     * process serving the inode `rename()` already unlinked while the installer
     * reports success.
     *
     * It takes the map rather than keeping the current one because the FIRST
     * install changes it too: with no binary yet, the plugin resolves no
     * command at all and is absent from the map entirely.
     *
     * @param array<string, array{command: list<string>, name: string, initializationOptions?: array<string, mixed>}> $servers
     */
    public function reload(array $servers): void
    {
        $this->stop();
        $this->sessions = [];
        $this->statuses = [];
        $this->startFailures = [];
        $this->servers = $servers;
    }

    public function stop(): void
    {
        foreach ($this->sessions as $session) {
            $session->stop();
        }
    }

    /** The status-bar label for the server responsible for $path. */
    public function statusFor(?string $path): string
    {
        if ($this->disabled) {
            return $this->translator->trans('disabled');
        }
        if (!$this->configured) {
            return $this->translator->trans('no server');
        }
        $key = null === $path ? $this->fallbackKey() : $this->serverKeyFor($path);
        if (null === $key) {
            // PHP is the first-class language here, but bundled servers start
            // disabled — point at Settings rather than naming one plugin.
            return null !== $path && 'php' === ProjectPaths::languageId($path) ? $this->translator->trans('none (enable a PHP server in Settings)') : $this->translator->trans('no server');
        }
        $server = $this->servers[$key];

        return $this->statuses[self::signature($server)] ?? $server['name'];
    }

    // --- LspSession-compatible surface ---------------------------------------

    /** A server process handles the active file (it may still be initializing). */
    public function isActive(): bool
    {
        return $this->activeSession()?->isActive() ?? false;
    }

    /** The active file's server is up and past initialize. */
    public function isInitialized(): bool
    {
        return $this->activeSession()?->isInitialized() ?? false;
    }

    public function request(string $method, mixed $params, callable $onResult): void
    {
        $this->activeSession()?->request($method, $params, $onResult);
    }

    public function whenReady(callable $action): void
    {
        $this->activeSession()?->whenReady($action);
    }

    public function ready(?string $path, Document $document, FlushMode $flush = FlushMode::Flush): ?string
    {
        return $this->sessionFor($path)?->ready($path, $document, $flush);
    }

    /** Opening a document is what lazily launches its language's server. */
    public function notifyDidOpen(string $path, string $text, string $languageId, int $version): void
    {
        $this->sessionFor($path, StartMode::Start)?->notifyDidOpen($path, $text, $languageId, $version);
    }

    public function notifyDidClose(string $path): void
    {
        $this->sessionFor($path)?->notifyDidClose($path);
    }

    public function notifyDidSave(string $path): void
    {
        $this->sessionFor($path)?->notifyDidSave($path);
    }

    public function notifyFullChange(string $path, Document $document): void
    {
        $this->sessionFor($path)?->notifyFullChange($path, $document);
    }

    /**
     * @param \Closure(): ?string $resolvePath current file path, evaluated on fire
     * @param \Closure(): void    $afterSync
     */
    public function scheduleDidChange(Document $doc, \Closure $resolvePath, \Closure $afterSync): void
    {
        $this->sessionFor($resolvePath())?->scheduleDidChange($doc, $resolvePath, $afterSync);
    }

    public function flushPendingChange(?string $path, ?Document $document): void
    {
        $this->sessionFor($path)?->flushPendingChange($path, $document);
    }

    /** The named capability advertised by the server responsible for $path (null when none is up). */
    public function serverCapability(?string $path, string $name): mixed
    {
        return $this->sessionFor($path)?->serverCapability($name);
    }

    // --- routing --------------------------------------------------------------

    private function activeSession(): ?LspSession
    {
        $session = $this->sessionFor($this->activeFile->getFilePath());
        if (null !== $session) {
            return $session;
        }
        // Workspace-level requests (symbol search, executeCommand) can run with
        // no matching file open: fall back to the catch-all/PHP server when one
        // is already running.
        $key = $this->fallbackKey();

        return null === $key ? null : $this->sessions[self::signature($this->servers[$key])] ?? null;
    }

    private function sessionFor(?string $path, StartMode $start = StartMode::Reuse): ?LspSession
    {
        if (null === $path || $this->disabled) {
            return null;
        }
        $key = $this->serverKeyFor($path);
        if (null === $key) {
            return null;
        }
        $server = $this->servers[$key];
        $signature = self::signature($server);
        if (($this->sessions[$signature] ?? null) === null) {
            if (StartMode::Reuse === $start) {
                return null;
            }
            $this->startSession($server, $signature);
        }

        // A synchronously failing start (spawn refusal) evicts its own slot,
        // so the entry may already be gone again: nothing to route to.
        return $this->sessions[$signature] ?? null;
    }

    /** The configured server key for $path: its exact languageId, else the catch-all. */
    private function serverKeyFor(string $path): ?string
    {
        $language = ProjectPaths::languageId($path);
        if (($this->servers[$language] ?? null) !== null) {
            return $language;
        }

        return ($this->servers['*'] ?? null) !== null ? '*' : null;
    }

    /** The status-bar server when no file is open: the catch-all, else PHP. */
    private function fallbackKey(): ?string
    {
        if (($this->servers['*'] ?? null) !== null) {
            return '*';
        }

        return ($this->servers['php'] ?? null) !== null ? 'php' : null;
    }

    /** @param array{command: list<string>, name: string, initializationOptions?: array<string, mixed>} $server */
    private function startSession(array $server, string $signature): void
    {
        $session = new LspSession($this->rootDir, $this->clientFactory);
        // Stored BEFORE start(): a synchronous failure (a spawn refusal, a
        // sync test fake) must be able to evict the very slot it occupies —
        // stored after, the assignment would resurrect the dead session.
        $this->sessions[$signature] = $session;
        $this->statuses[$signature] = $this->translator->trans('starting…');
        $onLog = $this->onLog;
        $onShowMessage = $this->onShowMessage;
        $session->start(
            $server['command'],
            null === $onLog ? static function (string $line): void {} : static fn (string $line) => $onLog('['.$server['name'].'] '.$line),
            $this->onDiagnostics ?? static function (array $params): void {},
            $this->onApplyEdit ?? static fn (array $edit): bool => false,
            function (Outcome $ok) use ($server, $signature): void {
                // @mago-expect lint:no-else-clause -- the dispatch below runs in BOTH branches, so an early return would skip it and a guard would duplicate it
                if ($ok->isOk()) {
                    // A server that came up earns fresh retries for its next
                    // incident (this same callback fires again on a
                    // mid-session death).
                    unset($this->startFailures[$signature]);
                    $this->statuses[$signature] = $server['name'];
                } else {
                    $attempts = ($this->startFailures[$signature] ?? 0) + 1;
                    $this->startFailures[$signature] = $attempts;
                    $this->statuses[$signature] = $this->translator->trans('failed');
                    // Evict so the next didOpen relaunches — a dead server
                    // used to stay dead until a settings edit. Bounded:
                    // past MAX_START_ATTEMPTS the failure goes sticky, so a
                    // crash-looping binary is not respawned on every buffer
                    // switch for the rest of the session.
                    if ($attempts < self::MAX_START_ATTEMPTS) {
                        unset($this->sessions[$signature]);
                    }
                }
                $this->dispatcher?->dispatch(new LspStatusChanged());
            },
            $server['initializationOptions'] ?? null,
            // Prefixed with the server's name, exactly as its log lines are:
            // several servers can run at once, and an unattributed toast leaves
            // the user guessing which one is complaining.
            null === $onShowMessage
                ? null
                : static function (array $params) use ($onShowMessage, $server): void {
                    $message = \is_string($params['message'] ?? null) ? trim($params['message']) : '';
                    // An empty body is left empty so the reporter still drops it —
                    // prefixing it would turn silence into a toast saying only the
                    // server's name.
                    if ('' !== $message) {
                        $params['message'] = '['.$server['name'].'] '.$message;
                    }
                    $onShowMessage($params);
                },
            $this->onProgress,
        );
    }

    /** @param array{command: list<string>, name: string, initializationOptions?: array<string, mixed>} $server */
    private static function signature(array $server): string
    {
        return implode("\0", $server['command'])."\0".json_encode($server['initializationOptions'] ?? null, \JSON_THROW_ON_ERROR);
    }
}
