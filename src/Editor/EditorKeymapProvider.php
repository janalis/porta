<?php

declare(strict_types=1);

namespace Porta\Editor;

use Symfony\Component\Tui\Input\Keybindings;

/**
 * The editor's own key map, which shadows the IDE-level one for editing verbs.
 * Implemented by the editor widget (via the vendored `KeybindingsTrait`).
 *
 * Depended on by the keymap settings page, which lists and rebinds these, and —
 * through {@see \Porta\Ui\Menu\EditorCommandTarget} — by the menu builder, which
 * shows the editor binding for an action before falling back to the IDE's.
 */
interface EditorKeymapProvider
{
    public function getKeybindings(): Keybindings;
}
