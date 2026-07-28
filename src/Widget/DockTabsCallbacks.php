<?php

declare(strict_types=1);

namespace Porta\Widget;

use Porta\Workbench\Dock\DockTab;

/**
 * The dock strip's outbound hooks, wired in one call instead of three fluent
 * setters — the {@see \Porta\Widget\Git\DiffViewCallbacks} shape. Every slot
 * is nullable: a bare widget fires nothing.
 */
final readonly class DockTabsCallbacks
{
    /**
     * @param (callable(DockTab, string): void)|null   $onTabSelected
     * @param (callable(DockTab, string): void)|null   $onTabClosed
     * @param (callable(DockTab, int, int): void)|null $onTabMoved drag-reorder within a family: family, from, to
     */
    public function __construct(
        public mixed $onTabSelected = null,
        public mixed $onTabClosed = null,
        public mixed $onTabMoved = null,
    ) {}
}
