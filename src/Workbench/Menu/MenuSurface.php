<?php

declare(strict_types=1);

namespace Porta\Workbench\Menu;

/**
 * Where a plugin's menu item lands.
 *
 * Two cases, and the small number is the point: `Plugins` is the trailing menu
 * every plugin item has always gone to, and `EditorContext` is the one CONTEXT
 * menu built from command ids ({@see ContextMenuBuilder::editorItems()}), so it
 * is the only other surface a spec can reach without work first. The tab, tree,
 * git-row and dock-strip menus are hand-built {@see MenuItem} closures with no
 * registry behind them — adding a case here for one of those is a promise the
 * builder cannot keep until it is converted.
 */
enum MenuSurface
{
    /** The trailing "Plugins" menu in the menu bar — the default, and where every item went before this existed. */
    case Plugins;

    /**
     * The editor's right-click menu, after core's items.
     *
     * Reachable only while the CODE editor holds the pane: the router resolves
     * this surface from the editor widget's rect, and a viewer pane detaches
     * that widget. So a command scoped to a viewer tab (a graph export, an
     * image action) put here would be gated on exactly the state that hides it
     * — measured before graph-viewer's exports were moved, which is why they
     * stayed in the Plugins menu.
     */
    case EditorContext;
}
