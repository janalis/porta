<?php

declare(strict_types=1);

namespace Porta\Git;

use Porta\I18n\Translator;
use Porta\Support\Clipboard;
use Porta\Support\Outcome;
use Porta\Support\ProjectPaths;
use Porta\Ui\Glyphs;
use Porta\Widget\CodeEditorWidget;
use Porta\Workbench\Notifier;
use Porta\Workbench\WorkspaceViews;
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
        private readonly WorkspaceViews $views,
        private readonly Clipboard $clipboard,
    ) {}

    public function stageCurrent(): void
    {
        $path = $this->editor->getFilePath();
        if (null === $path || !$this->git->isAvailable()) {
            $this->unavailable();

            return;
        }
        $this->git->stage($path, function (Outcome $ok) use ($path): void {
            $this->notifier->flash(
                $ok->isOk()
                    ? $this->translator->trans('Staged %path%', ['%path%' => ProjectPaths::relative($this->rootDir, $path)])
                    : $this->translator->trans('Stage failed'),
            );
            $this->refresher->refreshActiveMarks();
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
            $this->git->commit($message, function (Outcome $ok) use ($message): void {
                $this->notifier->flash(
                    $ok->isOk()
                        ? $this->translator->trans('Committed: %summary%', ['%summary%' => $message])
                        : $this->translator->trans('Commit failed (nothing staged?)'),
                );
                $this->refresher->refreshBranch();
                $this->refresher->refreshActiveMarks();
            });
        });
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
            },
        );
    }

    /** Stash the working tree; git's own stdout line is the flash (covers the clean-tree case). */
    public function stash(): void
    {
        if (!$this->git->isAvailable()) {
            $this->unavailable();

            return;
        }
        $this->git->stashPush(function (Outcome $ok, string $line): void {
            $this->notifier->flash($ok->isOk() ? $line : $this->translator->trans('Stash failed'), $ok->isOk() ? 3.0 : 6.0);
            if ($ok->isOk()) {
                $this->refresher->refreshActiveMarks();
            }
            $this->tui->requestRender();
        });
    }

    /** Pop the newest stash back onto the working tree. */
    public function stashPop(): void
    {
        if (!$this->git->isAvailable()) {
            $this->unavailable();

            return;
        }
        $this->git->stashPop(null, fn (Outcome $ok, string $error) => $this->finishSwitch($ok, $this->stashError($error), $this->translator->trans('Stash popped')));
    }

    /** The stash picker: pick a stash, then apply, pop or drop it. */
    public function stashes(): void
    {
        if (!$this->git->isAvailable()) {
            $this->unavailable();

            return;
        }
        $this->git->stashList(function (array $stashes): void {
            if ([] === $stashes) {
                $this->notifier->flash($this->translator->trans('No stashes'));

                return;
            }
            $this->views->closeAll();
            $this->tui->setFocus($this->editor);
            $items = array_map(static fn (array $stash): array => [
                'label' => '  '.$stash['subject'],
                'hint' => date('Y-m-d H:i', $stash['timestamp']),
                'value' => $stash['ref'],
            ], $stashes);
            $this->editor->openListOverlay($this->translator->trans('Stashes'), $items, function (string $ref): void {
                $this->editor->openListOverlay(
                    $this->translator->trans('%stash%: apply, pop or drop?', ['%stash%' => $ref]),
                    [
                        ['label' => $this->translator->trans('Apply'), 'hint' => '', 'value' => 'apply'],
                        ['label' => $this->translator->trans('Pop'), 'hint' => '', 'value' => 'pop'],
                        ['label' => $this->translator->trans('Drop'), 'hint' => '', 'value' => 'drop'],
                        ['label' => $this->translator->trans('Cancel'), 'hint' => '', 'value' => 'cancel'],
                    ],
                    function (string $action) use ($ref): void {
                        match ($action) {
                            'apply' => $this->git->stashApply($ref, fn (Outcome $ok, string $error) => $this->finishSwitch(
                                $ok,
                                $this->stashError($error),
                                $this->translator->trans('Applied %stash%', ['%stash%' => $ref]),
                            )),
                            'pop' => $this->git->stashPop($ref, fn (Outcome $ok, string $error) => $this->finishSwitch(
                                $ok,
                                $this->stashError($error),
                                $this->translator->trans('Popped %stash%', ['%stash%' => $ref]),
                            )),
                            // Drop never touches the worktree — no marks refresh.
                            'drop' => $this->git->stashDrop($ref, function (Outcome $ok, string $error) use ($ref): void {
                                $this->notifier->flash($ok->isOk() ? $this->translator->trans('Dropped %stash%', ['%stash%' => $ref]) : $error, $ok->isOk() ? 3.0 : 6.0);
                            }),
                            default => null,
                        };
                    },
                );
            });
        });
    }

    /** Open the git view's History tab filtered to the current file. */
    public function fileHistory(): void
    {
        $path = $this->editor->getFilePath();
        if (null === $path || !$this->git->isAvailable()) {
            $this->unavailable();

            return;
        }
        $this->views->openGitFileHistory($path);
    }

    /** Undo the last commit (soft reset), keeping its changes staged. */
    public function undoLastCommit(): void
    {
        if (!$this->git->isAvailable()) {
            $this->unavailable();

            return;
        }
        $this->git->undoLastCommit(function (Outcome $ok): void {
            $this->notifier->flash(
                $ok->isOk()
                    ? $this->translator->trans('Last commit undone (changes kept staged)')
                    : $this->translator->trans('Nothing to undo'),
            );
            $this->refresher->refreshBranch();
            $this->refresher->refreshActiveMarks();
        });
    }

    /** Copy a commit's full hash to the clipboard (the history context menu). */
    public function copyCommitHash(LogEntry $commit): void
    {
        $this->clipboard->copy($commit->sha);
        $this->notifier->flash($this->translator->trans('Copied %sha%', ['%sha%' => $commit->shortSha]));
    }

    /** Prompt for a name, then branch off $commit and switch to it. */
    public function branchFromCommit(LogEntry $commit): void
    {
        if (!$this->guardAvailable()) {
            return;
        }
        $this->editor->openPrompt($this->translator->trans('New branch name'), '', function (string $name) use ($commit): void {
            $name = trim($name);
            if ('' === $name) {
                return;
            }
            $this->git->branchFromCommit($name, $commit->sha, fn (Outcome $ok, string $error) => $this->finishHistoryOp($ok, $error, $this->translator->trans('Created and switched to %branch%', [
                '%branch%' => $name,
            ])));
        });
        $this->tui->requestRender();
    }

    /** Cherry-pick $commit onto the current branch (a conflict flashes git's message). */
    public function cherryPick(LogEntry $commit): void
    {
        if (!$this->guardAvailable()) {
            return;
        }
        $this->git->cherryPick($commit->sha, fn (Outcome $ok, string $error) => $this->finishHistoryOp($ok, $error, $this->translator->trans('Cherry-picked %sha%', ['%sha%' => $commit->shortSha])));
    }

    /** Revert $commit as a new commit (a conflict flashes git's message). */
    public function revertCommit(LogEntry $commit): void
    {
        if (!$this->guardAvailable()) {
            return;
        }
        $this->git->revertCommit($commit->sha, fn (Outcome $ok, string $error) => $this->finishHistoryOp($ok, $error, $this->translator->trans('Reverted %sha%', ['%sha%' => $commit->shortSha])));
    }

    /** Reset HEAD to $commit, confirming the soft/mixed mode first (never --hard). */
    public function resetToCommit(LogEntry $commit): void
    {
        if (!$this->guardAvailable()) {
            return;
        }
        $this->views->closeAll();
        $this->tui->setFocus($this->editor);
        $this->editor->openListOverlay(
            $this->translator->trans('Reset to %sha%?', ['%sha%' => $commit->shortSha]),
            [
                ['label' => $this->translator->trans('Soft (keep staged changes)'), 'hint' => '', 'value' => 'soft'],
                ['label' => $this->translator->trans('Mixed (unstage changes)'), 'hint' => '', 'value' => 'mixed'],
                ['label' => $this->translator->trans('Cancel'), 'hint' => '', 'value' => 'cancel'],
            ],
            function (string $mode) use ($commit): void {
                if ('soft' !== $mode && 'mixed' !== $mode) {
                    return;
                }
                $this->git->resetTo($commit->sha, $mode, fn (Outcome $ok, string $error) => $this->finishHistoryOp($ok, $error, $this->translator->trans('Reset to %sha% (%mode%)', [
                    '%sha%' => $commit->shortSha,
                    '%mode%' => $mode,
                ])));
            },
        );
        $this->tui->requestRender();
    }

    private function guardAvailable(): bool
    {
        if ($this->git->isAvailable()) {
            return true;
        }
        $this->unavailable();

        return false;
    }

    /**
     * Finish a history operation: flash the outcome and refresh the branch label
     * and gutter marks. Unlike a plain branch switch this refreshes on failure
     * too — a cherry-pick/revert conflict changes the working tree even as it
     * "fails".
     */
    private function finishHistoryOp(Outcome $ok, string $error, string $success): void
    {
        $this->notifier->flash($ok->isOk() ? $success : $error, $ok->isOk() ? 3.0 : 6.0);
        $this->refresher->refreshBranch();
        $this->refresher->refreshActiveMarks();
    }

    /** A conflicting pop/apply reports on stdout, not stderr — provide the message git doesn't. */
    private function stashError(string $error): string
    {
        return '' !== $error ? $error : $this->translator->trans('Stash left conflicts — resolve them in the editor');
    }

    private function unavailable(): void
    {
        $this->notifier->flash($this->translator->trans('Git not available'));
    }

    /** Sentinel row values — a "\n" can never appear in a refname. */
    private const string CREATE = "create\nbranch";
    private const string DELETE = "delete\nbranch";
    private const string REMOTE_PREFIX = "remote\n";

    /**
     * The branch picker: local branches (newest activity first), unshadowed
     * remote branches (checked out as tracking branches), a create row and a
     * delete row. Checkout failures flash git's own message — no auto-stash.
     */
    public function branches(): void
    {
        if (!$this->git->isAvailable()) {
            $this->unavailable();

            return;
        }
        $this->git->branches(function (array $branches): void {
            // The overlay lives inside the editor: close whichever full view
            // owns the main area so the picker is actually visible.
            $this->views->closeAll();
            $this->tui->setFocus($this->editor);
            $items = [
                ['label' => $this->translator->trans('+ Create new branch…'), 'hint' => '', 'value' => self::CREATE],
                ['label' => $this->translator->trans('− Delete branch…'), 'hint' => '', 'value' => self::DELETE],
            ];
            foreach ($branches as $branch) {
                $hint = match (true) {
                    $branch['remote'] => $this->translator->trans('remote'),
                    $branch['current'] => $this->translator->trans('current'),
                    default => '',
                };
                $items[] = [
                    'label' => ($branch['current'] ? Glyphs::BRANCH_CURRENT.' ' : '  ').$branch['name'],
                    'hint' => $hint,
                    'value' => $branch['remote'] ? self::REMOTE_PREFIX.$branch['name'] : $branch['name'],
                ];
            }
            $this->editor->openListOverlay($this->translator->trans('Branches'), $items, function (string $value): void {
                match (true) {
                    self::CREATE === $value => $this->promptNewBranch(),
                    self::DELETE === $value => $this->pickBranchToDelete(),
                    str_starts_with($value, self::REMOTE_PREFIX) => $this->checkoutTracking(substr($value, \strlen(self::REMOTE_PREFIX))),
                    default => $this->checkout($value),
                };
            });
            $this->tui->requestRender();
        });
    }

    /** Direct new-branch prompt (the status-bar branch menu's "New Branch…"). */
    public function newBranch(): void
    {
        if (!$this->git->isAvailable()) {
            $this->unavailable();

            return;
        }
        $this->views->closeAll();
        $this->tui->setFocus($this->editor);
        $this->promptNewBranch();
    }

    /** Direct delete-branch picker (the status-bar branch menu's "Delete Branch…"). */
    public function deleteBranch(): void
    {
        if (!$this->git->isAvailable()) {
            $this->unavailable();

            return;
        }
        $this->views->closeAll();
        $this->tui->setFocus($this->editor);
        $this->pickBranchToDelete();
    }

    /** Second picker: local branches minus the current one, then a confirm. */
    private function pickBranchToDelete(): void
    {
        $this->git->branches(function (array $branches): void {
            $deletable = array_values(array_filter($branches, static fn (array $b): bool => !$b['remote'] && !$b['current']));
            if ([] === $deletable) {
                $this->notifier->flash($this->translator->trans('No other local branches to delete'));

                return;
            }
            $items = array_map(static fn (array $b): array => ['label' => '  '.$b['name'], 'hint' => '', 'value' => $b['name']], $deletable);
            $this->editor->openListOverlay($this->translator->trans('Delete branch'), $items, function (string $name): void {
                $this->editor->openListOverlay(
                    $this->translator->trans('Delete %branch%?', ['%branch%' => $name]),
                    [
                        ['label' => $this->translator->trans('Delete'), 'hint' => '', 'value' => 'yes'],
                        ['label' => $this->translator->trans('Cancel'), 'hint' => '', 'value' => 'no'],
                    ],
                    function (string $confirm) use ($name): void {
                        if ('yes' !== $confirm) {
                            return;
                        }
                        // -d only: an unmerged branch fails with git's message,
                        // which itself names the -D escape hatch.
                        $this->git->deleteBranch($name, function (Outcome $ok, string $error) use ($name): void {
                            $this->notifier->flash($ok->isOk() ? $this->translator->trans('Deleted branch %branch%', ['%branch%' => $name]) : $error, $ok->isOk() ? 3.0 : 6.0);
                        });
                    },
                );
            });
        });
    }

    /** Check out origin/x as a local tracking branch x. */
    private function checkoutTracking(string $remoteBranch): void
    {
        $slash = strpos($remoteBranch, needle: '/');
        $local = false === $slash ? $remoteBranch : substr($remoteBranch, $slash + 1);
        $this->git->checkoutTracking($remoteBranch, fn (Outcome $ok, string $error) => $this->finishSwitch($ok, $error, $this->translator->trans('Switched to %branch% (tracking %remote%)', [
            '%branch%' => $local,
            '%remote%' => $remoteBranch,
        ])));
    }

    private function promptNewBranch(): void
    {
        $this->editor->openPrompt($this->translator->trans('New branch name'), '', function (string $name): void {
            $name = trim($name);
            if ('' === $name) {
                return;
            }
            $this->git->createBranch($name, fn (Outcome $ok, string $error) => $this->finishSwitch($ok, $error, $this->translator->trans('Created and switched to %branch%', ['%branch%' => $name])));
        });
        $this->tui->requestRender();
    }

    private function checkout(string $branch): void
    {
        $this->git->checkout($branch, fn (Outcome $ok, string $error) => $this->finishSwitch($ok, $error, $this->translator->trans('Switched to %branch%', ['%branch%' => $branch])));
    }

    private function finishSwitch(Outcome $ok, string $error, string $success): void
    {
        $this->notifier->flash($ok->isOk() ? $success : $error, $ok->isOk() ? 3.0 : 6.0);
        if ($ok->isOk()) {
            // HEAD moved and files may have changed on disk; the filesystem
            // poll reconciles open buffers, these refresh the chrome now.
            $this->refresher->refreshBranch();
            $this->refresher->refreshActiveMarks();
        }
        $this->tui->requestRender();
    }
}
