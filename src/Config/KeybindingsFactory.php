<?php

declare(strict_types=1);

namespace Porta\Config;

use Porta\Plugin\Runtime\PluginHost;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Input\KeyParser;

/**
 * Builds the immutable Keybindings map: IDE defaults, overlaid with plugin
 * default keys (which is why creating it forces the plugin load), overlaid
 * with the user's .porta.json keymap. Registered as the container's
 * Keybindings factory.
 */
final class KeybindingsFactory
{
    public function __construct(
        private readonly PluginHost $plugins,
        private readonly IdeConfig $config,
        private readonly ActionRegistry $actions,
    ) {}

    /** @var array<string, true> ids already reported — rebuild() re-runs this on every keymap edit */
    private array $reported = [];

    /**
     * @param ?KeyParser                        $parser LiveKeybindings::rebuild() threads the live parser through (the kitty negotiation lives on it); null builds the boot default
     * @param ?array<string, list<string>>      $keymap a fresher user keymap than the boot config's (LiveKeybindings re-reads .porta.json); null uses the boot snapshot
     */
    public function create(?KeyParser $parser = null, ?array $keymap = null): Keybindings
    {
        $this->plugins->ensureLoaded();
        $keymap ??= $this->config->keymap;

        return new Keybindings(\array_merge(ActionCatalog::ideDefaults(), $this->plugins->manager->defaultKeys, $this->known($keymap)), $parser);
    }

    /**
     * The user's keymap minus the ids nothing declares, each reported through
     * the plugin channel's boot warnings.
     *
     * This is the only place the question can be asked: the catalog is core's
     * plus every loaded plugin's, and `ensureLoaded()` has just run. Namespaced
     * ids ("plugin:command") are plugin COMMANDS, dispatched by id rather than
     * bound to a descriptor, so they are never checked — they simply bind if
     * their plugin loads.
     *
     * @param  array<string, list<string>> $keymap
     * @return array<string, list<string>>
     */
    private function known(array $keymap): array
    {
        $kept = [];
        foreach ($keymap as $action => $keys) {
            if (!\str_contains($action, ':') && !$this->actions->has($action)) {
                if (!\array_key_exists($action, $this->reported)) {
                    $this->reported[$action] = true;
                    $this->plugins->manager->addWarning(new ConfigWarning('Unknown keymap action "%action%" in .porta.json', ['%action%' => $action]));
                }

                continue;
            }
            $kept[$action] = $keys;
        }

        return $kept;
    }
}
