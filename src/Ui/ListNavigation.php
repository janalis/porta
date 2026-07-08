<?php

declare(strict_types=1);

namespace Porta\Ui;

use Symfony\Component\Tui\Input\Keybindings;

/**
 * The move_up/move_down (and page_up/page_down) keybinding ladder shared by
 * the list-style widgets, so each widget's handleInput() keeps only its own
 * actions. Returns whether $keys moved the selection; the caller repaints.
 */
final class ListNavigation
{
    public static function handleKey(string $keys, Keybindings $kb, ListSelection $list, int $count, int $page = 0): bool
    {
        if ($kb->matches($keys, 'move_up')) {
            $list->moveUp();
        } elseif ($kb->matches($keys, 'move_down')) {
            $list->moveDown($count);
        } elseif ($page > 0 && $kb->matches($keys, 'page_up')) {
            $list->moveUp($page);
        } elseif ($page > 0 && $kb->matches($keys, 'page_down')) {
            $list->moveDown($count, $page);
        } else {
            return false;
        }

        return true;
    }
}
