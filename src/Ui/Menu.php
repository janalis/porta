<?php

declare(strict_types=1);

namespace Porta\Ui;

/**
 * A top-level application menu: a title shown in the menu bar and the list of
 * {@see MenuItem}s (and separators) revealed in its dropdown.
 */
final class Menu
{
    /** @param list<MenuItem> $items */
    public function __construct(
        public readonly string $title,
        public readonly array $items,
    ) {}
}
