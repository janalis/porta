<?php

declare(strict_types=1);

namespace Porta\Workbench\Menu;

use Porta\Config\CommandRegistry;
use Porta\Config\LiveKeybindings;
use Porta\Editor\BufferService;
use Porta\Git\ChangeEntry;
use Porta\Git\GitCommands;
use Porta\Git\LogEntry;
use Porta\I18n\Translator;
use Porta\Run\RunController;
use Porta\Widget\Git\ChangesListWidget;
use Porta\Workbench\Dock\BottomDock;
use Porta\Workbench\Dock\DockTab;

/**
 * Builds the right-click context-menu item lists (editor actions, tab close
 * actions, dock strip tabs, git change rows), so the mouse router carries no
 * menu content of its own.
 */
final class ContextMenuBuilder
{
    public function __construct(
        private readonly CommandRegistry $commands,
        private readonly EditorCommandTarget $editor,
        private readonly LiveKeybindings $keybindings,
        private readonly BufferService $bufferService,
        private readonly Translator $translator,
        private readonly BottomDock $dock,
        private readonly RunController $run,
        private readonly ChangesListWidget $changes,
        private readonly GitCommands $git,
    ) {}

    /**
     * The status-bar branch menu — thin triggers over the existing branch
     * flows, so switching/creating/deleting stays single-sourced in GitCommands.
     *
     * @return list<MenuItem>
     */
    public function branchItems(): array
    {
        return [
            new MenuItem($this->translator->trans('Switch Branch…'), '', fn () => $this->git->branches()),
            new MenuItem($this->translator->trans('New Branch…'), '', fn () => $this->git->newBranch()),
            new MenuItem($this->translator->trans('Delete Branch…'), '', fn () => $this->git->deleteBranch()),
        ];
    }

    /** @return list<MenuItem> */
    public function editorItems(): array
    {
        return MenuModel::items(
            [
                'e:cut',
                'e:copy',
                'e:paste',
                MenuModel::SEP,
                'e:goto_definition',
                'e:goto_implementation',
                'e:find_usages',
                MenuModel::SEP,
                'e:rename_symbol',
                'i:reformat',
                'e:code_action',
            ],
            $this->commands,
            $this->editor,
            $this->keybindings->current(),
            $this->translator,
        );
    }

    /**
     * A dock-strip tab's menu: a session tab offers a new sibling next to its
     * close; tool/Debug tabs close (the same action as their strip ×).
     *
     * @return list<MenuItem>
     */
    public function dockTabItems(DockTab $tab, string $key): array
    {
        if (DockTab::Terminal === $tab) {
            return [
                new MenuItem($this->translator->trans('New Session'), '', fn () => $this->run->newTerminalSession()),
                new MenuItem($this->translator->trans('Close Session'), '', fn () => $this->dock->closeTab($tab, $key)),
            ];
        }

        return [new MenuItem($this->translator->trans('Close'), '', fn () => $this->dock->closeTab($tab, $key))];
    }

    /**
     * A git changes row's menu — thin triggers over the widget's existing
     * callbacks, so the git view's staging/discard/history flows stay single-sourced.
     *
     * @return list<MenuItem>
     */
    public function gitChangeItems(ChangeEntry $entry): array
    {
        $stageLabel = 'full' === $entry->stagedState() ? 'Unstage' : 'Stage';

        return [
            new MenuItem($this->translator->trans($stageLabel), '', fn () => $this->changes->toggleStageSelected()),
            new MenuItem($this->translator->trans('Discard…'), '', fn () => $this->changes->armDiscardSelected()),
            new MenuItem($this->translator->trans('File History'), '', fn () => $this->changes->showHistory()),
        ];
    }

    /**
     * A commit row's menu in the history browser — thin triggers over
     * GitCommands, so the copy/branch/cherry-pick/revert/reset flows stay
     * single-sourced.
     *
     * @return list<MenuItem>
     */
    public function gitCommitItems(LogEntry $commit): array
    {
        return [
            new MenuItem($this->translator->trans('Copy Hash'), '', fn () => $this->git->copyCommitHash($commit)),
            new MenuItem($this->translator->trans('Create Branch from Commit…'), '', fn () => $this->git->branchFromCommit($commit)),
            new MenuItem($this->translator->trans('Cherry-pick'), '', fn () => $this->git->cherryPick($commit)),
            new MenuItem($this->translator->trans('Revert Commit'), '', fn () => $this->git->revertCommit($commit)),
            new MenuItem($this->translator->trans('Reset to Commit…'), '', fn () => $this->git->resetToCommit($commit)),
        ];
    }

    /** @return list<MenuItem> */
    public function tabItems(string $path): array
    {
        return [
            new MenuItem($this->translator->trans('Close'), '', fn () => $this->bufferService->closeTab($path)),
            new MenuItem($this->translator->trans('Close Others'), '', fn () => $this->bufferService->closeTabsExcept($path)),
            new MenuItem($this->translator->trans('Close All'), '', fn () => $this->bufferService->closeTabsExcept(null)),
        ];
    }
}
