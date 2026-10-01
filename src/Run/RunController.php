<?php

declare(strict_types=1);

namespace Porta\Run;

use Porta\Container\AsIdeService;
use Porta\Event\TerminalRevealRequested;
use Porta\Event\TerminalSessionCloseRequested;
use Porta\Event\TerminalSessionCycleRequested;
use Porta\I18n\Translator;
use Porta\Terminal\PtyProcess;
use Porta\Terminal\TerminalKeyEncoder;
use Porta\Terminal\TerminalSession;
use Porta\Terminal\TerminalSessions;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\TerminalPanelWidget;
use Porta\Workbench\Notifier;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Tui\Tui;

/**
 * The run/terminal feature: discovering runnable tasks (Run… menu), re-running
 * the last one, the interactive terminal tabs, and the PTY processes whose
 * output streams into the shared panel. Owns the {@see TerminalSessions}
 * lifecycle: shells (`shell:N`), run configurations (`run:<label>`, re-run
 * replaces only that session's process) and the last-run state.
 */
#[AsIdeService]
final class RunController
{
    /** @var array{label: string, command: list<string>}|null */
    private ?array $lastRun = null;

    private int $shellCounter = 0;

    /**
     * Who is waiting for a run to end, by session id — told the exit code,
     * or -1 when the run is replaced or closed first: a killed process never
     * reports an exit, and a waiter must not wait for one.
     *
     * @var array<string, \Closure(int): void>
     */
    private array $waiting = [];

    /**
     * Who else reads a session's output as it arrives, by session id — a
     * terminal shared with collaborators.
     *
     * @var array<string, \Closure(string): void>
     */
    private array $watchers = [];

    public function __construct(
        private readonly RunConfigs $runConfigs,
        private readonly TerminalPanelWidget $terminalPanel,
        private readonly TerminalKeyEncoder $terminalKeys,
        // Deliberately concrete: besides the file/overlay roles, the run
        // picker hands the widget to setFocus() so the list is drivable.
        private readonly CodeEditorWidget $editor,
        private readonly Notifier $notifier,
        private readonly Tui $tui,
        private readonly Translator $translator,
        private readonly string $rootDir,
        private readonly TerminalSessions $sessions,
    ) {}

    public function openRunMenu(): void
    {
        $tasks = $this->runConfigs->discover($this->editor->getFilePath());
        if ([] === $tasks) {
            $this->notifier->flash($this->translator->trans('Nothing to run (no PHP file, composer scripts, or Makefile)'));

            return;
        }

        $items = [];
        foreach ($tasks as $index => $task) {
            $items[] = ['label' => $task['label'], 'hint' => '', 'value' => (string) $index];
        }
        // The overlay lives on the editor: focus it so the picker is drivable
        // even when the terminal panel currently owns the focus.
        $this->tui->setFocus($this->editor);
        $this->editor->openListOverlay($this->translator->trans('Run'), $items, function (string $index) use ($tasks): void {
            $task = $tasks[(int) $index] ?? null;
            if (null !== $task) {
                $this->lastRun = $task;
                $this->runInTerminal($task['command'], $task['label']);
            }
        });
        $this->tui->requestRender();
    }

    /**
     * The tasks the Run menu would offer right now — the active file, the
     * composer scripts, the Makefile targets.
     *
     * @return list<array{label: string, command: list<string>}>
     */
    public function configurations(): array
    {
        return $this->runConfigs->discover($this->editor->getFilePath());
    }

    /**
     * Run a command in its `run:<title>` session WITHOUT taking the screen:
     * the tab appears beside the others, but the panel is neither opened nor
     * focused and the tab the user is looking at stays the active one. For a
     * run the user did not start — an agent's, through a plugin.
     *
     * A run of the same title already going is replaced, exactly as a re-run
     * from the menu replaces it.
     *
     * @param list<string>                $command
     * @param (\Closure(int): void)|null  $onExit  the exit code; -1 when the run is replaced or closed first
     */
    public function runInBackground(array $command, string $title, ?\Closure $onExit = null): void
    {
        $shown = $this->sessions->active();
        $session = $this->sessionFor($title);
        if (null !== $onExit) {
            $this->waiting[$session->id] = $onExit;
        }
        $this->start($this->attach($session, $command));
        if (null !== $shown) {
            $this->sessions->makeActive($shown);
        }
    }

