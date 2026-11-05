<?php

declare(strict_types=1);

namespace Porta\Debug;

use Porta\Container\AsIdeService;
use Porta\Editor\FileOpener;
use Porta\Editor\LineShift;
use Porta\Event\DebugFrameSelected;
use Porta\Event\DebugStatusChanged;
use Porta\Event\DebugVariableEditRequested;
use Porta\Event\DebugWatchRemovalRequested;
use Porta\Filesystem\LocalOnly;
use Porta\I18n\Translator;
use Porta\Support\ProjectPaths;
use Porta\Terminal\TerminalSessions;
use Porta\Ui\Glyphs;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\DebugPanelCallbacks;
use Porta\Widget\DebugPanelWidget;
use Porta\Widget\TerminalPanelWidget;
use Porta\Workbench\Dock\BottomDock;
use Porta\Workbench\Dock\DockTab;
use Porta\Workbench\Notifier;
use Porta\Workbench\StatusCell;
use Porta\Workbench\StatusSegment;
use Porta\Workbench\StatusTone;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\AutowireCallable;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Tui\Tui;

/**
 * The debugger orchestrator: owns the breakpoint store and the (single) DAP
 * session, translates session events into editor decorations, the debug panel
 * and the status bar, and carries the step/stop commands.
 */
#[AsIdeService]
#[AsTaggedItem(priority: 200)]
final class DebugController implements DebugSessionObserver, StatusSegment
{
    private ?DapSession $session = null;

    /** @var array<int, array{id: int, name: string, path: string, line: int}> live frames by id (absolute paths) */
    private array $frames = [];
    /** The frame the panel last selected — watches and evaluate run against it. */
    private ?int $currentFrameId = null;

    /** The launched file's name — restored onto the Debug tab if a stop re-opens it. */
    private string $panelTitle = '';

    /**
     * @param \Closure(list<string>): DapClientInterface $clients the transport for an adapter's argv
     */
    public function __construct(
        private readonly BreakpointStore $breakpoints,
        private readonly DebugAdapters $adapters,
        private readonly CodeEditorWidget $editor,
        private readonly DebugPanelWidget $panel,
        private readonly TerminalPanelWidget $terminalPanel,
        private readonly Notifier $notifier,
        private readonly Tui $tui,
        private readonly Translator $translator,
        private readonly ProjectPaths $paths,
        private readonly FileOpener $files,
        #[AutowireCallable(service: ProcessDapClientFactory::class, method: 'create')]
        private readonly \Closure $clients,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly DebugSessionState $state,
        private readonly EditorDecorations $decorations,
        private readonly WatchStore $watches,
        private readonly ExceptionFilterStore $exceptionFilters,
        private readonly TerminalSessions $sessions,
        private readonly BottomDock $dock,
        private readonly InlineValues $inlineValues,
        private readonly ?LocalOnly $localOnly = null,
    ) {
        // Only the variable expansion stays a closure: it is
        // continuation-passing — the widget hands in the channel the answer
        // comes back through — which a one-way notification cannot express.
        $this->panel->setCallbacks(new DebugPanelCallbacks(
            onExpandVariable: function (int $reference, callable $deliver): void {
                $this->session?->variables($reference, static fn (array $variables) => $deliver($variables));
                $this->tui->requestRender();
            },
        ));
        $this->editor->decorations->whenLinesMove($this->linesMoved(...));
    }

    /**
     * An edit moved the shown file's lines: its breakpoints follow them, in
     * the gutter and in a live session. Left where they were, a line added
     * above (an auto-import) put the dot on the previous statement, and the
     * adapter broke there — or never, on a blank line.
     */
    /**
     * @param array<array-key, string> $before
     * @param array<array-key, string> $after
     */
    private function linesMoved(string $path, array $before, array $after): void
    {
        if ([] === $this->breakpoints->linesFor($path)) {
            return;
        }
        $this->breakpoints->shift($path, LineShift::between($before, $after));
        $this->syncBreakpoints($path);
    }

