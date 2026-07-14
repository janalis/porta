<?php

declare(strict_types=1);

namespace Porta\Git;

use Porta\I18n\Translator;
use Porta\Support\ProjectPaths;
use Porta\Ui\Notifier;
use Porta\Widget\CodeEditorWidget;
use Symfony\Component\Tui\Tui;

/**
 * The git actions invoked from the editor/command palette — stage the current
 * file, commit, and blame the caret line. Each is a thin orchestration of the
 * async {@see GitInfo} client plus a status flash and a redraw; keeping them
 * here leaves the IDE coordinator out of the git-command business. The full
 * changes/diff experience lives in {@see GitViewController}.
 *
 */
final class GitCommands
{
    public function __construct(
        private readonly GitInfo $git,
        private readonly CodeEditorWidget $editor,
        private readonly Notifier $notifier,
        private readonly Tui $tui,
        private readonly Translator $translator,
        private readonly GitRefresher $refresher,
        private readonly string $rootDir,
    ) {}

    public function stageCurrent(): void
    {
        $path = $this->editor->getFilePath();
        if (null === $path || !$this->git->isAvailable()) {
            $this->unavailable();

            return;
        }
        $this->git->stage($path, function (bool $ok) use ($path): void {
            $this->notifier->flash(
                $ok
                    ? $this->translator->trans('Staged %path%', ['%path%' => ProjectPaths::relative($this->rootDir, $path)])
                    : $this->translator->trans('Stage failed'),
            );
            $this->refresher->refreshActiveMarks();
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
        $this->editor->openPrompt($this->translator->trans('Commit message'), '', function (string $message): void {
            if ('' === trim($message)) {
                return;
            }
            $this->git->commit($message, function (bool $ok) use ($message): void {
                $this->notifier->flash(
                    $ok
                        ? $this->translator->trans('Committed: %summary%', ['%summary%' => $message])
                        : $this->translator->trans('Commit failed (nothing staged?)'),
                );
                $this->refresher->refreshBranch();
                $this->refresher->refreshActiveMarks();
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
                $this->notifier->flash(
                    null === $blame
                        ? $this->translator->trans('No blame for this line')
                        : \sprintf('%s  %s: %s', $blame['sha'], $blame['author'], $blame['summary']),
                );
                $this->tui->requestRender();
            },
        );
    }

    /** Undo the last commit (soft reset), keeping its changes staged. */
    public function undoLastCommit(): void
    {
        if (!$this->git->isAvailable()) {
            $this->unavailable();

            return;
        }
        $this->git->undoLastCommit(function (bool $ok): void {
            $this->notifier->flash(
                $ok
                    ? $this->translator->trans('Last commit undone (changes kept staged)')
                    : $this->translator->trans('Nothing to undo'),
            );
            $this->refresher->refreshBranch();
            $this->refresher->refreshActiveMarks();
            $this->tui->requestRender();
        });
    }

    private function unavailable(): void
    {
        $this->notifier->flash($this->translator->trans('Git not available'));
    }
}
