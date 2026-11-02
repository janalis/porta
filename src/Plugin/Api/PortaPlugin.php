<?php

declare(strict_types=1);

namespace Porta\Plugin\Api;

/**
 * The contract a plugin file fulfills: `<project>/.porta/plugins/<dir>/porta.php`
 * (or the same path under `~/.porta/plugins/`) must `return` an instance of this
 * interface. `activate()` runs once during IDE construction and may only call
 * the {@see PluginApi} registration methods — the action methods (flash, editor
 * access, …) become available to the registered handlers once the IDE is up.
 *
 * Prefer anonymous classes (`return new class implements PortaPlugin {…}`) —
 * two plugins declaring the same named class is an uncatchable PHP fatal.
 */
interface PortaPlugin
{
    /** Stable identifier (/^[a-z0-9][a-z0-9_-]*$/i) — also the keymap namespace (`<name>:<command>`). */
    public function name(): string;

    /** Register commands, keybindings, menu items and event hooks. Called once at boot. */
    public function activate(PluginApi $api): void;
}
