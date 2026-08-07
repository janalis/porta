<?php

declare(strict_types=1);

namespace Porta\Workbench\Menu;

/**
 * A view's context menu with the screen cell to anchor it at — what the
 * keyboard path (Ctrl+F10) needs and the mouse path already knows.
 */
final readonly class ViewContextMenu
{
    /** @param list<MenuItem> $items */
    public function __construct(
        public array $items,
        public int $row,
        public int $col,
    ) {}
}
