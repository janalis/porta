<?php

declare(strict_types=1);

namespace Porta\Workbench\Dock;

/**
 * The dock list tools plugins contribute — a tab of results with
 * jump-to-location, the shape core's Problems / TODO / Usages tabs already
 * have.
 *
 * Owned by the CONSUMING namespace (the {@see \Porta\Editor\ViewerProviderSource}
 * precedent), so `Porta\Workbench` never depends on `Porta\Plugin` even though
 * every row here comes from a plugin's `registerListTool()` call.
 *
 * The two closures arrive already wrapped in their plugin's fault barrier, so a
 * consumer cannot forget to guard them — a throwing tool flashes and the IDE
 * keeps running.
 */
interface DockToolSource
{
    /** @return list<array{plugin: string, id: string, title: string, rerun: (\Closure(): void)|null, activate: (\Closure(string, int): void)|null}> in load order */
    public function listTools(): array;
}
