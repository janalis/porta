<?php

declare(strict_types=1);

namespace Porta\Workbench;

use Porta\Workbench\Menu\MenuItem;

/** A clickable status cell's column range and the menu it offers. */
final readonly class StatusMenuSpan
{
    /** @param \Closure(): list<MenuItem> $items */
    public function __construct(
        public int $from,
        public int $to,
        public \Closure $items,
    ) {}

    public function contains(int $col): bool
    {
        return $col >= $this->from && $col < $this->to;
    }
}
