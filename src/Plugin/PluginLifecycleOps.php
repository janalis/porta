<?php

declare(strict_types=1);

namespace Porta\Plugin;

/**
 * The live plugin operations the settings UI drives: activate a just-installed
 * plugin, deactivate a removed one, flip the persisted enabled switch, and
 * query the installed/disabled state. {@see PluginLifecycle} is the real
 * implementation; tests record calls through a fake.
 */
interface PluginLifecycleOps
{
    /** Live-load an installed plugin.php; returns the failure message or null. */
    public function activateInstalled(string $file): ?string;

    /** Live-deactivate a removed plugin (and reactivate a shadowed copy). */
    public function deactivate(string $name): void;

    /** Enable a plugin live, persisting the choice. */
    public function enable(string $name): void;

    /** Disable a plugin live, persisting the choice. */
    public function disable(string $name): void;

    public function isDisabled(string $name): bool;

    /** @return list<string> every installed plugin name (disabled ones included) */
    public function listedNames(): array;

    /** Re-push the settings view's rows (an async install lands after the view re-rendered). */
    public function refreshView(): void;
}
