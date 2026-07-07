<?php

declare(strict_types=1);

namespace Porta\Run;

use Porta\I18n\Translator;
use Porta\Terminal\PtyProcess;
use Porta\Terminal\TerminalBuffer;
use Porta\Terminal\TerminalKeyEncoder;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\StatusBarWidget;
use Porta\Widget\TerminalPanelWidget;
use Symfony\Component\Tui\Tui;

/**
 * The run/terminal feature: discovering runnable tasks (Run… menu), re-running
 * the last one, the interactive terminal toggle, and the PTY process whose
 * output streams into the terminal panel. Owns the process/buffer/last-run
 * state the coordinator used to carry.
 */
final class RunController
{
    private ?PtyProcess $terminal = null;
    private ?TerminalBuffer $terminalBuffer = null;
    /** @var array{label: string, command: list<string>}|null */
    private ?array $lastRun = null;

    public function __construct(
        private readonly RunConfigs $runConfigs,
        private readonly TerminalPanelWidget $terminalPanel,
        private readonly TerminalKeyEncoder $terminalKeys,
        private readonly CodeEditorWidget $editor,
        private readonly StatusBarWidget $statusBar,
        private readonly Tui $tui,
        private readonly Translator $translator,
        private readonly string $rootDir,
    ) {}

    public function openRunMenu(): void
    {
        $tasks = $this->runConfigs->discover($this->editor->getFilePath());
        if ([] === $tasks) {
            $this->statusBar->flash($this->translator->trans('Nothing to run (no PHP file, composer scripts, or Makefile)'));
            $this->tui->requestRender();

            return;
        }

        $items = [];
        foreach ($tasks as $index => $task) {
            $items[] = ['label' => $task['label'], 'hint' => '', 'value' => (string) $index];
        }
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
            $this->statusBar->flash($this->translator->trans('No previous run — press Ctrl+Shift+R to pick one'));
            $this->tui->requestRender();

            return;
        }
        $this->runInTerminal($this->lastRun['command'], $this->lastRun['label']);
    }

    public function toggleTerminal(): void
    {
        if ($this->terminalPanel->isOpen()) {
            $this->terminalPanel->close();

            return;
        }
        // A default interactive shell; keeps running while the panel is hidden.
        if (null === $this->terminal || !$this->terminal->isRunning()) {
            $shell = getenv('SHELL') ?: '/bin/sh';
            $this->runInTerminal([$shell, '-i'], basename($shell));
        } else {
            /** @var TerminalBuffer $buffer */
            $buffer = $this->terminalBuffer;
            $this->terminalPanel->open($this->terminalPanel->getTitle(), $buffer);
        }
        $this->tui->setFocus($this->terminalPanel);
        $this->tui->requestRender();
    }

    /**
     * Run a command in the terminal panel, streaming its output live.
     *
     * @param list<string> $command
     */
    private function runInTerminal(array $command, string $title): void
    {
        $this->terminal?->stop();
        $this->terminalBuffer = new TerminalBuffer();

        $this->terminal = new PtyProcess($command, $this->rootDir, columns: 100, rows: 24);
        $this->terminal
            ->onOutput(function (string $chunk): void {
                /** @var TerminalBuffer $buffer */
                $buffer = $this->terminalBuffer;
                $buffer->feed($chunk);
                $this->terminalPanel->refresh();
                $this->tui->requestRender();
            })
            ->onExit(function (int $code): void {
                $this->terminalPanel->setTitle(\sprintf('%s — exited (%d)', $this->terminalPanel->getTitle(), $code));
                $this->tui->requestRender();
            });

        $this->terminalPanel->open($title, $this->terminalBuffer);
        if (!$this->terminal->start()) {
            // Spawn failure needs a PTY-less system; unreachable here.
            // @codeCoverageIgnoreStart
            $this->statusBar->flash($this->translator->trans('Could not start the terminal process'));
            $this->terminal = null;

            // @codeCoverageIgnoreEnd
        }
        $this->tui->setFocus($this->terminalPanel);
        $this->tui->requestRender();
    }

    /** Forward panel keystrokes to the child process (terminal key encoding applied). */
    public function forwardInput(string $data): void
    {
        $bytes = $this->terminalKeys->encode($data);
        if ('' !== $bytes) {
            $this->terminal?->write($bytes);
        }
    }

    /** Stop the child process (app shutdown). */
    public function stop(): void
    {
        $this->terminal?->stop();
    }
}