    /** The panel's edit key on a variable: prompt for a new value and set it live. */
    private function editVariable(int $reference, string $name, string $currentValue): void
    {
        if (null === $this->session || !$this->session->supportsSetVariable()) {
            $this->notifier->warn($this->translator->trans('This debug adapter cannot edit variables'));

            return;
        }
        // The prompt lives on the editor: focus it so it is drivable while the panel holds focus.
        $this->tui->setFocus($this->editor);
        $this->editor->openPrompt($this->translator->trans('Set %name% to', ['%name%' => $name]), $currentValue, function (string $value) use ($reference, $name): void {
            $this->session?->setVariable($reference, $name, $value, function (string $newValue, ?string $error) use ($reference): void {
                if (null !== $error) {
                    $this->notifier->error($error);

                    return;
                }
                // Re-fetch the container so the panel reflects the adapter's stored value.
                $this->session?->variables($reference, fn (array $variables) => $this->panel->setVariables($reference, $variables));
                $this->tui->requestRender();
            });
        });
        $this->tui->requestRender();
    }

    public function isActive(): bool
    {
        return null !== $this->session && $this->session->isActive();
    }

    public function isPaused(): bool
    {
        return null !== $this->session && DebugState::Paused === $this->session->state();
    }

    /** @return array{path: string, line: int}|null where the session is paused: absolute path, 0-based line */
    public function stoppedAt(): ?array
    {
        $path = $this->state->stoppedPath();
        $line = $this->state->stoppedLine();

        return null === $path || null === $line ? null : ['path' => $path, 'line' => $line];
    }

    /**
     * The paused stack, innermost first: absolute paths, 0-based lines.
     * Empty unless paused.
     *
     * @return list<array{id: int, name: string, path: string, line: int}>
     */
    public function frames(): array
    {
        return $this->isPaused() ? \array_values($this->frames) : [];
    }

    /**
     * Every scope of a paused frame with its variables, delivered once all
     * have arrived — in the adapter's scope order. Empty unless paused.
     *
     * @param \Closure(list<array{scope: string, variables: list<array{name: string, value: string, reference: int}>}>): void $deliver
     */
    public function frameVariables(int $frameId, \Closure $deliver): void
    {
        $session = $this->session;
        if (null === $session || !$this->isPaused()) {
            $deliver([]);

            return;
        }
        $session->scopes($frameId, static function (array $scopes) use ($session, $deliver): void {
            $result = [];
            /** @var int $waiting the closures made in the loop below hold it by reference, which mago reads as mixed */
            $waiting = 0;
            foreach ($scopes as $index => $scope) {
                $result[$index] = ['scope' => $scope['name'], 'variables' => []];
                $waiting += $scope['variablesReference'] > 0 ? 1 : 0;
            }
            if (0 === $waiting) {
                $deliver(\array_values($result));

                return;
            }
            foreach ($scopes as $index => $scope) {
                if ($scope['variablesReference'] <= 0) {
                    continue;
                }
                $session->variables($scope['variablesReference'], static function (array $variables) use (&$result, &$waiting, $index, $deliver): void {
                    $result[$index]['variables'] = self::shown($variables);
                    if (0 === --$waiting) {
                        $deliver(\array_values($result));
                    }
                });
            }
        });
    }

    /**
     * A variable's children — an array's items, an object's properties — by
     * the reference it was delivered with (0: it has none). Empty unless
     * paused.
     *
     * @param \Closure(list<array{name: string, value: string, reference: int}>): void $deliver
     */
    public function children(int $reference, \Closure $deliver): void
    {
        $session = $this->session;
        if (null === $session || !$this->isPaused() || $reference <= 0) {
            $deliver([]);

            return;
        }
        $session->variables($reference, static fn (array $variables) => $deliver(self::shown($variables)));
    }

    /**
     * Variables as a plugin is handed them: a non-zero reference opens one.
     *
     * @param list<array{name: string, value: string, variablesReference: int}> $variables
     *
     * @return list<array{name: string, value: string, reference: int}>
     */
    private static function shown(array $variables): array
    {
        return \array_map(static fn (array $variable): array => ['name' => $variable['name'], 'value' => $variable['value'], 'reference' => $variable['variablesReference']], $variables);
    }

    /**
     * The status-bar cell: '⏸ path:line' when paused, '▶ debug' while running,
     * and nothing at all when idle.
     *
     * @return list<StatusCell>
     */
    #[\Override]
    public function statusCells(): array
    {
        if (!$this->isActive()) {
            return [];
        }
        $path = $this->state->stoppedPath();
        $line = $this->state->stoppedLine();
        $paused = $this->isPaused() && null !== $path && null !== $line;
        $text = $paused ? Glyphs::DEBUG_PAUSED.' '.$this->paths->relative($path).':'.($line + 1) : Glyphs::DEBUG_RUNNING.' '.$this->translator->trans('debug');
        $tooltip = $paused ? $this->translator->trans('The debugger is paused at this line') : $this->translator->trans('The debugger is running');

        return [new StatusCell($text, StatusTone::Active, tooltip: $tooltip)];
    }

