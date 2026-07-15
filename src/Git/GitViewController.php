<?php

declare(strict_types=1);

namespace Porta\Git;

use Porta\Event\GitCommitted;
use Porta\Event\GitRemoteCompleted;
use Porta\I18n\Translator;
use Porta\Layout\EditorAreaPanes;
use Porta\Layout\LayoutController;
use Porta\Support\Clipboard;
use Porta\Support\ProjectPaths;
use Porta\Ui\EditorArea;
use Porta\Ui\Notifier;
use Porta\Ui\WorkspaceViews;
use Porta\Widget\MenuBarWidget;
use Psr\EventDispatcher\EventDispatcherInterface;
use Revolt\EventLoop;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\FocusableInterface;

/**
 * The GitHub-Desktop-style git view: swaps the main area's tree|editor
 * children for [changes list + commit box | diff], keeps the changes model
 * fresh (on every mutation plus a 2s poll while open), and runs the
 * stage/unstage/discard/commit pipelines against {@see GitInfo}.
 */
final class GitViewController
{
    private bool $open = false;
    /** @var list<ChangeEntry> */
    private array $entries = [];
    private string $lastStatusRaw = "\0unset";
    private ?string $poll = null;
    /** The entry, parsed document and side ('worktree'|'cached'|'untracked') shown in the diff pane. */
    private ?ChangeEntry $shownEntry = null;
    private ?DiffDocument $shownDocument = null;
    private string $shownSide = 'worktree';
    /** 'changes' | 'history' — which tab owns the left pane. */
    private string $mode = 'changes';
    /** Diff pane display toggle (persisted): drop whitespace-only changes (-w). */
    private bool $hideWhitespace = false;
    /** The commit + path shown in the history diff, for whitespace-toggle reloads. */
    private ?LogEntry $shownCommit = null;
    private ?string $shownCommitPath = null;

    /** Diff context cycle (`c` in the diff pane): git's default → ±10 → the whole file. */
    private const array CONTEXT_STEPS = [null, 10, 999_999];
    private const array CONTEXT_LABELS = ['default', '±10 lines', 'whole file'];

    private int $contextStep = 0;

    /** Runs the index-mutating verbs (stage/unstage/discard/commit) for this view. */
    private readonly GitStagingService $staging;

    public function __construct(
        private readonly GitInfo $git,
        private readonly string $rootDir,
        private readonly GitPanes $panes,
        private readonly ContainerWidget $main,
        private readonly ContainerWidget $gitArea,
        private readonly ContainerWidget $gitLeft,
        private readonly MenuBarWidget $menuBar,
        private readonly Tui $tui,
        private readonly Notifier $notifier,
        private readonly Translator $translator,
        private readonly EditorAreaPanes $editArea,
        private readonly EditorArea $editorArea,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly LayoutController $layout,
        private readonly Clipboard $clipboard,
        WorkspaceViews $views,
        private readonly ?GitRemoteCommands $remoteCommands = null,
        private readonly int $historyPageSize = 200,
    ) {
        $views->attachGit($this);
        $this->panes->history->onLoadMore($this->loadMoreHistory(...));
        $this->panes->changes->onRemote(fn (string $operation) => $this->remoteCommands?->runOperation($operation));
        $this->panes->history->onRemote(fn (string $operation) => $this->remoteCommands?->runOperation($operation));
        $this->staging = new GitStagingService($this->git, $this->rootDir, $this->panes->commitBox, $this->notifier, $this->translator);

        $exit = $this->close(...);
        $next = $this->focusNext(...);

        $this->panes
            ->changes
            ->onSelect($this->loadDiff(...))
            ->onToggleStage($this->toggleStage(...))
            ->onDiscard($this->discard(...))
            ->onShowHistory(fn () => $this->switchMode('history'))
            ->onFocusNext($next)
            ->onExit($exit);
        $this->panes
            ->history
            ->onSelectCommit($this->loadCommitFiles(...))
            ->onSelectFile($this->loadCommitFileDiff(...))
            ->onShowChanges(fn () => $this->switchMode('changes'))
            ->onFocusNext($next)
            ->onExit($exit);
        $this->panes
            ->diffView
            ->onStageSelection($this->stageSelection(...))
            ->onCycleContext($this->cycleContext(...))
            ->onLayoutToggle(fn (bool $split) => $this->layout->setGitDiffPrefs($split, $this->hideWhitespace))
            ->onToggleWhitespace($this->toggleWhitespace(...))
            ->onCopy($this->copyDiffText(...))
            ->onFocusNext($next)
            ->onExit($exit);
        $this->panes->tabs->onSelect($this->switchMode(...));
        $this->panes
            ->commitBox
            ->setStagedChangesProvider($this->hasStagedChanges(...))
            ->onCommit($this->commit(...))
            ->onAmendToggle($this->amendToggled(...))
            ->onFocusNext($next)
            ->onExit($exit);
    }

