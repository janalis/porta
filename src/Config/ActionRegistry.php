<?php

declare(strict_types=1);

namespace Porta\Config;

/**
 * Every rebindable action there is: core's {@see ActionCatalog} table plus the
 * ones plugins register. The keymap page lists these, and `.porta.json` may
 * rebind any of them.
 *
 * The catalog stays a constant — core's actions are known at compile time and
 * nothing should be able to remove one. What could not stay there is a plugin
 * pane's keys: `image_zoom_in` belongs to the image-viewer plugin now, and a
 * constant in `src/` naming it would be core describing plugin behaviour.
 *
 * It lists the plugins that have LOADED, which in a running IDE is all of them:
 * boot resolves `Keybindings` before the widget tree exists, and that resolution
 * is what triggers the one-time load. (The same arrangement
 * {@see \Porta\Editor\ViewerProviderSource} has — a registration is visible
 * once its plugin is in.)
 *
 * **The composition is only complete after plugins load**, which is why the
 * keymap's membership check moved out of {@see IdeConfig::load()} (it runs
 * before the container exists, let alone the plugins) and into
 * {@see KeybindingsFactory}, where the map is actually built. An unknown id is
 * reported there, through the same boot-warning channel a failed plugin uses.
 */
final readonly class ActionRegistry
{
    public function __construct(
        private RebindableActionSource $plugins,
    ) {}

    /**
     * Core's actions first, then the plugins' in load order — the order the
     * keymap page lists them in.
     *
     * @return array<string, string> action id => untranslated label
     */
    public function labels(): array
    {
        $labels = [];
        foreach (ActionCatalog::ACTIONS as $id => $spec) {
            $labels[$id] = $spec['label'];
        }

        // Union, not a spread: a colliding id keeps CORE's label and its
        // position. PluginApi::registerActions() already refuses a core id, so
        // this is the second lock — nothing a plugin does can relabel "Save
        // File" or reorder the page's first rows.
        return $labels + $this->plugins->rebindableActions();
    }

    /** Whether $id is a rebindable action at all — core's or a plugin's. */
    public function has(string $id): bool
    {
        return \array_key_exists($id, $this->labels());
    }

    /** The action's untranslated label, or the id itself when nothing declares it. */
    public function label(string $id): string
    {
        return $this->labels()[$id] ?? $id;
    }
}
