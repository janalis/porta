<?php

declare(strict_types=1);

namespace Porta\Workbench;

use Porta\Config\LiveKeybindings;
use Porta\Container\AsIdeService;
use Porta\Editor\EditorPanes;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\Settings\KeybindingsListWidget;
use Symfony\Component\Tui\Input\Keybindings;

/**
 * The widgets that match keys against their own merged map yet must follow
 * the live one: the editor and the registered panes, whose actions the keymap
 * page rebinds, and that page's own list, whose reset key differs by
 * platform. Each is handed every rebuilt map as its explicit keybindings,
 * which the vendor merge lets win over the boot context's (see
 * {@see LiveKeybindings}).
 *
 * A pane registered later is covered too: activating a plugin rebuilds the
 * map after its panes are registered.
 */
#[AsIdeService]
final readonly class KeymapFollowers
{
    public function __construct(
        private LiveKeybindings $keys,
        private CodeEditorWidget $editor,
        private EditorPanes $panes,
        private KeybindingsListWidget $keymapList,
    ) {}

    public function wire(): void
    {
        $this->keys->onRebuild(function (Keybindings $keys): void {
            $this->editor->setKeybindings($keys);
            $this->keymapList->setKeybindings($keys);
            foreach ($this->panes->seated() as $pane) {
                $pane->setKeybindings($keys);
            }
        });
    }
}
