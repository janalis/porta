<?php

declare(strict_types=1);

namespace Porta\Git;

use Porta\I18n\Translator;
use Porta\Support\ProjectPaths;
use Porta\Ui\Notifier;
use Porta\Ui\WorkspaceViews;
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
        private readonly WorkspaceViews $views,
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

    /**
     * The branch picker: list local branches (newest activity first) in the
     * editor's list overlay, switch on pick, or create a new branch via the
     * leading "+ Create new branch…" row. Checkout failures flash git's own
     * message (the dirty-working-tree refusal in particular) — no auto-stash.
     */
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
                $items[] = [
                    'label' => ($branch['current'] ? '✓ ' : '  ').$branch['name'],
                    'hint' => $branch['remote'] ? $this->translator->trans('remote') : ($branch['current'] ? $this->translator->trans('current') : ''),
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

    /** Second picker: local branches minus the current one, then a confirm. */
    private function pickBranchToDelete(): void
    {
        $this->git->branches(function (array $branches): void {
            $deletable = array_values(array_filter($branches, static fn (array $b): bool => !$b['remote'] && !$b['current']));
            if ([] === $deletable) {
                $this->notifier->flash($this->translator->trans('No other local branches to delete'));
                $this->tui->requestRender();

                return;
            }
            $items = array_map(fn (array $b): array => ['label' => '  '.$b['name'], 'hint' => '', 'value' => $b['name']], $deletable);
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
                        $this->git->deleteBranch($name, function (bool $ok, string $error) use ($name): void {
                            $this->notifier->flash($ok ? $this->translator->trans('Deleted branch %branch%', ['%branch%' => $name]) : $error, $ok ? 3.0 : 6.0);
                            $this->tui->requestRender();
                        });
                    },
                );
            });
            $this->tui->requestRender();
        });
    }

    /** Check out origin/x as a local tracking branch x. */
    private function checkoutTracking(string $remoteBranch): void
    {
        $slash = strpos($remoteBranch, '/');
        $local = false === $slash ? $remoteBranch : substr($remoteBranch, $slash + 1);
        $this->git->checkoutTracking($remoteBranch, fn (bool $ok, string $error) => $this->finishSwitch($ok, $error, $this->translator->trans('Switched to %branch% (tracking %remote%)', [
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
            $this->git->createBranch($name, fn (bool $ok, string $error) => $this->finishSwitch($ok, $error, $this->translator->trans('Created and switched to %branch%', ['%branch%' => $name])));
        });
        $this->tui->requestRender();
    }

    private function checkout(string $branch): void
    {
        $this->git->checkout($branch, fn (bool $ok, string $error) => $this->finishSwitch($ok, $error, $this->translator->trans('Switched to %branch%', ['%branch%' => $branch])));
    }

    private function finishSwitch(bool $ok, string $error, string $success): void
    {
        $this->notifier->flash($ok ? $success : $error, $ok ? 3.0 : 6.0);
        if ($ok) {
            // HEAD moved and files may have changed on disk; the filesystem
            // poll reconciles open buffers, these refresh the chrome now.
            $this->refresher->refreshBranch();
            $this->refresher->refreshActiveMarks();
        }
        $this->tui->requestRender();
    }
}
