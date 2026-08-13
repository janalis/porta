<?php

declare(strict_types=1);

namespace Porta\Workbench\Menu;

/**
 * A top-level application menu: a title shown in the menu bar and the list of
 * {@see MenuItem}s (and separators) revealed in its dropdown.
 */
final readonly class Menu
{
    /** @param list<MenuItem> $items */
    public function __construct(
        public string $title,
        public array $items,
    ) {}
}
