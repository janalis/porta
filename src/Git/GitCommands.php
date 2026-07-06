<?php

declare(strict_types=1);

namespace Porta\Git;

use Porta\Editor\DiffParser;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\DiffPanelWidget;
use Porta\Widget\ResultsPanelWidget;
use Porta\Widget\StatusBarWidget;
use Porta\Widget\TerminalPanelWidget;
use Symfony\Component\Tui\Tui;

/**
 * The git actions invoked from the editor/command palette — stage the current
 * file, commit, blame the caret line, and show the current file's diff vs
 * HEAD. Each is a thin orchestration of the async {@see GitInfo} client plus a
 * status flash and a redraw; keeping them here leaves the IDE coordinator out
 * of the git-command business.
 *
 * The three closures are the seams back to the coordinator: refresh the active
 * buffer's change marks, refresh the branch indicator, and format a path for
 * display.
 */
final class GitCommands
{
    /**
     * @param \Closure(): void       $refreshActiveMarks recompute the active buffer's git marks
     * @param \Closure(): void       $refreshBranch      refresh the branch indicator
     * @param \Closure(string): string $relativePath     format an absolute path for display
     */
    public function __construct(
        private readonly GitInfo $git,
        private readonly CodeEditorWidget $editor,
        private readonly StatusBarWidget $statusBar,
        private readonly Tui $tui,
        private readonly DiffPanelWidget $diffPanel,
        private readonly TerminalPanelWidget $terminalPanel,
        private readonly ResultsPanelWidget $resultsPanel,
        private readonly \Closure $refreshActiveMarks,
        private readonly \Closure $refreshBranch,
        private readonly \Closure $relativePath,
    ) {}

    /**
     * Show the current file's unified diff against HEAD in the bottom panel.
     */
    public function openDiff(): void
    {
        $path = $this->editor->getFilePath();
        if (null === $path) {
            $this->statusBar->flash('No file to diff');
            $this->tui->requestRender();

            return;
        }
        $rel = ($this->relativePath)($path);
        $this->git->fileDiff($path, function (string $raw) use ($rel): void {
            $rows = DiffParser::parse($raw);
            if ([] === $rows) {
                $this->statusBar->flash(\sprintf('%s — no changes vs HEAD', $rel));
                $this->tui->requestRender();

                return;
            }
            // Only one bottom panel is visible at a time.
            $this->terminalPanel->close();
            $this->resultsPanel->close();
            $this->diffPanel->open(\sprintf('Diff: %s (vs HEAD)', $rel), $rows);
            $this->tui->setFocus($this->diffPanel);
            $this->tui->requestRender();
        });
    }

    public function stageCurrent(): void
    {
        $path = $this->editor->getFilePath();
        if (null === $path || !$this->git->isAvailable()) {
            $this->unavailable();

            return;
        }
        $this->git->stage($path, function (bool $ok) use ($path): void {
            $this->statusBar->flash($ok ? 'Staged '.($this->relativePath)($path) : 'Stage failed');
            ($this->refreshActiveMarks)();
            $this->tui->requestRender();
        });
    }

    public function commit(): void
    {
        if (!$this->git->isAvailable()) {
            $this->unavailable();

            return;
        }
        $this->tui->setFocus($this->editor);
        $this->editor->openPrompt('Commit message', '', function (string $message): void {
            if ('' === trim($message)) {
                return;
            }
            $this->git->commit($message, function (bool $ok) use ($message): void {
                $this->statusBar->flash($ok ? 'Committed: '.$message : 'Commit failed (nothing staged?)');
                ($this->refreshBranch)();
                ($this->refreshActiveMarks)();
                $this->tui->requestRender();
            });
        });
        $this->tui->requestRender();
    }

    public function blameLine(): void
    {
        $path = $this->editor->getFilePath();
        if (null === $path || !$this->git->isAvailable()) {
            $this->unavailable();

            return;
        }
        $this->git->blameLine(
            $path,
            $this->editor->getDocument()->getCursorLine(),
            /** @param array{sha: string, author: string, summary: string}|null $blame */ function (?array $blame): void {
                $this->statusBar->flash(
                    null === $blame
                        ? 'No blame for this line'
                        : \sprintf('%s  %s: %s', $blame['sha'], $blame['author'], $blame['summary']),
                );
                $this->tui->requestRender();
            },
        );
    }

    private function unavailable(): void
    {
        $this->statusBar->flash('Git not available');
        $this->tui->requestRender();
    }
}
