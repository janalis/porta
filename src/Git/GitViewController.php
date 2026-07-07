<?php

declare(strict_types=1);

namespace Porta\Git;

use Porta\Widget\Git\ChangesListWidget;
use Porta\Widget\Git\CommitBoxWidget;
use Porta\Widget\Git\DiffViewWidget;
use Porta\Widget\StatusBarWidget;
use Revolt\EventLoop;
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

    /**
     * @param list<AbstractWidget> $editArea the main container's normal children (tree, separator, editor pane)
     * @param \Closure(): void     $onClosed restore focus/render after the swap back
     * @param \Closure(): void     $onCommitted refresh gutter marks and branch after a commit
     */
    public function __construct(
        private readonly GitInfo $git,
        private readonly string $rootDir,
        private readonly ChangesListWidget $changes,
        private readonly DiffViewWidget $diffView,
        private readonly CommitBoxWidget $commitBox,
        private readonly ContainerWidget $main,
        private readonly ContainerWidget $gitArea,
        private readonly array $editArea,
        private readonly Tui $tui,
        private readonly StatusBarWidget $statusBar,
        private readonly \Closure $onClosed,
        private readonly \Closure $onCommitted,
    ) {
        $exit = fn () => $this->close();
        $next = fn () => $this->focusNext();

        $this->changes
            ->onSelect(fn (ChangeEntry $entry) => $this->loadDiff($entry))
            ->onToggleStage(fn (ChangeEntry $entry) => $this->toggleStage($entry))
            ->onDiscard(fn (ChangeEntry $entry) => $this->discard($entry))
            ->onFocusNext($next)
            ->onExit($exit);
        $this->diffView
            ->onStageSelection(fn (array $selected) => $this->stageSelection($selected))
            ->onFocusNext($next)
            ->onExit($exit);
        $this->commitBox
            ->hasStagedChanges(fn (): bool => $this->hasStagedChanges())
            ->onCommit(fn (string $message) => $this->commit($message))
            ->onFocusNext($next)
            ->onExit($exit);
    }

    public function isOpen(): bool
    {
        return $this->open;
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
            $this->statusBar->flash('Not a git repository');
            $this->tui->requestRender();

            return;
        }

        $this->open = true;
        $this->main->clear();
        $this->main->add($this->gitArea);
        $this->tui->setFocus($this->changes);
        $this->lastStatusRaw = "\0unset";
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
        $this->main->clear();
        foreach ($this->editArea as $widget) {
            $this->main->add($widget);
        }
        ($this->onClosed)();
    }

    /** Re-read `git status`; $quiet skips the repaint when nothing changed. */
    public function refresh(bool $quiet = false): void
    {
        $this->git->status(function (array $entries, string $raw) use ($quiet): void {
            if (!$this->open || ($quiet && $raw === $this->lastStatusRaw)) {
                return;
            }
            $this->lastStatusRaw = $raw;
            $this->entries = $entries;
            $this->changes->setEntries($entries);
            $this->commitBox->invalidate(); // the button's enabled state may flip

            $selected = $this->changes->selectedEntry();
            if (null === $selected) {
                $this->diffView->setDocument(null, null, 'Working tree clean');
            } else {
                // Always reload: the same path's diff side can flip between
                // worktree and cached as staging changes; a same-path reload
                // keeps the diff cursor in place.
                $this->loadDiff($selected);
            }
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
            $this->diffView->setDocument($this->shownDocument, $entry->path, 'No changes');
            $this->tui->requestRender();
        };

        if ($entry->isUntracked()) {
            $this->shownSide = 'untracked';
            $this->git->untrackedFileDiff($abs, $show);
        } elseif (' ' !== $entry->worktreeStatus) {
            $this->shownSide = 'worktree';
            $this->git->worktreeFileDiff($abs, $show);
        } else {
            $this->shownSide = 'cached';
            $this->git->cachedFileDiff($abs, $show);
        }
    }

    /**
     * Line/hunk staging from the diff pane: a worktree diff stages the
     * selection into the index; a cached diff unstages it (the patch is built
     * from the inverted hunks, same recount path). Untracked files have no
     * partial form — the whole file stages from the list.
     *
     * @param array<int, array<int, true>> $selected hunk index => selected line set
     */
    private function stageSelection(array $selected): void
    {
        if (null === $this->shownEntry || null === $this->shownDocument) {
            return;
        }
        if ('untracked' === $this->shownSide) {
            $this->statusBar->flash('Untracked: stage the whole file with Space in the changes list');
            $this->tui->requestRender();

            return;
        }

        $hunks = 'cached' === $this->shownSide
            ? array_map(PatchBuilder::invertHunk(...), $this->shownDocument->hunks)
            : $this->shownDocument->hunks;
        $patch = PatchBuilder::build($this->shownEntry->path, $hunks, $selected);
        if (null === $patch) {
            return; // only context rows selected
        }

        $entry = $this->shownEntry;
        $this->git->applyCached($patch, function (bool $ok) use ($entry): void {
            if (!$ok) {
                $this->statusBar->flash('git apply refused the partial patch');
            }
            $this->refresh();
            $this->loadDiff($entry); // same path: the diff view keeps its cursor
        });
    }

    private function toggleStage(ChangeEntry $entry): void
    {
        $abs = $this->rootDir.'/'.$entry->path;
        $done = function (bool $ok): void {
            if (!$ok) {
                $this->statusBar->flash('git refused the staging change');
            }
            $this->refresh();
        };

        'full' === $entry->stagedState() ? $this->git->unstageFile($abs, $done) : $this->git->stage($abs, $done);
    }

    private function discard(ChangeEntry $entry): void
    {
        if ($entry->isUntracked()) {
            @unlink($this->rootDir.'/'.$entry->path);
            $this->statusBar->flash('Deleted '.$entry->path);
            $this->refresh();

            return;
        }
        $this->git->discardFile($this->rootDir.'/'.$entry->path, function (bool $ok) use ($entry): void {
            $this->statusBar->flash($ok ? 'Discarded changes to '.$entry->path : 'Discard failed');
            $this->refresh();
        });
    }

    private function commit(string $message): void
    {
        $this->git->commit($message, function (bool $ok, string $out): void {
            $firstLine = strtok($out, "\n");
            $summary = false === $firstLine ? '' : $firstLine;
            if ($ok) {
                $this->commitBox->clear();
                $this->statusBar->flash('Committed: '.$summary);
                ($this->onCommitted)();
            } else {
                $this->statusBar->flash('Commit failed: '.$summary);
            }
            $this->refresh();
        });
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

    /** Tab order: changes list → diff → commit box → changes list. */
    private function focusNext(): void
    {
        /** @var list<FocusableInterface&AbstractWidget> $order */
        $order = [$this->changes, $this->diffView, $this->commitBox];
        $current = $this->tui->getFocus();
        $index = array_search($current, $order, true);
        $target = $order[(false === $index ? 0 : $index + 1) % \count($order)];
        if ($target === $this->commitBox) {
            $this->commitBox->resetField();
        }
        $this->tui->setFocus($target);
        $this->tui->requestRender();
    }
}
