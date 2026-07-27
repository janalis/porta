<?php

declare(strict_types=1);

namespace Porta\Plugin;

/**
 * The full live plugin lifecycle the plugins settings page drives: activate a
 * just-installed plugin, deactivate a removed one, disable one, and re-push the
 * view's rows — on top of the enable/query slice in {@see PluginEnablement},
 * which is all the offer paths need. {@see PluginLifecycle} is the real
 * implementation; tests record calls through a fake.
 */
interface PluginLifecycleOps extends PluginEnablement
{
    /** Live-load an installed plugin.php; returns the failure message or null. */
    public function activateInstalled(string $file): ?string;

    /** Live-deactivate a removed plugin (and reactivate a shadowed copy). */
    public function deactivate(string $name): void;

    /** Disable a plugin live, persisting the choice. */
    public function disable(string $name): void;

    /** A plugin setting changed: re-resolve the LSP servers if that plugin provides one. */
    public function onProviderSettingChanged(string $name): void;

    /** Re-push the settings view's rows (an async install lands after the view re-rendered). */
    public function refreshView(): void;
}
