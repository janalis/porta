<?php

declare(strict_types=1);

namespace Porta\Config;

use Porta\Plugin\PluginHost;
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

    /** @param ?KeyParser $parser LiveKeybindings::rebuild() threads the live parser through (the kitty negotiation lives on it); null builds the boot default */
    public function create(?KeyParser $parser = null): Keybindings
    {
        $this->plugins->ensureLoaded();

        return new Keybindings(array_merge(ActionCatalog::ideDefaults(), $this->plugins->manager()->defaultKeys(), $this->config->keymap), $parser);
    }
}
