<?php

declare(strict_types=1);

namespace Porta\Ui;

use Porta\Config\CommandRegistry;
use Porta\Editor\BufferService;
use Porta\Git\ChangeEntry;
use Porta\I18n\Translator;
use Porta\Run\RunController;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\Git\ChangesListWidget;
use Symfony\Component\Tui\Input\Keybindings;

/**
 * Builds the right-click context-menu item lists (editor actions, tab close
 * actions, dock strip tabs, git change rows), so the mouse router carries no
 * menu content of its own.
 */
final class ContextMenuBuilder
{
    public function __construct(
        private readonly CommandRegistry $commands,
        private readonly CodeEditorWidget $editor,
        private readonly Keybindings $keybindings,
        private readonly BufferService $bufferService,
        private readonly Translator $translator,
        private readonly BottomDock $dock,
        private readonly RunController $run,
        private readonly ChangesListWidget $changes,
    ) {}

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
            $this->keybindings,
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
