<?php

declare(strict_types=1);

namespace Porta\Plugin\Lifecycle;

/**
 * "The installed plugin set changed — re-push whatever lists it." The
 * settings sidebar carries one sub-item per plugin, and a live
 * install/remove/enable has to move those rows; without this seam the plugin
 * lifecycle would hold `Widget/Settings/SettingsNavWidget` directly, which was
 * the ENTIRE `Plugin > Widget` dependency of the codebase.
 *
 * Owned by the consumer, like {@see \Porta\Editor\FileOpener}: the lifecycle
 * declares the one thing it needs done and the settings tier implements it —
 * which is also why it lives in THIS tier rather than `Runtime/`. A
 * consumer-owned seam belongs beside its consumer, and {@see PluginLifecycle}
 * is the only one.
 *
 * The names travel as an argument rather than being fetched by the
 * implementation on purpose — the only source of that list is the lifecycle
 * itself, so a no-argument `refresh()` would need the implementation to depend
 * back on it, and the container would refuse the construction cycle.
 */
interface PluginPagesSync
{
    /** @param list<string> $names every installed plugin, in precedence order */
    public function refresh(array $names): void;
}
