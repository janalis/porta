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
    ) {}

    /**
     * @param ?KeyParser                        $parser LiveKeybindings::rebuild() threads the live parser through (the kitty negotiation lives on it); null builds the boot default
     * @param ?array<string, list<string>>      $keymap a fresher user keymap than the boot config's (LiveKeybindings re-reads .porta.json); null uses the boot snapshot
     */
    public function create(?KeyParser $parser = null, ?array $keymap = null): Keybindings
    {
        $this->plugins->ensureLoaded();

        return new Keybindings(array_merge(ActionCatalog::ideDefaults(), $this->plugins->manager()->defaultKeys(), $keymap ?? $this->config->keymap), $parser);
    }
}
