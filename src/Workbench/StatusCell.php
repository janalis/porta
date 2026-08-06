<?php

declare(strict_types=1);

namespace Porta\Workbench;

use Porta\Workbench\Menu\MenuItem;

/**
 * One segment of the status bar's left prefix: its text, how prominent it is,
 * and optionally what right-clicking it offers.
 *
 * The menu is the reason this is a value rather than two getters. The branch
 * indicator was hardcoded into {@see StatusBarProvider}, which returned a
 * `branchStart`/`branchEnd` pair so the pointer router could hit-test that one
 * cell and open that one menu — three places naming git to make one span
 * clickable. A cell carries its own span's menu, so the router hit-tests
 * segments generically and no longer knows which is which.
 */
final readonly class StatusCell
{
    /** @param (\Closure(): list<MenuItem>)|null $menu null when the cell is not clickable */
    public function __construct(
        public string $text,
        public StatusTone $tone = StatusTone::Subtle,
        public ?\Closure $menu = null,
    ) {}
}
