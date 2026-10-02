<?php

declare(strict_types=1);

namespace Porta\Workbench;

use Porta\Workbench\Menu\MenuItem;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/** A clickable status cell's column range and the menu it offers. */
final readonly class StatusMenuSpan
{
    /** @param \Closure(): list<MenuItem> $items */
    public function __construct(
        public int $from,
        public int $to,
        public \Closure $items,
        /** What the cell draws, styled. */
        private string $text = '',
    ) {}

    /** What the cell shows, plain — the keyboard's menu names it with this. */
    public function label(): string
    {
        return \trim(AnsiUtils::stripAnsiCodes($this->text));
    }

    public function contains(int $col): bool
    {
        return $col >= $this->from && $col < $this->to;
    }
}
