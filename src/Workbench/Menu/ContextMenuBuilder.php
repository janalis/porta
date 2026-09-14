<?php

declare(strict_types=1);

namespace Porta\Workbench\Menu;

use Porta\Config\CommandRegistry;
use Porta\Config\LiveKeybindings;
use Porta\Editor\BufferService;
use Porta\Editor\TabSide;
use Porta\I18n\Translator;
use Porta\Plugin\Runtime\PluginManager;
use Porta\Run\RunController;
use Porta\Workbench\Dock\BottomDock;
use Porta\Workbench\Dock\DockTab;

/**
 * Builds the right-click context-menu item lists (editor actions, tab close
 * actions, dock strip tabs, git change rows), so the mouse router carries no
 * menu content of its own.
 */
final readonly class ContextMenuBuilder
{
    public function __construct(
        private CommandRegistry $commands,
        private EditorCommandTarget $editor,
        private LiveKeybindings $keybindings,
        private BufferService $bufferService,
        private Translator $translator,
        private BottomDock $dock,
        private RunController $run,
        private PluginManager $plugins,
        private MenuFileContext $fileContext,
    ) {}

    /**
     * The editor's right-click menu: core's verbs, then whatever plugins have
     * put on this surface, behind a separator.
     *
     * Plugin items come LAST and grouped because this menu is core's — a
     * plugin adding one must not push cut/copy/paste down the list. The
     * separator disappears on its own when no plugin contributes: MenuModel
     * drops one that would trail the list.
     *
     * @return list<MenuItem>
     */
    public function editorItems(): array
    {
        $plugins = $this->plugins->menuSpecs(MenuSurface::EditorContext);

        return MenuModel::items(
            [
                'e:cut',
                'e:copy',
                'e:paste',
                MenuModel::SEP,
                // Their own group, below the clipboard verbs they belong with
                // but above the navigation ones: they copy, but not the
                // selection, and PhpStorm keeps them apart for the same reason.
                'i:copy_path',
                'i:copy_reference',
                MenuModel::SEP,
                'e:goto_definition',
                'e:goto_implementation',
                'e:find_usages',
                MenuModel::SEP,
                'e:rename_symbol',
                'i:reformat',
                'e:code_action',
                MenuModel::SEP,
                ...$plugins,
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
    public function dockTabItems(?DockTab $tab, string $key): array
    {
        if (DockTab::Terminal === $tab) {
            return [
                new MenuItem($this->translator->trans('New Session'), '', fn () => $this->run->newTerminalSession()),
                new MenuItem($this->translator->trans('Close Session'), '', fn () => $this->dock->closeTab($tab, $key)),
            ];
        }

        return [new MenuItem($this->translator->trans('Close'), '', fn () => $this->dock->closeTab($tab, $key))];
    }

    /** @return list<MenuItem> */
    public function tabItems(string $path): array
    {
        return [
            new MenuItem($this->translator->trans('Close'), '', fn () => $this->bufferService->closeTab($path)),
            new MenuItem($this->translator->trans('Close Others'), '', fn () => $this->bufferService->closeTabsExcept($path)),
            new MenuItem($this->translator->trans('Close to the Left'), '', fn () => $this->bufferService->closeTabsToTheSide($path, TabSide::Left)),
            new MenuItem($this->translator->trans('Close to the Right'), '', fn () => $this->bufferService->closeTabsToTheSide($path, TabSide::Right)),
            new MenuItem($this->translator->trans('Close All'), '', fn () => $this->bufferService->closeTabsExcept(null)),
            // AFTER the close group, not before it: two E2e tests walk this
            // menu by counting Down presses, so anything inserted above Close
            // All silently moves what they select. The close verbs are the
            // primary group anyway.
            MenuItem::separator(),
            new MenuItem(
                $this->translator->trans($this->bufferService->isPinned($path) ? 'Unpin Tab' : 'Pin Tab'),
                '',
                fn () => $this->bufferService->togglePinned($path),
            ),
            ...$this->fileItems($path),
        ];
    }

    /**
     * What plugins have put on the FILE surface, for the file at $path —
     * appended to the tab menu and to a tree FILE row's menu, behind a
     * separator when there is anything to show.
     *
     * Two things differ from {@see editorItems()} and both are the point:
     * every item runs inside {@see MenuFileContext}, so the handler learns
     * WHICH file was clicked rather than assuming the active one; and the
     * `FILE_SCOPED_IDE` gate is dropped, because a path was clicked — that
     * gate asks whether a buffer is open, which is the wrong question here
     * (right-clicking a tree row with an empty editor is the ordinary way to
     * reach this menu). A plugin's own `enabledWhen` still applies.
     *
     * @return list<MenuItem>
     */
    public function fileItems(string $path): array
    {
        $items = [];
        foreach ($this->plugins->menuSpecs(MenuSurface::FileContext) as $spec) {
            // One spec at a time so label, shortcut and the adoptable-but-
            // unregistered skip stay single-sourced in MenuModel.
            foreach (MenuModel::items([$spec], $this->commands, $this->editor, $this->keybindings->current(), $this->translator) as $item) {
                $run = $item->run;
                $items[] = new MenuItem(
                    $item->label,
                    $item->shortcut,
                    null === $run ? null : fn () => $this->fileContext->with($path, $run),
                    isEnabled: $spec->when,
                );
            }
        }

        return [] === $items ? [] : [MenuItem::separator(), ...$items];
    }
}