    /** Ctrl+F8 / a gutter click: toggle a breakpoint on the caret's (or given) line. */
    public function toggleBreakpointAtCaret(): void
    {
        $path = $this->editor->getFilePath();
        if (null === $path) {
            $this->notifier->flash($this->translator->trans('Nothing to debug — open a file first'));

            return;
        }
        $this->toggleBreakpoint($path, $this->editor->getDocument()->cursorLine);
    }

    /** Toggle a breakpoint on a specific 0-based line of a file. */
    public function toggleBreakpoint(string $path, int $line): void
    {
        $this->breakpoints->toggle($path, $line);
        $this->syncBreakpoints($path);
    }

    /** Ctrl+Shift+F8: edit (or create) the caret line's breakpoint condition. */
    public function editBreakpointAtCaret(): void
    {
        $path = $this->editor->getFilePath();
        if (null === $path) {
            $this->notifier->flash($this->translator->trans('Nothing to debug — open a file first'));

            return;
        }
        $line = $this->editor->getDocument()->cursorLine;
        $existing = $this->breakpoints->conditionAt($path, $line);
        // The prompt lives on the editor: focus it so it is drivable even
        // when a dock panel currently owns the focus.
        $this->tui->setFocus($this->editor);
        $this->editor->openPrompt($this->translator->trans('Breakpoint condition (empty: always break)'), $existing['condition'] ?? '', function (string $condition) use (
            $path,
            $line,
            $existing,
        ): void {
            $this->editor->openPrompt($this->translator->trans('Hit count condition (empty: every hit)'), $existing['hitCondition'] ?? '', function (string $hitCondition) use (
                $path,
                $line,
                $existing,
                $condition,
            ): void {
                $this->editor->openPrompt($this->translator->trans('Log message (empty: pause instead of logging)'), $existing['logMessage'] ?? '', function (string $logMessage) use (
                    $path,
                    $line,
                    $condition,
                    $hitCondition,
                ): void {
                    $this->breakpoints->setDetails(
                        $path,
                        $line,
                        '' === \trim($condition) ? null : \trim($condition),
                        '' === \trim($hitCondition) ? null : \trim($hitCondition),
                        '' === \trim($logMessage) ? null : \trim($logMessage),
                    );
                    $this->syncBreakpoints($path);
                });
            });
        });
        $this->tui->requestRender();
    }

    /** Refresh the gutter and push the file's breakpoints to a live session. */
    private function syncBreakpoints(string $path): void
    {
        if ($path === $this->editor->getFilePath()) {
            $this->editor->decorations->setBreakpointLines($this->breakpoints->linesFor($path), $this->breakpoints->conditionalLinesFor($path), $this->breakpoints->logpointLinesFor($path));
        }
        $this->session?->setBreakpoints($path, $this->breakpoints->breakpointsFor($path));
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
        if (true === $this->localOnly?->refused() || $this->alreadyRunning()) {
            return;
        }
        $path = $this->editor->getFilePath();
        null === $path
            ? $this->notifier->flash($this->translator->trans('Nothing to debug — open a file first'))
            : $this->startFor($path);
    }

    /**
     * Start $languageId's debug adapter listening for a debuggee to connect —
     * a web request, a CLI run started elsewhere — rather than running a file.
     */
    public function listenFor(string $languageId): void
    {
        if (true === $this->localOnly?->refused() || $this->alreadyRunning()) {
            return;
        }
        $this->begin(
            $languageId,
            $this->translator->trans('Listening (%language%)', ['%language%' => $languageId]),
            fn (DebugConfig $config): array => LaunchArguments::listen($this->paths->root(), $config->launch),
        );
    }

    /** Whether a session runs already — said, if so: one at a time. */
    private function alreadyRunning(): bool
    {
        if ($this->isActive()) {
            $this->notifier->flash($this->translator->trans('Debugger already running — stop it first (Ctrl+F2)'));
        }

        return $this->isActive();
    }

    /** Launch $path under its language's debug adapter, or say there is none. */
    private function startFor(string $path): void
    {
        $this->begin(
            ProjectPaths::languageId($path),
            \basename($path),
            fn (DebugConfig $config): array => LaunchArguments::build($path, $this->paths->root(), $config->launch, $config->runCommandArgv),
        );
    }

