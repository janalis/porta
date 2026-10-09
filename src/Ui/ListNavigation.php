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
        $moved = true;
        match (true) {
            $kb->matches($keys, 'move_up') => $list->moveUp(),
            $kb->matches($keys, 'move_down') => $list->moveDown($count),
            $page > 0 && $kb->matches($keys, 'page_up') => $list->moveUp($page),
            $page > 0 && $kb->matches($keys, 'page_down') => $list->moveDown($count, $page),
            default => $moved = false,
        };

        return $moved;
    }
}
