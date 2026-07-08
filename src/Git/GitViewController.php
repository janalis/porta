<?php

declare(strict_types=1);

namespace Porta\Git;

use Porta\I18n\Translator;
use Porta\Ui\Notifier;
use Porta\Widget\Git\ChangesListWidget;
use Porta\Widget\Git\CommitBoxWidget;
use Porta\Widget\Git\DiffViewWidget;
use Porta\Widget\Git\GitTabsWidget;
use Porta\Widget\Git\HistoryListWidget;
use Porta\Widget\MenuBarWidget;
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

    /**
     * @param \Closure(): list<AbstractWidget> $editArea the main container's normal children (tree, separator, editor pane), in the current writing direction's order
     * @param \Closure(): void     $onClosed restore focus/render after the swap back
     * @param \Closure(): void     $onCommitted refresh gutter marks and branch after a commit
     * @param \Closure(): array{split: bool, hideWhitespace: bool} $loadDiffPrefs persisted display toggles
     * @param \Closure(bool, bool): void                           $saveDiffPrefs persist (split, hideWhitespace)
     * @param \Closure(string): void                               $copyToClipboard OSC 52 copy
     */
    public function __construct(
        private readonly GitInfo $git,
        private readonly string $rootDir,
        private readonly ChangesListWidget $changes,
        private readonly DiffViewWidget $diffView,
        private readonly CommitBoxWidget $commitBox,
        private readonly HistoryListWidget $history,
        private readonly GitTabsWidget $tabs,
        private readonly ContainerWidget $main,
        private readonly ContainerWidget $gitArea,
        private readonly ContainerWidget $gitLeft,
        private readonly MenuBarWidget $menuBar,
        private readonly \Closure $editArea,
        private readonly Tui $tui,
        private readonly Notifier $notifier,
        private readonly Translator $translator,
        private readonly \Closure $onClosed,
        private readonly \Closure $onCommitted,
        private readonly \Closure $loadDiffPrefs,
        private readonly \Closure $saveDiffPrefs,
        private readonly \Closure $copyToClipboard,
    ) {
        $exit = fn () => $this->close();
        $next = fn () => $this->focusNext();

        $this->changes
            ->onSelect(fn (ChangeEntry $entry) => $this->loadDiff($entry))
            ->onToggleStage(fn (ChangeEntry $entry) => $this->toggleStage($entry))
            ->onDiscard(fn (ChangeEntry $entry) => $this->discard($entry))
            ->onShowHistory(fn () => $this->switchMode('history'))
            ->onFocusNext($next)
            ->onExit($exit);
        $this->history
            ->onSelectCommit(fn (LogEntry $commit) => $this->loadCommitFiles($commit))
            ->onSelectFile(fn (LogEntry $commit, string $path) => $this->loadCommitFileDiff($commit, $path))
            ->onShowChanges(fn () => $this->switchMode('changes'))
            ->onFocusNext($next)
            ->onExit($exit);
        $this->diffView
            ->onStageSelection(fn (array $selected) => $this->stageSelection($selected))
            ->onCycleContext(fn () => $this->cycleContext())
            ->onLayoutToggle(fn (bool $split) => ($this->saveDiffPrefs)($split, $this->hideWhitespace))
            ->onToggleWhitespace(fn () => $this->toggleWhitespace())
            ->onCopy(fn (string $text) => $this->copyDiffText($text))
            ->onFocusNext($next)
            ->onExit($exit);
        $this->tabs->onSelect(fn (string $mode) => $this->switchMode($mode));
        $this->commitBox
            ->hasStagedChanges(fn (): bool => $this->hasStagedChanges())
            ->onCommit(fn (string $message, bool $amend) => $this->commit($message, $amend))
            ->onAmendToggle(fn (bool $amend) => $this->amendToggled($amend))
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
        return 'history' === $this->mode ? $this->history : $this->changes;
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
        $this->tui->setFocus($this->changes);
        $this->lastStatusRaw = "\0unset";
        $this->contextStep = 0;
        $prefs = ($this->loadDiffPrefs)();
        $this->diffView->setSplit($prefs['split']);
        $this->hideWhitespace = $prefs['hideWhitespace'];
        $this->diffView->setWhitespaceHidden($this->hideWhitespace);
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
        foreach (($this->editArea)() as $widget) {
            $this->main->add($widget);
        }
        ($this->onClosed)();
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
            $this->changes->setEntries($entries);
            $this->commitBox->invalidate(); // the button's enabled state may flip

            if ('history' === $this->mode) {
                return; // the diff pane belongs to the commit browser right now
            }
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
            $this->diffView->setDocument($this->shownDocument, $entry->path, $this->hideWhitespace ? 'No changes (whitespace hidden)' : 'No changes');
            $this->tui->requestRender();
        };

        if ($entry->isUntracked()) {
            $this->shownSide = 'untracked';
            $this->git->untrackedFileDiff($abs, $show);
        } elseif (' ' !== $entry->worktreeStatus) {
            $this->shownSide = 'worktree';
            $this->git->worktreeFileDiff($abs, $show, self::CONTEXT_STEPS[$this->contextStep], $this->hideWhitespace);
        } else {
            $this->shownSide = 'cached';
            $this->git->cachedFileDiff($abs, $show, self::CONTEXT_STEPS[$this->contextStep], $this->hideWhitespace);
        }
    }

    /** ctrl+c in the diff pane: selected plain-code lines onto the clipboard. */
    private function copyDiffText(string $text): void
    {
        ($this->copyToClipboard)($text);
        $lines = substr_count(rtrim($text, "\n"), "\n") + 1;
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
        $this->diffView->setWhitespaceHidden($this->hideWhitespace);
        ($this->saveDiffPrefs)($this->diffView->isSplit(), $this->hideWhitespace);
        $this->notifier->flash($this->translator->trans($this->hideWhitespace ? 'Whitespace changes hidden' : 'Whitespace changes shown'));

        if ('history' === $this->mode) {
            if (null !== $this->shownCommit && null !== $this->shownCommitPath) {
                $this->loadCommitFileDiff($this->shownCommit, $this->shownCommitPath);
            }
        } elseif (null !== $this->shownEntry) {
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
            $this->notifier->flash($this->translator->trans('Untracked: stage the whole file with Space in the changes list'));
            $this->tui->requestRender();

            return;
        }
        if ($this->hideWhitespace) {
            // A -w diff's hunks omit whitespace-only lines, so a patch built
            // from them no longer matches the preimage — git apply would balk.
            $this->notifier->flash($this->translator->trans('Show whitespace changes to stage lines (w)'));
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
                $this->notifier->flash($this->translator->trans('git apply refused the partial patch'));
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
                $this->notifier->flash($this->translator->trans('git refused the staging change'));
            }
            $this->refresh();
        };

        'full' === $entry->stagedState() ? $this->git->unstageFile($abs, $done) : $this->git->stage($abs, $done);
    }

    private function discard(ChangeEntry $entry): void
    {
        if ($entry->isUntracked()) {
            @unlink($this->rootDir.'/'.$entry->path);
            $this->notifier->flash($this->translator->trans('Deleted %path%', ['%path%' => $entry->path]));
            $this->refresh();

            return;
        }
        $this->git->discardFile($this->rootDir.'/'.$entry->path, function (bool $ok) use ($entry): void {
            $this->notifier->flash(
                $ok
                    ? $this->translator->trans('Discarded changes to %path%', ['%path%' => $entry->path])
                    : $this->translator->trans('Discard failed'),
            );
            $this->refresh();
        });
    }

    /** Amend armed: prefill the last commit's message; disarmed: start fresh. */
    private function amendToggled(bool $amend): void
    {
        if (!$amend) {
            $this->commitBox->setMessage('');
            $this->tui->requestRender();

            return;
        }
        $this->git->lastCommitMessage(function (string $message): void {
            if ($this->open && $this->commitBox->isAmend()) {
                $this->commitBox->setMessage($message);
            }
            $this->tui->requestRender();
        });
    }

    private function commit(string $message, bool $amend = false): void
    {
        $this->git->commit(
            $message,
            function (bool $ok, string $out): void {
                $firstLine = strtok($out, "\n");
                $summary = false === $firstLine ? '' : $firstLine;
                if ($ok) {
                    $this->commitBox->clear();
                    $this->notifier->flash($this->translator->trans('Committed: %summary%', ['%summary%' => $summary]));
                    ($this->onCommitted)();
                } else {
                    $this->notifier->flash($this->translator->trans('Commit failed: %summary%', ['%summary%' => $summary]));
                }
                $this->refresh();
            },
            $amend,
        );
    }

    /** Swap the left pane between the Changes and History tabs. */
    private function switchMode(string $mode): void
    {
        if ($mode === $this->mode) {
            return;
        }
        $this->mode = $mode;
        $this->tabs->setActive($mode);
        $this->gitLeft->clear();
        $this->gitLeft->add($this->tabs);
        if ('history' === $mode) {
            $this->gitLeft->add($this->history);
            $this->diffView->setReadOnly(true);
            $this->diffView->setDocument(null, null, 'Select a commit file');
            $this->tui->setFocus($this->history);
            $this->git->log(200, function (array $commits): void {
                if (!$this->open || 'history' !== $this->mode) {
                    return;
                }
                $this->history->setCommits($commits);
                if (null !== ($first = $this->history->selectedCommit())) {
                    $this->loadCommitFiles($first);
                }
                $this->tui->requestRender();
            });
        } else {
            $this->gitLeft->add($this->changes);
            $this->gitLeft->add($this->commitBox);
            $this->diffView->setReadOnly(false);
            $this->tui->setFocus($this->changes);
            $this->refresh();
        }
        $this->tui->requestRender();
    }

    private function loadCommitFiles(LogEntry $commit): void
    {
        $this->git->commitFiles($commit->sha, function (array $files) use ($commit): void {
            if (!$this->open || 'history' !== $this->mode || $commit->sha !== $this->history->selectedCommit()?->sha) {
                return;
            }
            $this->history->setFiles($files);
            // The first file is selected by default — show its patch right away.
            if ([] !== $files) {
                $this->loadCommitFileDiff($commit, $files[0]['path']);
            } else {
                $this->diffView->setDocument(null, null, 'No files in this commit');
            }
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
                $this->diffView->setDocument($document->isEmpty() ? null : $document, $path, $this->hideWhitespace ? 'No patch (whitespace hidden)' : 'No patch for this file');
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
    private function focusNext(): void
    {
        /** @var list<FocusableInterface&AbstractWidget> $order */
        $order = 'history' === $this->mode
            ? [$this->history, $this->diffView]
            : [$this->changes, $this->diffView, $this->commitBox];
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
