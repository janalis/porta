<?php

declare(strict_types=1);

namespace Porta\Git;

use Porta\I18n\Translator;
use Porta\Ui\Notifier;
use Porta\Widget\Git\CommitBoxWidget;

/**
 * The index-mutating half of the git view: stage/unstage a file, stage a
 * line/hunk selection from the diff, discard a file's changes, and commit.
 * Each verb runs the git plumbing, flashes the outcome, and calls back so the
 * {@see GitViewController} can refresh the status and reload whatever diff is
 * shown. Split out of the controller so it owns "change the repo" while the
 * controller owns "swap panes, move focus, display diffs".
 */
final class GitStagingService
{
    public function __construct(
        private readonly GitInfo $git,
        private readonly string $rootDir,
        private readonly CommitBoxWidget $commitBox,
        private readonly Notifier $notifier,
        private readonly Translator $translator,
    ) {}

    /**
     * Stage (or unstage) a line/hunk selection from the diff pane: a worktree
     * diff stages the selection into the index; a cached diff unstages it (the
     * patch is built from the inverted hunks, same recount path). Untracked
     * files have no partial form — the whole file stages from the list.
     *
     * @param array<int, array<int, true>> $selected  hunk index => selected line set
     * @param \Closure(): void              $onApplied refresh + reload the shown diff
     */
    public function stageSelection(ChangeEntry $entry, DiffDocument $document, string $side, bool $hideWhitespace, array $selected, \Closure $onApplied): void
    {
        if ('untracked' === $side) {
            $this->notifier->flash($this->translator->trans('Untracked: stage the whole file with Space in the changes list'));

            return;
        }
        if ($hideWhitespace) {
            // A -w diff's hunks omit whitespace-only lines, so a patch built
            // from them no longer matches the preimage — git apply would balk.
            $this->notifier->flash($this->translator->trans('Show whitespace changes to stage lines (w)'));

            return;
        }

        $hunks = 'cached' === $side
            ? array_map(PatchBuilder::invertHunk(...), $document->hunks)
            : $document->hunks;
        $patch = PatchBuilder::build($entry->path, $hunks, $selected);
        if (null === $patch) {
            return; // only context rows selected
        }

        $this->git->applyCached($patch, function (bool $ok) use ($onApplied): void {
            if (!$ok) {
                $this->notifier->flash($this->translator->trans('git apply refused the partial patch'));
            }
            $onApplied();
        });
    }

    /**
     * Stage a whole file, or unstage it when it is already fully staged.
     *
     * @param \Closure(): void $onChanged re-read status after the index moves
     */
    public function toggleStage(ChangeEntry $entry, \Closure $onChanged): void
    {
        $abs = $this->rootDir.'/'.$entry->path;
        $done = function (bool $ok) use ($onChanged): void {
            if (!$ok) {
                $this->notifier->flash($this->translator->trans('git refused the staging change'));
            }
            $onChanged();
        };

        'full' === $entry->stagedState() ? $this->git->unstageFile($abs, $done) : $this->git->stage($abs, $done);
    }

    /**
     * Discard a file's working-tree changes; an untracked file is deleted.
     *
     * @param \Closure(): void $onChanged re-read status after the file reverts
     */
    public function discard(ChangeEntry $entry, \Closure $onChanged): void
    {
        if ($entry->isUntracked()) {
            @unlink($this->rootDir.'/'.$entry->path);
            $this->notifier->flash($this->translator->trans('Deleted %path%', ['%path%' => $entry->path]));
            $onChanged();

            return;
        }
        $this->git->discardFile($this->rootDir.'/'.$entry->path, function (bool $ok) use ($entry, $onChanged): void {
            $this->notifier->flash(
                $ok
                    ? $this->translator->trans('Discarded changes to %path%', ['%path%' => $entry->path])
                    : $this->translator->trans('Discard failed'),
            );
            $onChanged();
        });
    }

    /**
     * Commit the staged changes with $message, amending HEAD when $amend.
     *
     * @param \Closure(): void $onCommitted refresh gutter marks + branch on success
     * @param \Closure(): void $onChanged   re-read status after the commit
     */
    public function commit(string $message, bool $amend, \Closure $onCommitted, \Closure $onChanged): void
    {
        $this->git->commit(
            $message,
            function (bool $ok, string $out) use ($onCommitted, $onChanged): void {
                $firstLine = strtok($out, "\n");
                $summary = false === $firstLine ? '' : $firstLine;
                if ($ok) {
                    $this->commitBox->clear();
                    $this->notifier->flash($this->translator->trans('Committed: %summary%', ['%summary%' => $summary]));
                    $onCommitted();
                } else {
                    $this->notifier->flash($this->translator->trans('Commit failed: %summary%', ['%summary%' => $summary]));
                }
                $onChanged();
            },
            $amend,
        );
    }
}
