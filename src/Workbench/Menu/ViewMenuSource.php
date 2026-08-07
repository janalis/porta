<?php

declare(strict_types=1);

namespace Porta\Workbench\Menu;

/**
 * A view's own context menus, for both ways of asking: a right-click at a
 * point, and Ctrl+F10 on whichever of its panes has focus.
 *
 * Both had to move together. The mouse path pulled a selected row out of the
 * git router and then built the items in {@see \Porta\Workbench\Menu\ContextMenuBuilder}; the
 * keyboard path compared focus against two git widgets by identity and built
 * the same items again with its own anchor arithmetic. Three core classes
 * needed to know what a git changes row was to offer a menu over it.
 */
interface ViewMenuSource
{
    /**
     * The menu for a right-press at this SCREEN cell, or null when the point is
     * not over anything of this view's that offers one.
     *
     * Coordinates rather than a MouseEvent deliberately: the button and its
     * modifiers are the input stack's business and were already read by the
     * time this is asked, and taking the event here would make the workbench's
     * keyboard-driven opener depend on the input namespace to reach a menu.
     *
     * Selecting the row under the pointer is part of answering: a menu acts on
     * what it is anchored to, so the two cannot be separated.
     *
     * @return list<MenuItem>|null
     */
    public function contextItemsAt(int $row, int $col): ?array;

    /** The focused pane's menu and where to anchor it, or null when it has none. */
    public function focusedContextMenu(): ?ViewContextMenu;
}
