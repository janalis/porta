<?php

declare(strict_types=1);

namespace Porta\Lsp;

use Porta\Container\AsIdeService;
use Porta\Editor\ActiveFileProvider;
use Porta\Editor\Document;
use Porta\Event\LspStatusChanged;
use Porta\I18n\Translator;
use Porta\Support\Backoff;
use Porta\Support\OrphanGuard;
use Porta\Support\Outcome;
use Porta\Support\ProjectPaths;
use Psr\EventDispatcher\EventDispatcherInterface;
use Revolt\EventLoop;

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
    /**
     * The servers for each language, ITS OWN FIRST.
     *
     * Only the first answers a feature request; the rest receive the whole
     * document lifecycle and are asked for the verbs whose answers merge. A
     * language with a second server is the exception, not the shape — see
     * {@see \Porta\Plugin\Api\ServerRole}.
     *
     * @var array<string, non-empty-list<array{command: list<string>, name: string, initializationOptions?: array<string, mixed>}>> languageId ('*' = any) => servers
     */
    private array $servers = [];
    /** @var array<string, LspSession> server signature => session */
    private array $sessions = [];
    /** @var array<string, string> server signature => 'starting…' | server name | 'failed' */
    private array $statuses = [];
    /** @var array<string, int> server signature => failures since the last reconfigure, or since it last stayed up */
    private array $startFailures = [];

    /** Failures per signature before the failure goes STICKY (until a reconfigure). */
    private const int MAX_START_ATTEMPTS = 3;

    /**
     * Seconds a server must have been up for its death to wipe its slate.
     * One that comes up and falls over at the first file it is shown would
     * otherwise earn fresh retries on every start, and crash-loop for good.
     */
    public const float STABLE = 60.0;

    /** @var array<string, float> server signature => when it came up (hrtime seconds) */
    private array $upSince = [];

    /**
     * A server that died while up, waiting out its backoff before it is
     * launched again and told about the files it had open.
     *
     * A file edited meanwhile is held as its live document, so it is told
     * as it is when the server is back, not as it was when it died.
     *
     * @var array<string, array{timer: string, documents: array<string, string|Document>}> server signature => relaunch
     */
    private array $relaunches = [];

    /** @var array<string, array<string, true>> server signature => progress tokens begun and not ended */
    private array $progress = [];

    /** @var array<string, array<string, array<string, true>>> server signature => URI => the owners its diagnostics there went under */
    private array $published = [];

    private bool $disabled = false;
    private bool $restricted = false;

    /** @var array<string, list<string>> languageId => servers a plugin declares whose binary is not there */
    private array $missing = [];
    private bool $configured = false;

    /** @var (\Closure(string): void)|null */
    private ?\Closure $onLog = null;
    /** @var (\Closure(array, string): void)|null */
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
        private readonly ?OrphanGuard $guard = null,
    ) {}

    /** The project every session serves: where a server's file operations must stay. */
    public function rootDir(): string
    {
        return $this->rootDir;
    }

    /**
     * Declare the available servers and the shared inbound hooks. Sessions are
     * not started here — each launches on its language's first didOpen.
     *
     * @param array<string, non-empty-list<array{command: list<string>, name: string, initializationOptions?: array<string, mixed>}>> $servers
     * @param \Closure(string): void        $onLog
     * @param \Closure(array, string): void $onDiagnostics params, and the name of the server that published them
     * @param \Closure(array): bool  $onApplyEdit
     * @param (\Closure(array): void)|null $onShowMessage `window/showMessage` params, already server-prefixed
     * @param (\Closure(array): void)|null $onProgress    `$/progress` params
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

    /**
     * What plugins declare and cannot launch, by language, as
     * {@see LanguageServerProvider::missingServers()} says it.
     *
     * @param array<string, list<string>> $missing
     */
    public function missing(array $missing): void
    {
        $this->missing = $missing;
    }

    public function disable(): void
    {
        $this->disabled = true;
        $this->configured = true;
    }

    /**
     * The project is not trusted, so no server of its own is started for it
     * ({@see ServerLocator::restricted()}) — only one run elsewhere, which
     * the boot hands {@see configure()}: a language without one says as
     * much, not as a server to install or enable — neither would help.
     * Decided once, at boot, as the rest of a session's trust is:
     * {@see LspReconfigurer} reads it back, so a map handed in later is
     * again only those — whatever another window answered meanwhile — and
     * what the bar says and what runs never part.
     */
    public function restrict(): void
    {
        $this->restricted = true;
    }

    /** Whether {@see restrict()} was: only a server run elsewhere may start. */
    public function isRestricted(): bool
    {
        return $this->restricted;
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
     * @param array<string, non-empty-list<array{command: list<string>, name: string, initializationOptions?: array<string, mixed>}>> $servers
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
     * @param array<string, non-empty-list<array{command: list<string>, name: string, initializationOptions?: array<string, mixed>}>> $servers
     */
    public function reload(array $servers): void
    {
        $this->stop();
        $this->sessions = [];
        $this->statuses = [];
        $this->startFailures = [];
        $this->upSince = [];
        $this->servers = $servers;
    }

    /**
     * The server each language is configured to start first — its argv,
     * name and initialization options — for whatever needs to run one of its
     * own, the same way. The catch-all '*' entry is left out: it names no
     * language.
     *
     * @return array<string, array{command: list<string>, name: string, initializationOptions?: array<string, mixed>}>
     */
    public function primaryServers(): array
    {
        $primaries = [];
        foreach ($this->servers as $languageId => $servers) {
            if ('*' === $languageId) {
                continue;
            }
            $primaries[$languageId] = $servers[0];
        }

        return $primaries;
    }

    public function stop(): void
    {
        foreach ($this->relaunches as $relaunch) {
            EventLoop::cancel($relaunch['timer']);
        }
        $this->relaunches = [];
        foreach ($this->sessions as $session) {
            $session->stop();
        }
    }

    /**
     * Every running server that registered file watchers, with them.
     *
     * @return array<string, non-empty-list<array{glob: string, kind: int}>> server signature => its watchers
     */
    public function fileWatchers(): array
    {
        return \array_filter(\array_map(static fn (LspSession $session): array => $session->fileWatchers(), $this->sessions));
    }

    /**
     * Tell one server, by signature, which of its watched files changed. A
     * signature whose session has gone since is a no-op.
     *
     * @param non-empty-list<array{uri: string, type: int}> $changes
     */
    public function notifyWatchedFiles(string $signature, array $changes): void
    {
        ($this->sessions[$signature] ?? null)?->notifyWatchedFiles($changes);
    }

    /** The status-bar label for the server responsible for $path. */
    public function statusFor(?string $path): string
    {
        // @custos-ignore DuplicateMatchCondition -- three different properties; its fix would drop the disabled arm and say "no server" for a disabled pool
        $key = null === $path ? $this->fallbackKey() : $this->serverKeyFor($path);
        $off = match (true) {
            // unless a server run elsewhere serves it: the one kind restricted starts
            $this->restricted && null === $key => $this->translator->trans('off — project not trusted'),
            $this->disabled => $this->translator->trans('disabled'),
            !$this->configured => $this->translator->trans('no server'),
            default => null,
        };

        return $off ?? (null === $key ? $this->unservedStatus($path) : self::describe($this->servers[$key], $this->statuses));
    }

    /** The status-bar label when no configured server answers for $path. */
    private function unservedStatus(?string $path): string
    {
        // PHP is the first-class language here, but bundled servers start
        // disabled — point at Settings rather than naming one plugin.
        $language = null === $path ? 'php' : ProjectPaths::languageId($path);
        $absent = $this->missing[$language][0] ?? null;

        return match (true) {
            // Enabled, and its binary not there: say that, not "enable one" —
            // and where the button is that installs it.
            null !== $absent => $this->translator->trans('%name% not found (install it in Settings)', ['%name%' => $absent]),
            null !== $path && 'php' === $language => $this->translator->trans('none (enable a PHP server in Settings)'),
            default => $this->translator->trans('no server'),
        };
    }

    /**
     * The status-bar text for a language's servers.
     *
     * One server reads as its own name (or what it is doing), which is every
     * language most of the time. Two read as `primary +other` — enough to say
     * that something else is also answering, without turning a status cell
     * into a list. A FAILED one is named either way: collapsing it into the
     * survivor's name is how "my second server is dead" becomes invisible.
     *
     * @param non-empty-list<array{command: list<string>, name: string, initializationOptions?: array<string, mixed>}> $servers
     * @param array<string, string>                                                                                    $statuses
     */
    private static function describe(array $servers, array $statuses): string
    {
        $labels = [];
        foreach ($servers as $server) {
            $labels[] = $statuses[self::signature($server)] ?? $server['name'];
        }

        return \implode(' +', $labels);
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

    /**
     * Ask EVERY server that serves the active file, and call back once per
     * answer as answers arrive.
     *
     * For the verbs whose answers are lists and therefore merge — completion
     * items, code actions, code lenses. Most verbs are not: definition, rename
     * and formatting each pick one, and asking two servers would mean
     * inventing a tie-break for each, so they stay on {@see request()}.
     *
     * Deliberately incremental rather than collected: {@see request()} has no
     * timeout, so a contract that waited for the last answer would let one
     * server that never replies hold the whole verb forever. A caller merges
     * what it has each time instead.
     *
     * Only servers whose OWN capabilities advertise $capability are asked — a
     * merged capability would be a claim no single server made, and a server
     * that never offered the feature answers an error per request.
     *
     * $owner identifies the answering session for a follow-up round trip
     * ({@see requestTo()}); it is opaque and only meaningful to this pool.
     *
     * @param \Closure(mixed, ?array<array-key, mixed>, string): void $onResult result, error, owner
     *
     * @return int how many servers were asked — 0 when none serves $capability
     */
    public function requestAll(string $method, mixed $params, string $capability, \Closure $onResult): int
    {
        $asked = 0;
        foreach ($this->sessionsFor($this->activeFile->getFilePath()) as $signature => $session) {
            // Asked once: `serverCapability()` reads the handshake's answer,
            // and a capability is `true`, an options object, or absent.
            /** @var mixed $declared */
            $declared = $session->serverCapability($capability);
            if (null === $declared || false === $declared) {
                continue;
            }
            $session->request($method, $params, static function (mixed $result, ?array $error) use ($onResult, $signature): void {
                $onResult($result, $error, $signature);
            });
            ++$asked;
        }

        return $asked;
    }

    /** Whether a language server is configured for $path's language — running or not. */
    public function serves(string $path): bool
    {
        return !$this->disabled && null !== $this->serverKeyFor($path);
    }

    /**
     * Ask the server for $path — the one whose language the FILE is, not the
     * active tab's. For a caller outside the editor ({@see LspQuery}), which
     * may ask about a file the user is not looking at.
     *
     * Sent once the session is past initialize, so a question arriving while
     * the server is still starting waits rather than racing the handshake. No
     * session at all — the file was never opened to this client — answers at
     * once with an error.
     */
    public function requestFor(string $path, string $method, mixed $params, callable $onResult): void
    {
        $session = $this->sessionFor($path);
        if (null === $session) {
            $onResult(null, ['code' => -32_002, 'message' => 'No language server is running for this file']);

            return;
        }
        $session->whenReady(static function () use ($session, $method, $params, $onResult): void {
            $session->request($method, $params, $onResult);
        });
    }

    /**
     * Ask the ONE server $owner names — the follow-up half of
     * {@see requestAll()}.
     *
     * `completionItem/resolve`, `codeAction/resolve` and
     * `workspace/executeCommand` are all second round trips about something a
     * particular server offered, and sending them anywhere else is at best an
     * error response and at worst another server acting on an id that means
     * something different to it.
     *
     * An EMPTY owner is "whoever serves the active file" — what a caller that
     * never asked {@see requestAll()} has, and the single-server behaviour
     * this method replaced. A non-empty owner naming a server that has since
     * gone (a failed relaunch, a reconfigure) is answered with an error on
     * the next tick instead: the thing being resolved cannot exist any more
     * either, handing it to another server would be asking it about an id
     * that means something else — and a caller waiting for an answer must
     * still get one.
     */
    public function requestTo(string $owner, string $method, mixed $params, callable $onResult): void
    {
        if ('' === $owner) {
            $this->request($method, $params, $onResult);

            // Equivalent-mutant note: dropping this `return` changes nothing
            // observable — no session is ever keyed '' — but falling through
            // would mean the empty case relies on that, and the day a session
            // key can be empty it would send the request twice.
            return;
        }
        $session = $this->sessions[$owner] ?? null;
        if (null === $session) {
            EventLoop::queue($onResult(...), null, ['code' => -32_002, 'message' => 'That language server is no longer running']);

            return;
        }
        $session->request($method, $params, $onResult);
    }

    /**
     * The named capability as $owner's own server declared it, or null when it
     * is gone. An empty owner asks the active file's server, mirroring
     * {@see requestTo()}.
     */
    public function capabilityOf(string $owner, string $name): mixed
    {
        return '' === $owner
            ? $this->serverCapability($this->activeFile->getFilePath(), $name)
            : ($this->sessions[$owner] ?? null)?->serverCapability($name);
    }

    public function whenReady(callable $action): void
    {
        $this->activeSession()?->whenReady($action);
    }

    /**
     * The active file's path when its OWN server is ready, else null.
     *
     * Every session is asked, not just the first: `ready()` is also what
     * flushes a pending didChange, and a supplementary server left holding an
     * un-flushed edit would answer the next merged request about stale text.
     * The verdict is still the primary's — it is the one a feature request
     * goes to.
     */
    public function ready(?string $path, Document $document, FlushMode $flush = FlushMode::Flush): ?string
    {
        $ready = null;
        $first = true;
        foreach ($this->sessionsFor($path) as $session) {
            $answer = $session->ready($path, $document, $flush);
            if ($first) {
                $ready = $answer;
                $first = false;
            }
        }

        return $ready;
    }

    /**
     * Opening a document is what lazily launches its language's servers — all
     * of them, because a server that never saw the file has nothing to say
     * about it.
     */
    public function notifyDidOpen(string $path, string $text, string $languageId, int $version): void
    {
        foreach ($this->sessionsFor($path, StartMode::Start) as $session) {
            $session->notifyDidOpen($path, $text, $languageId, $version);
        }
    }

    public function notifyDidClose(string $path): void
    {
        foreach (\array_keys($this->relaunches) as $signature) {
            unset($this->relaunches[$signature]['documents'][$path]);
        }
        foreach ($this->sessionsFor($path) as $session) {
            $session->notifyDidClose($path);
        }
    }

    /**
     * Tell every running server that $root — a file, or a whole directory —
     * is gone from this client.
     *
     * Every session, not {@see sessionFor()}'s one: a renamed folder can hold
     * files of several languages, and after the move the path no longer routes
     * to a server at all. Each session answers for its own open set, so asking
     * all of them costs nothing and misses nothing.
     */
    public function notifyDidCloseUnder(string $root): void
    {
        foreach ($this->sessions as $session) {
            $session->closeUnder($root);
        }
    }

    public function notifyDidSave(string $path): void
    {
        foreach ($this->sessionsFor($path) as $session) {
            $session->notifyDidSave($path);
        }
    }

    public function notifyFullChange(string $path, Document $document): void
    {
        $this->heldWhileDown($path, $document);
        foreach ($this->sessionsFor($path) as $session) {
            $session->notifyFullChange($path, $document);
        }
    }

    /**
     * @param \Closure(): ?string $resolvePath current file path, evaluated on fire
     * @param \Closure(): void    $afterSync
     */
    public function scheduleDidChange(Document $doc, \Closure $resolvePath, \Closure $afterSync): void
    {
        $this->heldWhileDown($resolvePath(), $doc);
        // Each session debounces on its own timer and diffs against what IT
        // was last told, which is the only baseline that can be right when
        // one server does incremental sync and another does not.
        foreach ($this->sessionsFor($resolvePath()) as $session) {
            $session->scheduleDidChange($doc, $resolvePath, $afterSync);
        }
    }

    public function flushPendingChange(?string $path, ?Document $document): void
    {
        foreach ($this->sessionsFor($path) as $session) {
            $session->flushPendingChange($path, $document);
        }
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

        return null === $key ? null : $this->sessions[self::signature($this->servers[$key][0])] ?? null;
    }

    private function sessionFor(?string $path, StartMode $start = StartMode::Reuse): ?LspSession
    {
        return \array_first($this->sessionsFor($path, $start));
    }

    /**
     * Every session serving $path, the language's own server first.
     *
     * The document lifecycle fans out over all of them: a server that never
     * received a didOpen answers nothing, and one that missed a didChange
     * answers about text the user has moved on from — which is worse than
     * silence, because it looks like a wrong answer rather than no answer.
     *
     * Keyed by launch signature, which is what {@see requestAll()} hands a
     * caller as the owner of an answer and {@see requestTo()} takes back.
     *
     * @return array<string, LspSession>
     */
    private function sessionsFor(?string $path, StartMode $start = StartMode::Reuse): array
    {
        $key = null === $path || $this->disabled ? null : $this->serverKeyFor($path);
        if (null === $key) {
            return [];
        }
        $sessions = [];
        foreach ($this->servers[$key] as $server) {
            $signature = self::signature($server);
            // Two providers can resolve to one launch — the same binary under
            // two names, or a plugin registering it twice. That is ONE
            // process, and telling it a document opened twice is not a
            // harmless duplicate: the server would hold two copies of a file
            // it can only close once. Keying the result by signature is what
            // makes that impossible rather than merely guarded.
            if (\array_key_exists($signature, $sessions)) {
                continue;
            }
            if (null === ($this->sessions[$signature] ?? null)) {
                if (StartMode::Reuse === $start) {
                    continue;
                }
                $this->startSession($server, $signature);
            }
            // A synchronously failing start (spawn refusal) evicts its own
            // slot, so the entry may already be gone again: nothing to route
            // to, and the others still stand.
            $session = $this->sessions[$signature] ?? null;
            if (null !== $session) {
                $sessions[$signature] = $session;
            }
        }

        return $sessions;
    }

    /** The configured server key for $path: its exact languageId, else the catch-all. */
    private function serverKeyFor(string $path): ?string
    {
        $language = ProjectPaths::languageId($path);
        if (null !== ($this->servers[$language] ?? null)) {
            return $language;
        }

        return null !== ($this->servers['*'] ?? null) ? '*' : null;
    }

    /** The status-bar server when no file is open: the catch-all, else PHP. */
    private function fallbackKey(): ?string
    {
        if (null !== ($this->servers['*'] ?? null)) {
            return '*';
        }

        return null !== ($this->servers['php'] ?? null) ? 'php' : null;
    }

    /** @param array{command: list<string>, name: string, initializationOptions?: array<string, mixed>} $server */
    private function startSession(array $server, string $signature): void
    {
        $session = new LspSession($this->rootDir, $this->clientFactory, $this->guard);
        // Stored BEFORE start(): a synchronous failure (a spawn refusal, a
        // sync test fake) must be able to evict the very slot it occupies —
        // stored after, the assignment would resurrect the dead session.
        $this->sessions[$signature] = $session;
        $this->statuses[$signature] = $this->translator->trans('starting…');
        $onLog = $this->onLog;
        $onShowMessage = $this->onShowMessage;
        $onDiagnostics = $this->onDiagnostics;
        $onProgress = $this->onProgress;
        $session->start(
            $server['command'],
            null === $onLog ? static function (string $line): void {} : static fn (string $line) => $onLog('['.$server['name'].'] '.$line),
            // Name-bound, exactly as the log and showMessage hooks are: the
            // sink keeps each publisher's findings apart, and an unattributed
            // publish would make every server share one slot again.
            null === $onDiagnostics
                ? static function (array $params): void {}
                : function (array $params) use ($onDiagnostics, $server, $signature): void {
                    // Asked for and published are kept apart, so a server doing both
                    // does not wipe the one with the other.
                    $owner = true === ($params['pulled'] ?? null) ? $server['name'].' (pulled)' : $server['name'];
                    // Remembered, so a server that dies takes its findings with it.
                    if (\is_string($params['uri'] ?? null)) {
                        $this->published[$signature][$params['uri']][$owner] = true;
                    }
                    $onDiagnostics($params, $owner);
                },
            $this->onApplyEdit ?? static fn (array $edit): bool => false,
            function (Outcome $ok) use ($server, $signature, $session): void {
                // @mago-expect lint:no-else-clause -- the dispatch below runs in BOTH branches, so an early return would skip it and a guard would duplicate it
                if ($ok->isOk()) {
                    $this->upSince[$signature] = \hrtime(true) / 1e9;
                    $this->statuses[$signature] = $server['name'];
                } else {
                    $this->failed($server, $signature, $session);
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
                    $message = \is_string($params['message'] ?? null) ? \trim($params['message']) : '';
                    // An empty body is left empty so the reporter still drops it —
                    // prefixing it would turn silence into a toast saying only the
                    // server's name.
                    if ('' !== $message) {
                        $params['message'] = '['.$server['name'].'] '.$message;
                    }
                    $onShowMessage($params);
                },
            null === $onProgress
                ? null
                : function (array $params) use ($onProgress, $signature): void {
                    // Each server's tokens apart — two servers both saying "1"
                    // are two tasks — and remembered, to end them if it dies.
                    if (!\is_string($params['token'] ?? null) && !\is_int($params['token'] ?? null)) {
                        return;
                    }
                    $token = $signature."\0".$params['token'];
                    $value = \is_array($params['value'] ?? null) ? $params['value'] : [];
                    $kind = \is_string($value['kind'] ?? null) ? $value['kind'] : '';
                    if ('begin' === $kind) {
                        $this->progress[$signature][$token] = true;
                    }
                    if ('end' === $kind) {
                        unset($this->progress[$signature][$token]);
                    }
                    $onProgress(['token' => $token] + $params);
                },
        );
    }

    /**
     * A server failed to start, or died. Its findings and its progress go
     * with it. One that was UP — a death, not a failed start — is launched
     * again after its backoff and told about every file it had open, since
     * nothing else would reopen them: the next didOpen only brings its own
     * file. Past MAX_START_ATTEMPTS the failure is sticky, and said once.
     *
     * @param array{command: list<string>, name: string, initializationOptions?: array<string, mixed>} $server
     */
    private function failed(array $server, string $signature, LspSession $session): void
    {
        $upSince = $this->upSince[$signature] ?? null;
        unset($this->upSince[$signature]);
        if (null !== $upSince && ((\hrtime(true) / 1e9) - $upSince) >= self::STABLE) {
            unset($this->startFailures[$signature]);
        }
        $attempts = ($this->startFailures[$signature] ?? 0) + 1;
        $this->startFailures[$signature] = $attempts;
        $this->statuses[$signature] = $this->translator->trans('failed');
        $this->forget($signature);
        if ($attempts >= self::MAX_START_ATTEMPTS) {
            if (null !== $this->onShowMessage) {
                ($this->onShowMessage)([
                    'type' => 1,
                    'message' => $this->translator->trans('[%name%] stopped %times% times and is not started again — change its settings or restart Porta', [
                        '%name%' => $server['name'],
                        '%times%' => $attempts,
                    ]),
                ]);
            }

            return;
        }
        // Evicted, so the next didOpen launches it again.
        unset($this->sessions[$signature]);
        if (null === $upSince) {
            return;
        }
        $documents = $session->openDocuments();
        $timer = EventLoop::unreference(EventLoop::delay(Backoff::after($attempts - 1), function () use ($server, $signature): void {
            $documents = $this->relaunches[$signature]['documents'] ?? [];
            unset($this->relaunches[$signature]);
            if ($this->disabled) {
                return;
            }
            if (null === ($this->sessions[$signature] ?? null)) {
                $this->startSession($server, $signature);
            }
            $relaunched = $this->sessions[$signature] ?? null;
            if (null === $relaunched) {
                return;
            }
            foreach ($documents as $path => $text) {
                if (\array_key_exists($path, $relaunched->openDocuments())) {
                    continue;
                }
                $relaunched->notifyDidOpen($path, \is_string($text) ? $text : $text->getText(), ProjectPaths::languageId($path), 0);
            }
        }));
        $this->relaunches[$signature] = ['timer' => $timer, 'documents' => $documents];
    }

    /** An edit to $path while its server is down is kept, for when it is back. */
    private function heldWhileDown(?string $path, Document $document): void
    {
        foreach (\array_keys($this->relaunches) as $signature) {
            if (null === $path || !\array_key_exists($path, $this->relaunches[$signature]['documents'])) {
                continue;
            }
            $this->relaunches[$signature]['documents'][$path] = $document;
        }
    }

    /** Clear what a gone server left on screen: its diagnostics and its progress. */
    private function forget(string $signature): void
    {
        $published = $this->published[$signature] ?? [];
        $progress = $this->progress[$signature] ?? [];
        unset($this->published[$signature], $this->progress[$signature]);
        foreach ($published as $uri => $owners) {
            foreach (\array_keys($owners) as $owner) {
                $this->onDiagnostics?->__invoke(['uri' => $uri, 'diagnostics' => []], $owner);
            }
        }
        if (null !== $this->onProgress) {
            foreach (\array_keys($progress) as $token) {
                ($this->onProgress)(['token' => $token, 'value' => ['kind' => 'end']]);
            }
        }
    }

    /** @param array{command: list<string>, name: string, initializationOptions?: array<string, mixed>} $server */
    private static function signature(array $server): string
    {
        return \implode("\0", $server['command'])."\0".\json_encode($server['initializationOptions'] ?? null, \JSON_THROW_ON_ERROR);
    }
}