    public function isOpen(): bool
    {
        return $this->open;
    }

    /** Where focus should land when a popup (e.g. the app menu) closes over the view. */
    public function focusTarget(): AbstractWidget&FocusableInterface
    {
        return 'history' === $this->mode ? $this->panes->history : $this->panes->changes;
    }

    public function toggle(): void
    {
        $this->open ? $this->close() : $this->open();
    }

    public function open(): void
    {
        if ($this->open) {
            return;
        }
        if (!$this->git->isAvailable()) {
            $this->notifier->flash($this->translator->trans('Not a git repository'));
            $this->tui->requestRender();

            return;
        }

        $this->open = true;
        // The (global, full-width) menu bar stays put; just drop any open
        // dropdown so its tracked hit region cannot go stale across the swap.
        $this->menuBar->close();
        $this->main->clear();
        $this->main->add($this->gitArea);
        if ('changes' !== $this->mode) {
            $this->switchMode('changes'); // the view always opens on Changes
        }
        $this->tui->setFocus($this->panes->changes);
        $this->lastStatusRaw = "\0unset";
        $this->contextStep = 0;
        $prefs = $this->layout->gitDiffPrefs();
        $this->panes->diffView->setSplit($prefs['split']);
        $this->hideWhitespace = $prefs['hideWhitespace'];
        $this->panes->diffView->setWhitespaceHidden($this->hideWhitespace);
        $this->refresh();
        $this->poll = EventLoop::repeat(2.0, fn () => $this->refresh(quiet: true));
        $this->tui->requestRender();
    }

    public function close(): void
    {
        if (!$this->open) {
            return;
        }
        $this->open = false;
        if (null !== $this->poll) {
            EventLoop::cancel($this->poll);
            $this->poll = null;
        }
        $this->menuBar->close();
        $this->main->clear();
        foreach ($this->editArea->orderedEditorPanes() as $widget) {
            $this->main->add($widget);
        }
        $this->editorArea->focus();
    }

    /** Re-read `git status`; $quiet skips the repaint when nothing changed. */
    public function refresh(bool $quiet = false): void
    {
        $this->git->status(function (array $entries, string $raw) use ($quiet): void {
            if (!$this->open || $quiet && $raw === $this->lastStatusRaw) {
                return;
            }
            $this->lastStatusRaw = $raw;
            $this->entries = $entries;
            $this->panes->changes->setEntries($entries);
            $this->panes->commitBox->invalidate(); // the button's enabled state may flip

            if ('history' === $this->mode) {
                return; // the diff pane belongs to the commit browser right now
            }
            $selected = $this->panes->changes->selectedEntry();
            if (null === $selected) {
                $this->panes->diffView->setDocument(null, null, 'Working tree clean');
                $this->tui->requestRender();

                return;
            }
            // Always reload: the same path's diff side can flip between
            // worktree and cached as staging changes; a same-path reload
            // keeps the diff cursor in place.
            $this->loadDiff($selected);
            $this->tui->requestRender();
        });
    }

    /**
     * Load the diff the user can act on: the worktree side while it is dirty,
     * else the staged side; untracked files diff against /dev/null.
     */
    private function loadDiff(ChangeEntry $entry): void
    {
        $abs = $this->rootDir.'/'.$entry->path;
        $show = function (string $raw) use ($entry): void {
            if (!$this->open) {
                return;
            }
            $document = DiffDocument::parse($raw);
            $this->shownEntry = $entry;
            $this->shownDocument = $document->isEmpty() ? null : $document;
            $this->panes->diffView->setDocument($this->shownDocument, $entry->path, $this->hideWhitespace ? 'No changes (whitespace hidden)' : 'No changes');
            $this->tui->requestRender();
        };

        if ($entry->isUntracked()) {
            $this->shownSide = 'untracked';
            $this->git->untrackedFileDiff($abs, $show);

            return;
        }
        if (' ' !== $entry->worktreeStatus) {
            $this->shownSide = 'worktree';
            $this->git->worktreeFileDiff($abs, $show, self::CONTEXT_STEPS[$this->contextStep], $this->hideWhitespace);

            return;
        }
        $this->shownSide = 'cached';
        $this->git->cachedFileDiff($abs, $show, self::CONTEXT_STEPS[$this->contextStep], $this->hideWhitespace);
    }

    /** ctrl+c in the diff pane: selected plain-code lines onto the clipboard. */
    private function copyDiffText(string $text): void
    {
        $this->clipboard->copy($text);
        $lines = substr_count(rtrim($text, characters: "\n"), needle: "\n") + 1;
        $this->notifier->flash(
            1 === $lines
                ? $this->translator->trans('Copied %count% line', ['%count%' => $lines])
                : $this->translator->trans('Copied %count% lines', ['%count%' => $lines]),
        );
        $this->tui->requestRender();
    }

