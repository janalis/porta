<?php

declare(strict_types=1);

namespace Porta\Config;

use Porta\Plugin\PluginHost;
use Symfony\Component\Tui\Input\Keybindings;

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

    public function create(): Keybindings
    {
        $this->plugins->ensureLoaded();

        return new Keybindings(array_merge(ActionCatalog::ideDefaults(), $this->plugins->manager()->defaultKeys(), $this->config->keymap));
    }
}
