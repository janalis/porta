<?php

declare(strict_types=1);

namespace Porta\Ui;

use Porta\Config\CommandRegistry;
use Porta\Editor\BufferService;
use Porta\Widget\CodeEditorWidget;
use Symfony\Component\Tui\Input\Keybindings;

/**
 * Builds the right-click context-menu item lists (editor actions, tab close
 * actions), so the mouse router carries no menu content of its own.
 */
final class ContextMenuBuilder
{
    public function __construct(
        private readonly CommandRegistry $commands,
        private readonly CodeEditorWidget $editor,
        private readonly Keybindings $keybindings,
        private readonly BufferService $bufferService,
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
        );
    }

    /** @return list<MenuItem> */
    public function tabItems(string $path): array
    {
        return [
            new MenuItem('Close', '', fn () => $this->bufferService->closeTab($path)),
            new MenuItem('Close Others', '', fn () => $this->bufferService->closeTabsExcept($path)),
            new MenuItem('Close All', '', fn () => $this->bufferService->closeTabsExcept(null)),
        ];
    }
}