    /**
     * `w` on the diff toolbar: flip whitespace hiding, persist, and reload
     * whichever diff is shown (the changes-side file or the history patch).
     */
    private function toggleWhitespace(): void
    {
        $this->hideWhitespace = !$this->hideWhitespace;
        $this->panes->diffView->setWhitespaceHidden($this->hideWhitespace);
        $this->layout->setGitDiffPrefs($this->panes->diffView->isSplit(), $this->hideWhitespace);
        $this->notifier->flash($this->translator->trans($this->hideWhitespace ? 'Whitespace changes hidden' : 'Whitespace changes shown'));

        if ('history' === $this->mode) {
            if (null !== $this->shownCommit && null !== $this->shownCommitPath) {
                $this->loadCommitFileDiff($this->shownCommit, $this->shownCommitPath);
            }
            $this->tui->requestRender();

            return;
        }
        if (null !== $this->shownEntry) {
            $this->loadDiff($this->shownEntry);
        }
        $this->tui->requestRender();
    }

    /** `c` in the diff pane: reload with the next context size in the cycle. */
    private function cycleContext(): void
    {
        if (null === $this->shownEntry) {
            return;
        }
        if ('untracked' === $this->shownSide) {
            $this->notifier->flash($this->translator->trans('Untracked files always show the whole file'));
            $this->tui->requestRender();

            return;
        }
        $this->contextStep = ($this->contextStep + 1) % \count(self::CONTEXT_STEPS);
        $this->notifier->flash($this->translator->trans('Diff context: %label%', ['%label%' => $this->translator->trans(self::CONTEXT_LABELS[$this->contextStep])]));
        $this->loadDiff($this->shownEntry);
        $this->tui->requestRender();
    }

    /**
     * Line/hunk staging from the diff pane, delegated to the staging service
     * once the currently-shown diff is known; the callback refreshes and
     * reloads the same path so the diff view keeps its cursor.
     *
     * @param array<int, array<int, true>> $selected hunk index => selected line set
     */
    private function stageSelection(array $selected): void
    {
        if (null === $this->shownEntry || null === $this->shownDocument) {
            return;
        }
        $entry = $this->shownEntry;
        $this->staging->stageSelection($entry, $this->shownDocument, $this->shownSide, $this->hideWhitespace, $selected, function () use ($entry): void {
            $this->refresh();
            $this->loadDiff($entry); // same path: the diff view keeps its cursor
        });
    }

    private function toggleStage(ChangeEntry $entry): void
    {
        $this->staging->toggleStage($entry, $this->refresh(...));
    }

    private function discard(ChangeEntry $entry): void
    {
        $this->staging->discard($entry, $this->refresh(...));
    }

    /** Amend armed: prefill the last commit's message; disarmed: start fresh. */
    private function amendToggled(bool $amend): void
    {
        if (!$amend) {
            $this->panes->commitBox->setMessage('');
            $this->tui->requestRender();

            return;
        }
        $this->git->lastCommitMessage(function (string $message): void {
            if ($this->open && $this->panes->commitBox->isAmend()) {
                $this->panes->commitBox->setMessage($message);
            }
            $this->tui->requestRender();
        });
    }

    private function commit(string $message, bool $amend = false): void
    {
        $this->staging->commit($message, $amend, fn () => $this->dispatcher->dispatch(new GitCommitted()), $this->refresh(...));
    }

    /** Swap the left pane between the Changes and History tabs. */
    private function switchMode(string $mode): void
    {
        if ($mode === $this->mode) {
            return;
        }
        $this->mode = $mode;
        $this->panes->tabs->setActive($mode);
        $this->gitLeft->clear();
        $this->gitLeft->add($this->panes->tabs);
        if ('history' === $mode) {
            $this->gitLeft->add($this->panes->history);
            $this->panes->diffView->setReadOnly(true);
            $this->panes->diffView->setDocument(null, null, 'Select a commit file');
            $this->tui->setFocus($this->panes->history);
            $this->reloadHistory(selectFirst: true);
            $this->tui->requestRender();

            return;
        }
        // Tabbing away drops any file filter — History reopens repo-wide.
        $this->historyPath = null;
        $this->panes->history->setFilterLabel(null);
        $this->gitLeft->add($this->panes->changes);
        $this->gitLeft->add($this->panes->commitBox);
        $this->panes->diffView->setReadOnly(false);
        $this->tui->setFocus($this->panes->changes);
        $this->refresh();
        $this->tui->requestRender();
    }

