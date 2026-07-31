<?php

declare(strict_types=1);

namespace Porta\Workbench\Menu;

/**
 * Where a plugin's menu item lands.
 *
 * Three cases, and the small number is still the point: `Plugins` is the
 * trailing menu every plugin item has always gone to, `EditorContext` is the
 * right-click menu over the editor TEXT, and `FileContext` is the one over a
 * FILE — a tree row or a tab, which share a case because they ask the same
 * question about the same kind of thing. The git-row and dock-strip menus are
 * hand-built {@see MenuItem} closures with no registry behind them, so a case
 * here for one of those is a promise the builder cannot keep until it is
 * converted.
 */
enum MenuSurface
{
    /** The trailing "Plugins" menu in the menu bar — the default, and where every item went before this existed. */
    case Plugins;

    /**
     * The right-click menu over a FILE — a tree row (files only, never a
     * directory) and a tab, both after core's items.
     *
     * The item acts on the file that was CLICKED, which is usually not the
     * active buffer; the path reaches the handler through
     * {@see MenuFileContext}, since a spec is a command id and carries no
     * argument. A plugin reads it with `PluginApi::contextFile()`.
     */
    case FileContext;

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