    /**
     * Start $languageId's adapter with the `launch` request $arguments builds,
     * the session's tabs named $label — or say there is no adapter.
     *
     * @param \Closure(DebugConfig): array<string, mixed> $arguments
     */
    private function begin(string $languageId, string $label, \Closure $arguments): void
    {
        $config = $this->adapters->adapterFor($languageId);
        if (null === $config) {
            $this->notifier->flash($this->translator->trans('No debug adapter configured for %language% — set one in Settings', ['%language%' => $languageId]));

            return;
        }

        $this->state->clear();
        $this->frames = [];
        $this->currentFrameId = null;
        $session = $this->sessions->find('debug') ?? $this->sessions->create('debug', '');
        $session->buffer->clear();
        // Not 'Debug: …': that is the debug panel's own tab, beside this one
        // on the same strip — two tabs of one name, and the output was the
        // one nobody could tell apart.
        $session->title = $this->translator->trans('Debug output: %label%', ['%label%' => $label]);
        // The debuggee's output session is created and made active but NOT
        // shown — the dock shows the debug panel; the output sits one
        // terminal-toggle away (ctrl+`).
        $this->sessions->makeActive($session);
        $this->panelTitle = $label;
        $this->panel->open($this->panelTitle);
        $this->panel->setWatches($this->watches->expressions);

        $this->session = new DapSession(
            ($this->clients)($config->adapterArgv),
            $languageId,
            fn (): array => $this->breakpoints->all(),
            $this,
            fn (array $advertised): array => $this->exceptionFilters->enabledFor($advertised),
        );
        if (!$this->session->start($arguments($config))) {
            $this->session = null;
            $this->panel->setSessionState(DebugState::Terminated, null);
            $this->notifier->error($this->translator->trans('Could not start the debug adapter'));
        }
        $this->dispatcher->dispatch(new DebugStatusChanged());
        $this->tui->requestRender();
    }

    public function stepOver(): void
    {
        if (!$this->steppable()) {
            return;
        }
        $this->session?->stepOver();
    }

    public function stepIn(): void
    {
        if (!$this->steppable()) {
            return;
        }
        $this->session?->stepIn();
    }

    public function stepOut(): void
    {
        if (!$this->steppable()) {
            return;
        }
        $this->session?->stepOut();
    }

    public function continueRun(): void
    {
        if (!$this->steppable()) {
            return;
        }
        $this->session?->continueRun();
    }

    /** Stop a running program where it is. */
    public function pause(): void
    {
        if (!$this->isActive() || $this->isPaused()) {
            $this->notifier->flash($this->translator->trans('No running debugger to pause'));

            return;
        }
        $this->session?->pause();
    }

    /**
     * Whether a step verb can act, saying so when it cannot.
     *
     * The four steppers were `$this->session?->stepOver()` and nothing else, so
     * Run ▸ Step Over with no debugger did absolutely nothing, in silence —
     * while their two neighbours on the same menu, {@see restart()} and {@see
     * runToCursor()}, both flashed. The KEY is gated by `when` and correctly
     * falls through to the focused widget; it is the palette and the menu that
     * reach the handler, and the handler that has to answer.
     */
    private function steppable(): bool
    {
        if ($this->isPaused()) {
            return true;
        }
        $this->notifier->flash($this->translator->trans('No paused debugger to step'));

        return false;
    }

    /** Ctrl+F2 and the `run()` teardown: end the session. Idempotent. */
    public function stop(): void
    {
        $this->session?->disconnect();
    }

    /**
     * Restart the running session: the adapter's native restart when it
     * advertises one, otherwise stop and relaunch for the current file.
     */
    public function restart(): void
    {
        if (null === $this->session || !$this->session->isActive()) {
            $this->notifier->flash($this->translator->trans('No debug session to restart'));

            return;
        }
        if ($this->session->supportsRestart()) {
            $this->session->restart();
            $this->tui->requestRender();

            return;
        }
        // Fallback: disconnect the old session and relaunch for the current file.
        $this->stop();
        $this->session = null;
        $this->startForCurrentFile();
    }

    /** Alt+F9: continue to the caret line via a one-shot breakpoint. */
    public function runToCursor(): void
    {
        if (!$this->isPaused()) {
            $this->notifier->flash($this->translator->trans('Run to cursor needs a paused debugger'));

            return;
        }
        $path = $this->editor->getFilePath();
        if (null === $path) {
            $this->notifier->flash($this->translator->trans('Nothing to debug — open a file first'));

            return;
        }
        $this->session?->runTo($path, $this->editor->getDocument()->cursorLine);
    }

