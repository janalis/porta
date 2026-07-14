<?php

declare(strict_types=1);

namespace Porta\Lsp;

use Porta\Editor\ActiveFileProvider;
use Porta\Editor\Document;
use Porta\Event\LspStatusChanged;
use Porta\I18n\Translator;
use Porta\Support\ProjectPaths;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Routes LSP traffic to per-language sessions. Servers are declared once via
 * {@see configure()} (languageId => command, '*' = catch-all) and each session
 * starts lazily on the first didOpen of a matching file, so configuring a
 * Python server costs nothing until a .py buffer opens. Sessions are keyed by
 * their command, so JS and TS configured with the same server share one
 * process. Mirrors {@see LspSession}'s public surface: path-taking calls route
 * by that path, the rest route by the active editor file — feature controllers
 * keep their single-session call shape.
 */
final class LspSessionPool
{
    /** @var array<string, array{command: list<string>, name: string}> languageId ('*' = any) => server */
    private array $servers = [];
    /** @var array<string, LspSession> command signature => session */
    private array $sessions = [];
    /** @var array<string, string> command signature => 'starting…' | server name | 'failed' */
    private array $statuses = [];
    private bool $disabled = false;
    private bool $configured = false;

    /** @var (\Closure(string): void)|null */
    private ?\Closure $onLog = null;
    /** @var (\Closure(array): void)|null */
    private ?\Closure $onDiagnostics = null;
    /** @var (\Closure(array): bool)|null */
    private ?\Closure $onApplyEdit = null;

    /** @var (\Closure(): void)|null */

    /**
     * @param (\Closure(list<string>, string, \Closure(string): void): LspClientInterface)|null $clientFactory
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
     * @param array<string, array{command: list<string>, name: string}> $servers
     * @param \Closure(string): void $onLog
     * @param \Closure(array): void  $onDiagnostics
     * @param \Closure(array): bool  $onApplyEdit
     */
    public function configure(array $servers, \Closure $onLog, \Closure $onDiagnostics, \Closure $onApplyEdit): void
    {
        $this->servers = $servers;
        $this->onLog = $onLog;
        $this->onDiagnostics = $onDiagnostics;
        $this->onApplyEdit = $onApplyEdit;
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
     * @param array<string, array{command: list<string>, name: string}> $servers
     */
    public function reconfigure(array $servers): void
    {
        $this->stop();
        $this->sessions = [];
        $this->statuses = [];
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
            // PHP is the first-class language here; keep its historical hint.
            return null !== $path && 'php' === ProjectPaths::languageId($path) ? $this->translator->trans('none (install phpactor)') : $this->translator->trans('no server');
        }
        $server = $this->servers[$key];

        return $this->statuses[self::signature($server['command'])] ?? $server['name'];
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

    public function ready(?string $path, Document $document, bool $flush = true): ?string
    {
        return $this->sessionFor($path)?->ready($path, $document, $flush);
    }

    /** Opening a document is what lazily launches its language's server. */
    public function notifyDidOpen(string $path, string $text, string $languageId, int $version): void
    {
        $this->sessionFor($path, startIfNeeded: true)?->notifyDidOpen($path, $text, $languageId, $version);
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

        return null === $key ? null : $this->sessions[self::signature($this->servers[$key]['command'])] ?? null;
    }

    private function sessionFor(?string $path, bool $startIfNeeded = false): ?LspSession
    {
        if (null === $path || $this->disabled) {
            return null;
        }
        $key = $this->serverKeyFor($path);
        if (null === $key) {
            return null;
        }
        $server = $this->servers[$key];
        $signature = self::signature($server['command']);
        if (!isset($this->sessions[$signature])) {
            if (!$startIfNeeded) {
                return null;
            }
            $this->sessions[$signature] = $this->startSession($server, $signature);
        }

        return $this->sessions[$signature];
    }

    /** The configured server key for $path: its exact languageId, else the catch-all. */
    private function serverKeyFor(string $path): ?string
    {
        $language = ProjectPaths::languageId($path);
        if (isset($this->servers[$language])) {
            return $language;
        }

        return isset($this->servers['*']) ? '*' : null;
    }

    /** The status-bar server when no file is open: the catch-all, else PHP. */
    private function fallbackKey(): ?string
    {
        if (isset($this->servers['*'])) {
            return '*';
        }

        return isset($this->servers['php']) ? 'php' : null;
    }

    /** @param array{command: list<string>, name: string} $server */
    private function startSession(array $server, string $signature): LspSession
    {
        $session = new LspSession($this->rootDir, $this->clientFactory);
        $this->statuses[$signature] = $this->translator->trans('starting…');
        $session->start(
            $server['command'],
            $this->onLog ?? static function (string $line): void {},
            $this->onDiagnostics ?? static function (array $params): void {},
            $this->onApplyEdit ?? static fn (array $edit): bool => false,
            function (bool $ok) use ($server, $signature): void {
                $this->statuses[$signature] = $ok ? $server['name'] : $this->translator->trans('failed');
                $this->dispatcher?->dispatch(new LspStatusChanged());
            },
        );

        return $session;
    }

    /** @param list<string> $command */
    private static function signature(array $command): string
    {
        return implode("\0", $command);
    }
}
