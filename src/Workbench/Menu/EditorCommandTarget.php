<?php

declare(strict_types=1);

namespace Porta\Workbench\Menu;

use Porta\Editor\ActiveFileProvider;
use Porta\Editor\EditorKeymapProvider;

/**
 * What the menu builder needs from the editor: run an action by id, know whether
 * a file is open (items that require one are dimmed otherwise), and read the
 * editor's binding for an action so the menu can print its shortcut.
 *
 * Implemented by the editor widget. The menu is built from
 * {@see \Porta\Config\ActionCatalog} and {@see \Porta\Config\CommandRegistry}, so
 * this is the whole of its editor coupling.
 */
interface EditorCommandTarget extends ActiveFileProvider, EditorKeymapProvider
{
    /** Run an editor action by its catalog id. */
    public function runAction(string $action): void;
}
