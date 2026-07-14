<?php

declare(strict_types=1);

namespace Porta\Debug;

use Porta\Editor\FileOpener;
use Porta\Event\DebugStatusChanged;
use Porta\I18n\Translator;
use Porta\Support\ProjectPaths;
use Porta\Terminal\TerminalBuffer;
use Porta\Ui\Notifier;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\DebugPanelWidget;
use Porta\Widget\TerminalPanelWidget;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Tui\Tui;

/**
 * The debugger orchestrator: owns the breakpoint store and the (single) DAP
 * session, translates session events into editor decorations, the debug panel
 * and the status bar, and carries the step/stop commands. Hand-built in `Ide`
 * (it hangs off closure-wired collaborators the container cannot express).
 */
final class DebugController implements DebugSessionObserver
{
    private ?DapSession $session = null;
    private ?TerminalBuffer $outputBuffer = null;
    /** @var array<int, array{id: int, name: string, path: string, line: int}> live frames by id (absolute paths) */
    private array $frames = [];

    public function __construct(
        private readonly BreakpointStore $breakpoints,
        private readonly DebugAdapters $adapters,
        private readonly CodeEditorWidget $editor,
        private readonly DebugPanelWidget $panel,
        private readonly TerminalPanelWidget $terminalPanel,
        private readonly Notifier $notifier,
        private readonly Tui $tui,
        private readonly Translator $translator,
        private readonly string $rootDir,
        private readonly FileOpener $files,
        private readonly DapClientFactory $clients,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly DebugSessionState $state,
        private readonly EditorDecorations $decorations,
    ) {
        $this->panel->onFrameSelected(fn (int $frameId) => $this->jumpToFrame($frameId));
        $this->panel->onExpandVariable(function (int $reference, callable $deliver): void {
            $this->session?->variables($reference, fn (array $variables) => $deliver($variables));
            $this->tui->requestRender();
        });
    }

    public function isActive(): bool
    {
        return null !== $this->session && $this->session->isActive();
    }

    public function isPaused(): bool
    {
        return null !== $this->session && DebugState::Paused === $this->session->state();
    }

    /** The status-bar segment: '⏸ path:line' when paused, '▶ debug' while running, null when idle. */
    public function statusSegment(): ?string
    {
        if (!$this->isActive()) {
            return null;
        }
        $path = $this->state->stoppedPath();
        $line = $this->state->stoppedLine();
        if ($this->isPaused() && null !== $path && null !== $line) {
            return '⏸ '.ProjectPaths::relative($this->rootDir, $path).':'.($line + 1);
        }

        return '▶ debug';
    }

    /** Ctrl+F8 / a gutter click: toggle a breakpoint on the caret's (or given) line. */
    public function toggleBreakpointAtCaret(): void
    {
        $path = $this->editor->getFilePath();
        if (null === $path) {
            $this->notifier->flash($this->translator->trans('Nothing to debug — open a file first'));

            return;
        }
        $this->toggleBreakpoint($path, $this->editor->getDocument()->getCursorLine());
    }

    /** Toggle a breakpoint on a specific 0-based line of a file. */
    public function toggleBreakpoint(string $path, int $line): void
    {
        $this->breakpoints->toggle($path, $line);
        $lines = $this->breakpoints->linesFor($path);
        if ($path === $this->editor->getFilePath()) {
            $this->editor->setBreakpointLines($lines);
        }
        $this->session?->setBreakpoints($path, $lines);
        $this->tui->requestRender();
    }

    /** Re-apply this file's debug decorations (breakpoints + stopped line) on buffer switch. */
    public function applyEditorDecorations(string $path): void
    {
        $this->decorations->apply($path);
    }

    /** A gutter click: toggle a breakpoint on a 0-based document line of the open file. */
    public function toggleBreakpointAtDocLine(int $docLine): void
    {
        if (null !== ($path = $this->editor->getFilePath())) {
            $this->toggleBreakpoint($path, $docLine);
        }
    }

