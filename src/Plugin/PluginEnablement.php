<?php

declare(strict_types=1);

namespace Porta\Plugin;

/**
 * Which plugins are installed, which are switched off, and the verb to switch
 * one on. This is the slice the *offer* paths need — the highlighting page
 * offering to enable the bundled `tempest` engine, the welcome flow offering
 * the same — as opposed to the full install/remove lifecycle in
 * {@see PluginLifecycleOps}, which only the plugins settings page drives.
 */
interface PluginEnablement
{
    /** Enable a plugin live, persisting the choice. */
    public function enable(string $name): void;

    public function isDisabled(string $name): bool;

    /** @return list<string> every installed plugin name (disabled ones included) */
    public function listedNames(): array;
}
