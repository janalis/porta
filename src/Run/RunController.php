<?php

declare(strict_types=1);

namespace Porta\Run;

use Porta\Container\AsIdeService;
use Porta\I18n\Translator;
use Porta\Terminal\PtyProcess;
use Porta\Terminal\TerminalKeyEncoder;
use Porta\Terminal\TerminalSession;
use Porta\Terminal\TerminalSessions;
use Porta\Ui\Notifier;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\TerminalPanelWidget;
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

    public function __construct(
        private readonly RunConfigs $runConfigs,
        private readonly TerminalPanelWidget $terminalPanel,
        private readonly TerminalKeyEncoder $terminalKeys,
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
        $shellEnv = getenv('SHELL');
        $shell = $shellEnv ? $shellEnv : '/bin/sh';
        $session = $this->sessions->create('shell:'.++$this->shellCounter, basename($shell).' '.$this->shellCounter);
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
        $found = $this->sessions->find('run:'.$title);
        if (null !== $found) {
            $found->buffer->clear();
            $found->setTitle($title); // drop any stale exit-code "(N)" suffix
            $this->sessions->makeActive($found);
        }
        $session = $found ?? $this->sessions->create('run:'.$title, $title);
        $this->spawn($session, $command);
    }

    /**
     * Spawn a PTY into a session and stream its output. The panel only
     * refreshes for the active session — background output must not yank the
     * live tab's scroll position.
     *
     * @param list<string> $command
     */
    private function spawn(TerminalSession $session, array $command): void
    {
        $process = new PtyProcess($command, $this->rootDir, columns: 100, rows: 24);
        $process
            ->onOutput(function (string $chunk) use ($session): void {
                $session->buffer->feed($chunk);
                if ($session === $this->sessions->active()) {
                    $this->terminalPanel->refresh();
                }
                $this->tui->requestRender();
            })
            ->onExit(function (int $code) use ($session): void {
                $session->setTitle(\sprintf('%s (%d)', $session->title(), $code));
                $this->tui->requestRender();
            });
        $session->attach($process);

        $this->showActive($session);
        if (!$process->start()) {
            // Spawn failure needs a PTY-less system; unreachable here.
            // @codeCoverageIgnoreStart
            $this->notifier->error($this->translator->trans('Could not start the terminal process'));

            // @codeCoverageIgnoreEnd
        }
    }

    /** Cycle to the next (+1) or previous (-1) session tab. */
    public function cycleSession(int $delta): void
    {
        if (null !== ($session = $delta > 0 ? $this->sessions->next() : $this->sessions->prev())) {
            $this->showActive($session);
        }
    }

    /** Close a session tab ($index, or -1 for the active one); closes the panel when the last goes. */
    public function closeSession(int $index): void
    {
        $target = -1 === $index ? $this->sessions->activeIndex() : $index;
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
        $this->terminalPanel->open($session->title(), $session->buffer);
        $this->tui->setFocus($this->terminalPanel);
        $this->tui->requestRender();
    }

    /** Forward panel keystrokes to the active session's process (terminal key encoding applied). */
    public function forwardInput(string $data): void
    {
        $bytes = $this->terminalKeys->encode($data);
        if ('' !== $bytes) {
            $this->sessions->active()?->process()?->write($bytes);
        }
    }

    /** Stop every session's process (app shutdown). */
    public function stop(): void
    {
        $this->sessions->stopAll();
    }
}
