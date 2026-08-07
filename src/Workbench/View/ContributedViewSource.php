<?php

declare(strict_types=1);

namespace Porta\Workbench\View;

/**
 * The views plugins registered, as {@see ViewRegistrar} needs them.
 *
 * Owned by the CONSUMER, like `Editor\EditorPaneSource`: the workbench declares
 * what it needs and the plugin manager implements it, so `Workbench` never
 * depends on `Plugin`.
 */
interface ContributedViewSource
{
    /** @return list<array{plugin: string, id: string, factory: \Closure}> */
    public function contributedViews(): array;

    /** Report a view that could not be built, through the registering plugin's own fault barrier. */
    public function reportViewFailure(string $plugin, \Throwable $error): void;
}