    /** Shift+F9: launch the active file under its language's debug adapter. */
    public function startForCurrentFile(): void
    {
        if ($this->isActive()) {
            $this->notifier->flash($this->translator->trans('Debugger already running — stop it first (Ctrl+F2)'));
            $this->tui->requestRender();

            return;
        }
        $path = $this->editor->getFilePath();
        if (null === $path) {
            $this->notifier->flash($this->translator->trans('Nothing to debug — open a file first'));
            $this->tui->requestRender();

            return;
        }
        $languageId = ProjectPaths::languageId($path);
        $config = $this->adapters->adapterFor($languageId);
        if (null === $config) {
            $this->notifier->flash($this->translator->trans('No debug adapter configured for %language% — set one in Settings', ['%language%' => $languageId]));
            $this->tui->requestRender();

            return;
        }

        $this->state->clear();
        $this->frames = [];
        $this->outputBuffer = new TerminalBuffer();
        $this->terminalPanel->open($this->translator->trans('Debug: %label%', ['%label%' => basename($path)]), $this->outputBuffer);
        $this->panel->open(basename($path));

        $this->session = new DapSession(
            $this->clients->create($config->adapterArgv),
            $languageId,
            fn (): array => $this->breakpoints->all(),
            $this,
        );
        if (!$this->session->start(LaunchArguments::build($path, $this->rootDir, $config->launch))) {
            $this->session = null;
            $this->panel->setSessionState(DebugState::Terminated, null);
            $this->notifier->flash($this->translator->trans('Could not start the debug adapter'));
        }
        $this->dispatcher->dispatch(new DebugStatusChanged());
        $this->tui->requestRender();
    }

    public function stepOver(): void
    {
        $this->session?->stepOver();
    }

    public function stepIn(): void
    {
        $this->session?->stepIn();
    }

    public function stepOut(): void
    {
        $this->session?->stepOut();
    }

    public function continueRun(): void
    {
        $this->session?->continueRun();
    }

    /** Ctrl+F2 and the `run()` teardown: end the session. Idempotent. */
    public function stop(): void
    {
        $this->session?->disconnect();
    }

    public function onStopped(string $reason): void
    {
        $session = $this->session;
        if (null === $session) {
            return;
        }
        $session->stackTrace(function (array $frames) use ($session): void {
            $this->frames = array_column($frames, null, 'id');
            $display = array_map(fn (array $frame): array => [...$frame, 'path' => ProjectPaths::relative($this->rootDir, $frame['path'])], $frames);
            $top = $frames[0] ?? null;
            if (null !== $top && '' !== $top['path']) {
                $this->showStop($top['path'], $top['line']);
                $this->panel->setSessionState(DebugState::Paused, ProjectPaths::relative($this->rootDir, $top['path']).':'.($top['line'] + 1));
                $this->loadScopes($session, $top['id']);
            } else {
                $this->panel->setSessionState(DebugState::Paused, null);
            }
            $this->panel->setFrames($display);
            $this->dispatcher->dispatch(new DebugStatusChanged());
            $this->tui->requestRender();
        });
    }

    private function loadScopes(DapSession $session, int $frameId): void
    {
        $session->scopes($frameId, function (array $scopes) use ($session): void {
            $this->panel->setScopes($scopes);
            foreach ($scopes as $scope) {
                if ($scope['variablesReference'] > 0) {
                    $session->variables($scope['variablesReference'], fn (array $variables) => $this->panel->setVariables($scope['variablesReference'], $variables));
                }
            }
            $this->tui->requestRender();
        });
    }

    private function showStop(string $path, int $line): void
    {
        $this->state->stopAt($path, $line);
        $this->files->openFile($path);
        $this->editor->jumpTo($line, 0);
        $this->applyEditorDecorations($path);
    }

    private function jumpToFrame(int $frameId): void
    {
        $frame = $this->frames[$frameId] ?? null;
        $session = $this->session;
        if (null === $frame || null === $session || '' === $frame['path']) {
            return;
        }
        $this->files->openFile($frame['path']);
        $this->editor->jumpTo($frame['line'], 0);
        $this->loadScopes($session, $frameId);
        $this->tui->requestRender();
    }

    public function onContinued(): void
    {
        $this->state->clear();
        $this->editor->setDebugLine(null);
        $this->panel->setSessionState(DebugState::Running, null);
        $this->dispatcher->dispatch(new DebugStatusChanged());
        $this->tui->requestRender();
    }

    public function onOutput(string $category, string $text): void
    {
        $this->outputBuffer?->feed(str_replace("\n", "\r\n", $text));
        $this->terminalPanel->refresh();
        $this->tui->requestRender();
    }

    public function onTerminated(): void
    {
        $this->state->clear();
        $this->frames = [];
        $this->editor->setDebugLine(null);
        $this->panel->setSessionState(DebugState::Terminated, null);
        $this->dispatcher->dispatch(new DebugStatusChanged());
        $this->tui->requestRender();
    }

    public function onError(string $message): void
    {
        $this->notifier->flash($this->translator->trans('Could not start the debug adapter').' — '.$message, 5.0);
        $this->onTerminated();
    }
}