    /** The session a run of $title streams into, or null when there has been none. */
    public function runSession(string $title): ?TerminalSession
    {
        return $this->sessions->find('run:'.$title);
    }

    public function runLast(): void
    {
        if (null === $this->lastRun) {
            $this->notifier->flash($this->translator->trans('No previous run — press Ctrl+Shift+R to pick one'));

            return;
        }
        $this->runInTerminal($this->lastRun['command'], $this->lastRun['label']);
    }

    public function toggleTerminal(): void
    {
        if ($this->terminalPanel->isVisible()) {
            // Wired: the dock hides and the sessions keep running; a lone
            // widget (unit tests) falls back to a real close.
            $this->terminalPanel->dismiss();

            return;
        }
        // Reopen the active session, or spawn the first shell.
        $active = $this->sessions->active();
        if (null !== $active) {
            $this->showActive($active);
        }
        if (null === $active) {
            $this->newTerminalSession();
        }
    }

    /** Reveal the active session in the terminal panel — the dock's Terminal tab. */
    #[AsEventListener]
    public function onRevealRequested(TerminalRevealRequested $event): void
    {
        $this->showActiveSession();
    }

    public function showActiveSession(): void
    {
        $active = $this->sessions->active();
        if (null !== $active) {
            $this->showActive($active);
        }
    }

    /** Ctrl+Shift+` (or Alt+F11): spawn another interactive shell session. */
    public function newTerminalSession(): void
    {
        $shellEnv = \getenv('SHELL');
        $shell = $shellEnv ? $shellEnv : '/bin/sh';
        $session = $this->sessions->create('shell:'.++$this->shellCounter, \basename($shell).' '.$this->shellCounter);
        $this->spawn($session, [$shell, '-i']);
    }

    /**
     * Run a command in its own `run:<title>` session (reused across re-runs),
     * replacing only that session's process — the shell and other runs survive.
     *
     * @param list<string> $command
     */
    private function runInTerminal(array $command, string $title): void
    {
        $this->spawn($this->sessionFor($title), $command);
    }

    /** The `run:<title>` session, emptied for a new run — or a new one. */
    private function sessionFor(string $title): TerminalSession
    {
        $found = $this->sessions->find('run:'.$title);
        if (null === $found) {
            return $this->sessions->create('run:'.$title, $title);
        }
        $this->release($found->id);
        $found->buffer->clear();
        $found->title = $title; // drop any stale exit-code "(N)" suffix
        $this->sessions->makeActive($found);

        return $found;
    }

    /** Tell whoever waits on $id's run that it will never report: it is being replaced or closed. */
    private function release(string $id): void
    {
        $waiter = $this->waiting[$id] ?? null;
        unset($this->waiting[$id]);
        if (null !== $waiter) {
            $waiter(-1);
        }
    }

    /**
     * Spawn a PTY into a session, show it, and stream its output. The panel
     * only refreshes for the active session — background output must not yank
     * the live tab's scroll position.
     *
     * @param list<string> $command
     */
    private function spawn(TerminalSession $session, array $command): void
    {
        $process = $this->attach($session, $command);
        $this->showActive($session);
        $this->start($process);
    }

    /**
     * A PTY for $command, wired to $session's scrollback and to whoever waits
     * on it, attached in place of the session's previous process.
     *
     * @param list<string> $command
     */
    private function attach(TerminalSession $session, array $command): PtyProcess
    {
        $process = new PtyProcess($command, $this->rootDir, columns: 100, rows: 24);
        $process
            ->onOutput(function (string $chunk) use ($session): void {
                $this->received($session, $chunk);
                ($this->watchers[$session->id] ?? null)?->__invoke($chunk);
            })
            ->onExit(function (int $code) use ($session): void {
                $session->title = \sprintf('%s (%d)', $session->title, $code);
                $waiter = $this->waiting[$session->id] ?? null;
                unset($this->waiting[$session->id]);
                if (null !== $waiter) {
                    $waiter($code);
                }
                $this->tui->requestRender();
            });
        $session->attach($process);

        return $process;
    }

    private function start(PtyProcess $process): void
    {
        if (!$process->start()) {
            // Spawn failure needs a PTY-less system; unreachable here.
            // @codeCoverageIgnoreStart
            $this->notifier->error($this->translator->trans('Could not start the terminal process'));

            // @codeCoverageIgnoreEnd
        }
    }

