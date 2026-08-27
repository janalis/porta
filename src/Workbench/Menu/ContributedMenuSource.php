<?php

declare(strict_types=1);

namespace Porta\Workbench\Menu;

/**
 * The top-level menus plugins registered, as {@see MenuRegistrar} needs them.
 *
 * Owned by the CONSUMER, like {@see \Porta\Workbench\View\ContributedViewSource}:
 * the workbench declares what it needs and the plugin manager implements it, so
 * `Porta\Workbench` never depends on `Porta\Plugin`.
 */
interface ContributedMenuSource
{
    /**
     * In load order, each with the plugin that contributed it — the key a live
     * enable/disable retracts by. A plugin's own barrier already caught
     * anything that failed to build.
     *
     * @return list<array{plugin: string, contribution: MenuContribution}>
     */
    public function contributedMenus(): array;
}