    /** The repo-relative file the History tab is filtered to, or null for repo-wide. */
    private ?string $historyPath = null;

    /** Reload history page 0 (honoring the file filter), optionally loading the first commit's files. */
    private function reloadHistory(bool $selectFirst): void
    {
        $this->git->log(
            $this->historyPageSize,
            function (array $commits) use ($selectFirst): void {
                if (!$this->open || 'history' !== $this->mode) {
                    return;
                }
                $this->panes->history->setCommits($commits);
                $this->panes->history->setHasMore(\count($commits) === $this->historyPageSize);
                if ($selectFirst && null !== ($first = $this->panes->history->selectedCommit())) {
                    $this->loadCommitFiles($first);
                }
                $this->tui->requestRender();
            },
            path: $this->historyPath,
        );
    }

    /**
     * Open the git view on the History tab filtered to one file — the
     * "commits that touched this file" view. The filter clears when the
     * user tabs back to Changes.
     */
    public function openFileHistory(string $absPath): void
    {
        $this->open();
        if (!$this->open) {
            return; // not a repository — open() already flashed
        }
        $this->historyPath = ProjectPaths::relative($this->rootDir, $absPath);
        $this->panes->history->setFilterLabel($this->historyPath);
        if ('history' === $this->mode) {
            $this->reloadHistory(selectFirst: true);

            return;
        }
        $this->switchMode('history');
    }

    /**
     * After a fetch/pull/push: the history tab shows stale commits — reload
     * page 0 (the changes list self-heals via its poll).
     */
    #[AsEventListener]
    public function onRemoteCompleted(GitRemoteCompleted $event): void
    {
        if ($this->open && 'history' === $this->mode) {
            $this->reloadHistory(selectFirst: false);
        }
    }

    /** Pull the next page of history under the loaded commits. */
    private function loadMoreHistory(): void
    {
        $this->git->log(
            $this->historyPageSize,
            function (array $commits): void {
                if (!$this->open || 'history' !== $this->mode) {
                    return;
                }
                $this->panes->history->appendCommits($commits);
                $this->panes->history->setHasMore(\count($commits) === $this->historyPageSize);
                $this->tui->requestRender();
            },
            skip: $this->panes->history->commitCount(),
            path: $this->historyPath,
        );
    }

    private function loadCommitFiles(LogEntry $commit): void
    {
        $this->git->commitFiles($commit->sha, function (array $files) use ($commit): void {
            if (!$this->open || 'history' !== $this->mode || $commit->sha !== $this->panes->history->selectedCommit()?->sha) {
                return;
            }
            $this->panes->history->setFiles($files);
            if ([] === $files) {
                $this->panes->diffView->setDocument(null, null, 'No files in this commit');
                $this->tui->requestRender();

                return;
            }
            // The first file is selected by default — show its patch right away.
            $this->loadCommitFileDiff($commit, $files[0]['path']);
            $this->tui->requestRender();
        });
    }

    private function loadCommitFileDiff(LogEntry $commit, string $path): void
    {
        $this->shownCommit = $commit;
        $this->shownCommitPath = $path;
        $this->git->commitFileDiff(
            $commit->sha,
            $path,
            function (string $raw) use ($path): void {
                if (!$this->open || 'history' !== $this->mode) {
                    return;
                }
                $document = DiffDocument::parse($raw);
                $this->panes->diffView->setDocument($document->isEmpty() ? null : $document, $path, $this->hideWhitespace ? 'No patch (whitespace hidden)' : 'No patch for this file');
                $this->tui->requestRender();
            },
            $this->hideWhitespace,
        );
    }

    private function hasStagedChanges(): bool
    {
        foreach ($this->entries as $entry) {
            if ('none' !== $entry->stagedState()) {
                return true;
            }
        }

        return false;
    }

    /** Tab order: changes list → diff → commit box → changes list (history: list ↔ diff). */
    public function focusNext(): void
    {
        $this->cycleFocus(1);
    }

    /** The reverse walk of {@see focusNext} (Ctrl+F6). */
    public function focusPrevious(): void
    {
        $this->cycleFocus(-1);
    }

    private function cycleFocus(int $direction): void
    {
        /** @var list<FocusableInterface&AbstractWidget> $order */
        $order = 'history' === $this->mode
            ? [$this->panes->history, $this->panes->diffView]
            : [$this->panes->changes, $this->panes->diffView, $this->panes->commitBox];
        $count = \count($order);
        $index = array_search($this->tui->getFocus(), $order, strict: true);
        $target = $order[false === $index ? 0 : ($index + $direction + $count) % $count];
        if ($target === $this->panes->commitBox) {
            $this->panes->commitBox->resetField();
        }
        $this->tui->setFocus($target);
        $this->tui->requestRender();
    }
}