    /** Cycle to the next (+1) or previous (-1) session tab. */
    #[AsEventListener]
    public function onSessionCycled(TerminalSessionCycleRequested $event): void
    {
        $this->cycleSession($event->delta);
    }

    public function cycleSession(int $delta): void
    {
        if (null !== ($session = $delta > 0 ? $this->sessions->next() : $this->sessions->prev())) {
            $this->showActive($session);
        }
    }

    /** Close a session tab ($index, or -1 for the active one); closes the panel when the last goes. */
    #[AsEventListener]
    public function onSessionClosed(TerminalSessionCloseRequested $event): void
    {
        $this->closeSession($event->index);
    }

    public function closeSession(int $index): void
    {
        $target = -1 === $index ? $this->sessions->activeIndex : $index;
        $closing = $this->sessions->sessions[$target] ?? null;
        if (null !== $closing) {
            $this->release($closing->id);
        }
        $survivor = $this->sessions->close($target);
        if (null === $survivor) {
            $this->terminalPanel->close();

            return;
        }
        $this->showActive($survivor);
    }

    /** Show a session in the panel and focus it. */
    private function showActive(TerminalSession $session): void
    {
        $this->terminalPanel->open($session->title, $session->buffer);
        $this->tui->setFocus($this->terminalPanel);
        $this->tui->requestRender();
    }

    /**
     * Forward panel keystrokes to the active session's process (terminal key
     * encoding applied) — or, for a session whose process runs elsewhere, to
     * whoever relays them there.
     */
    public function forwardInput(string $data): void
    {
        $bytes = $this->terminalKeys->encode($data);
        $active = $this->sessions->active();
        if ('' === $bytes || null === $active) {
            return;
        }
        null === $active->process ? ($active->input ?? static function (string $bytes): void {})($bytes) : $active->process->write($bytes);
    }

    /** @return list<array{id: string, title: string, running: bool}> every terminal tab, in tab order */
    public function terminals(): array
    {
        return \array_map(static fn (TerminalSession $session): array => ['id' => $session->id, 'title' => $session->title, 'running' => $session->isRunning()], $this->sessions->sessions);
    }

    /** What $id shows, as it would be printed again (colours kept, CR LF line ends), or null without such a tab. */
    public function scrollback(string $id): ?string
    {
        $session = $this->sessions->find($id);

        return null === $session ? null : \implode("\r\n", $session->buffer->lines);
    }

    /** Type $bytes into $id's process; false without such a tab, or a process running in it. */
    public function write(string $id, string $bytes): bool
    {
        $process = $this->sessions->find($id)?->process;
        if (null === $process || !$process->isRunning()) {
            return false;
        }
        $process->write($bytes);

        return true;
    }

    /** Read $id's output as it arrives — or stop, with null. False when there is no such session. */
    public function watch(string $id, ?\Closure $onOutput): bool
    {
        if (null === $this->sessions->find($id)) {
            return false;
        }
        unset($this->watchers[$id]);
        if (null !== $onOutput) {
            $this->watchers[$id] = $onOutput;
        }

        return true;
    }

    /**
     * The `$id` session for a terminal whose process runs elsewhere: made
     * the first time, retitled after — behind the tab in front, as a run in
     * the background is. Its output is what {@see feed()} gives it; the keys
     * typed into it go to $onInput.
     *
     * @param (\Closure(string): void)|null $onInput
     */
    public function mirror(string $id, string $title, ?\Closure $onInput): void
    {
        $shown = $this->sessions->active();
        $session = $this->sessions->find($id) ?? $this->sessions->create($id, $title);
        $session->title = $title;
        $session->input = $onInput;
        if (null !== $shown) {
            $this->sessions->makeActive($shown);
        }
        $this->tui->requestRender();
    }

    /** Output for $id, as though its process printed it. */
    public function feed(string $id, string $bytes): void
    {
        $session = $this->sessions->find($id);
        if (null !== $session) {
            $this->received($session, $bytes);
        }
    }

    private function received(TerminalSession $session, string $bytes): void
    {
        $session->buffer->feed($bytes);
        if ($session === $this->sessions->active()) {
            $this->terminalPanel->refresh();
        }
        $this->tui->requestRender();
    }

    /** Stop every session's process (app shutdown). */
    public function stop(): void
    {
        $this->sessions->stopAll();
    }
}