    /**
     * The exception-breakpoint chooser: the adapter's advertised filters as
     * checkbox rows; each pick toggles, re-syncs the live session and
     * re-opens (Escape leaves the loop).
     */
    public function exceptionBreakpointsOverlay(): void
    {
        $session = $this->session;
        if (null === $session || !$session->isActive()) {
            $this->notifier->flash($this->translator->trans('No debugger session — exception filters come from the adapter'));

            return;
        }
        $filters = $session->exceptionBreakpointFilters();
        if ([] === $filters) {
            $this->notifier->flash($this->translator->trans('The adapter has no exception breakpoint filters'));

            return;
        }
        $enabled = $this->exceptionFilters->enabledFor($filters);
        $items = \array_map(static fn (array $filter): array => [
            'label' => $filter['label'],
            'checked' => \in_array($filter['filter'], $enabled, strict: true),
            'hint' => $filter['filter'],
            'value' => $filter['filter'],
        ], $filters);
        $this->tui->setFocus($this->editor); // drivable from a focused dock panel too
        $this->editor->openListOverlay($this->translator->trans('Exception Breakpoints'), $items, function (string $id) use ($filters): void {
            $this->exceptionFilters->toggle($id, $filters);
            $this->session?->syncExceptionBreakpoints();
            $this->exceptionBreakpointsOverlay();
        });
        $this->tui->requestRender();
    }

    #[\Override]
    public function onStopped(string $reason): void
    {
        $session = $this->session;
        if (null === $session) {
            return;
        }
        $this->revealPanel();
        $session->stackTrace(function (array $frames) use ($session): void {
            // Answered after the user resumed (F9, F8 pressed before it came)
            // or after the session ended: the frames are of a stop that is
            // over, and painting them said Paused over a running program.
            if ($session !== $this->session || DebugState::Paused !== $session->state()) {
                return;
            }
            $this->frames = \array_column($frames, null, 'id');
            $display = \array_map(fn (array $frame): array => [...$frame, 'path' => $this->paths->relative($frame['path'])], $frames);
            $top = $frames[0] ?? null;
            if (null === $top || '' === $top['path']) {
                $this->panel->setSessionState(DebugState::Paused, null);
                $this->panel->setFrames($display);
                $this->dispatcher->dispatch(new DebugStatusChanged());
                $this->tui->requestRender();

                return;
            }
            $this->showStop($top['path'], $top['line'], $top['id']);
            $this->panel->setSessionState(DebugState::Paused, $this->paths->relative($top['path']).':'.($top['line'] + 1));
            $this->loadScopes($session, $top['id']);
            $this->panel->setFrames($display);
            $this->dispatcher->dispatch(new DebugStatusChanged());
            $this->tui->requestRender();
        });
    }

    /**
     * A stop always surfaces the Debug tab: re-opened (watches reseeded) if
     * the user closed it, revealed if another tab owns the dock slot.
     */
    private function revealPanel(): void
    {
        if (!$this->panel->isOpen()) {
            $this->panel->open($this->panelTitle);
            $this->panel->setWatches($this->watches->expressions);

            return;
        }
        $this->dock->reveal(DockTab::Debug); // a no-op when already visible
    }

    private function loadScopes(DapSession $session, int $frameId): void
    {
        $this->currentFrameId = $frameId;
        $this->reevaluateWatches();
        $session->scopes($frameId, function (array $scopes) use ($session): void {
            $this->panel->setScopes($scopes);
            foreach ($scopes as $scope) {
                if ($scope['variablesReference'] <= 0) {
                    continue;
                }

                // The same delivery feeds two readers: the panel, which lists
                // them by scope, and the inline values, which want them by name.
                $session->variables($scope['variablesReference'], function (array $variables) use ($scope): void {
                    $this->panel->setVariables($scope['variablesReference'], $variables);
                    $this->inlineValues->addVariables($variables);
                });
            }
            $this->tui->requestRender();
        });
    }

    /** $frameId is passed rather than read from `$currentFrameId`: `loadScopes()` sets that AFTER this runs. */
    private function showStop(string $path, int $line, int $frameId): void
    {
        $this->state->stopAt($path, $line);
        $this->inlineValues->stoppedAt($path, $line, $frameId);
        $this->files->openFile($path);
        $this->editor->jumpTo($line, 0);
        $this->applyEditorDecorations($path);
    }

