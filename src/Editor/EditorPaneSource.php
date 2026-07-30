<?php

declare(strict_types=1);

namespace Porta\Editor;

/**
 * Where pane registrations come from — the plugins that supplied them.
 *
 * Owned by the CONSUMING namespace, the {@see ViewerProviderSource} precedent,
 * so the editor never depends on `Porta\Plugin` even though every row here
 * originates in a plugin's `registerPane()` call. It matters more here than it
 * usually does: the drain has to name {@see \Porta\Widget\EditorPaneWidget},
 * and putting the drain in the plugin tier would resurrect the `Plugin > Widget`
 * edge round 18 severed on purpose.
 *
 * The factory and loader travel as bare `\Closure`s for the same reason — a
 * signature naming the widget type would drag it back across the boundary. The
 * drain checks what comes out instead, and reports a factory that returns
 * something else through this same seam.
 */
interface EditorPaneSource
{
    /** @return list<array{plugin: string, id: string, pane: \Closure, load: \Closure}> in load order */
    public function panes(): array;

    /** Report a pane that could not be built, through the registering plugin's own fault barrier. */
    public function reportPaneFailure(string $plugin, \Throwable $error): void;
}
