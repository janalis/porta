<?php

declare(strict_types=1);

namespace Porta\Git;

use Porta\Widget\CodeEditorWidget;
use Porta\Widget\StatusBarWidget;
use Symfony\Component\Tui\Tui;

/**
 * The git actions invoked from the editor/command palette — stage the current
 * file, commit, and blame the caret line. Each is a thin orchestration of the
 * async {@see GitInfo} client plus a status flash and a redraw; keeping them
 * here leaves the IDE coordinator out of the git-command business. The full
 * changes/diff experience lives in {@see GitViewController}.
 *
 * The three closures are the seams back to the coordinator: refresh the active
 * buffer's change marks, refresh the branch indicator, and format a path for
 * display.
 */
final class GitCommands
{
    /**
     * @param \Closure(): void       $refreshActiveMarks
     * @param \Closure(): void       $refreshBranch
     * @param \Closure(string): string $relativePath
     */
    public function __construct(
        private readonly GitInfo $git,
        private readonly CodeEditorWidget $editor,
        private readonly StatusBarWidget $statusBar,
        private readonly Tui $tui,
        private readonly \Closure $refreshActiveMarks,
        private readonly \Closure $refreshBranch,
        private readonly \Closure $relativePath,
    ) {}

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