    #[AsEventListener]
    public function onFrameSelected(DebugFrameSelected $event): void
    {
        // The path and line ride along for observers; the controller has the
        // frame table and reads them from there.
        $this->jumpToFrame($event->frameId);
    }

    #[AsEventListener]
    public function onVariableEditRequested(DebugVariableEditRequested $event): void
    {
        $this->editVariable($event->parentReference, $event->name, $event->value);
    }

    #[AsEventListener]
    public function onWatchRemoved(DebugWatchRemovalRequested $event): void
    {
        $this->watches->remove($event->index);
        $this->panel->setWatches($this->watches->expressions);
        $this->reevaluateWatches();
        $this->tui->requestRender();
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

    /** Ctrl+Shift+F9: add a watch expression (evaluated immediately when paused). */
    public function addWatch(): void
    {
        $this->tui->setFocus($this->editor); // drivable from a focused dock panel too
        $this->editor->openPrompt($this->translator->trans('Watch expression'), '', function (string $expression): void {
            $expression = \trim($expression);
            if ('' === $expression) {
                return;
            }
            $this->watches->add($expression);
            $this->panel->setWatches($this->watches->expressions);
            $this->reevaluateWatches();
        });
        $this->tui->requestRender();
    }

    /** The palette's one-shot evaluate (paused only): result lands in a flash. */
    public function evaluatePrompt(): void
    {
        // BEFORE the prompt, not inside its callback. Find Action lists this
        // verb whether or not `when` holds, and the guard used to sit on the
        // far side of the input: the prompt opened, the user typed an
        // expression, and it was dropped on the floor without a word.
        if (!$this->isPaused()) {
            $this->notifier->flash($this->translator->trans('Evaluate needs a paused debugger'));

            return;
        }
        $this->tui->setFocus($this->editor); // drivable from a focused dock panel too
        $this->editor->openPrompt($this->translator->trans('Evaluate expression'), '', function (string $expression): void {
            $expression = \trim($expression);
            if ('' === $expression || null === $this->session) {
                return;
            }
            $this->session->evaluate($expression, $this->currentFrameId, 'repl', function (array $result) use ($expression): void {
                $this->notifier->flash($expression.' = '.($result['error'] ?? $result['result']), 5.0);
            });
        });
    }

    /** Evaluate every watch against the current frame; results land index-addressed. */
    private function reevaluateWatches(): void
    {
        $session = $this->session;
        if (null === $session || DebugState::Paused !== $session->state()) {
            return;
        }
        foreach ($this->watches->expressions as $index => $expression) {
            $session->evaluate($expression, $this->currentFrameId, 'watch', function (array $result) use ($index): void {
                $this->panel->setWatchResult($index, $result['result'], $result['variablesReference'], $result['error']);
                $this->tui->requestRender();
            });
        }
    }

    #[\Override]
    public function onContinued(): void
    {
        $this->state->clear();
        // The values were facts about a frame that is running again.
        $this->inlineValues->clear();
        $this->editor->decorations->setDebugLine(null);
        $this->panel->setSessionState(DebugState::Running, null);
        $this->dispatcher->dispatch(new DebugStatusChanged());
        $this->tui->requestRender();
    }

    #[\Override]
    public function onOutput(string $category, string $text): void
    {
        $session = $this->sessions->find('debug');
        $session?->buffer->feed(\str_replace("\n", replace: "\r\n", subject: $text));
        if (null !== $session && $session === $this->sessions->active()) {
            $this->terminalPanel->refresh();
        }
        $this->tui->requestRender();
    }

    #[\Override]
    public function onTerminated(): void
    {
        $this->state->clear();
        $this->frames = [];
        $this->inlineValues->clear();
        $this->editor->decorations->setDebugLine(null);
        $this->panel->setSessionState(DebugState::Terminated, null);
        $this->dispatcher->dispatch(new DebugStatusChanged());
        $this->tui->requestRender();
    }

    #[\Override]
    public function onCrashed(): void
    {
        $this->notifier->error($this->translator->trans('The debug adapter stopped unexpectedly'), 5.0);
        $this->onTerminated();
    }

    #[\Override]
    public function onError(string $message): void
    {
        $this->notifier->error($this->translator->trans('Could not start the debug adapter').' — '.$message, 5.0);
        $this->onTerminated();
    }
}
