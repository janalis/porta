<?php

declare(strict_types=1);

namespace Porta\Plugin\Runtime;

use Porta\Config\IdeConfig;
use Porta\Config\PortaHome;
use Porta\Config\PortaJsonWriter;
use Porta\Container\AsIdeService;
use Porta\I18n\Translator;
use Porta\Settings\SettingsScope;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Tempest\Highlight\TerminalTheme;

/**
 * Owns the plugin subsystem's construction and one-time boot load. The
 * settings/manager/loader triple can't autowire individually (their scalar
 * arguments come out of IdeConfig and the PORTA_HOME/PORTA_EMBEDDED_PLUGINS
 * environment at instantiation time), so this host builds them together and
 * hands out the instances. ensureLoaded() is idempotent: the first caller —
 * normally the Keybindings factory, because plugin default keys must merge
 * into the immutable map — triggers the actual plugin activation.
 */
#[AsIdeService]
final class PluginHost
{
    private bool $loaded = false;

    public readonly PluginSettings $settings;

    public readonly PluginManager $manager;

    public readonly PluginLoader $loader;

    public function __construct(
        IdeConfig $config,
        EventDispatcherInterface $events,
        string $rootDir,
        ?TerminalTheme $syntaxTheme = null,
        ?PortaJsonWriter $writer = null,
        ?SettingsScope $scope = null,
        ?Translator $translator = null,
    ) {
        $this->settings = new PluginSettings($rootDir, $config->plugins, $writer, $scope);
        $this->manager = new PluginManager($events, $this->settings);
        $this->loader = new PluginLoader(
            $rootDir,
            PortaHome::base(),
            $this->settings,
            PluginLoader::embeddedDir(),
            $config->disabledPlugins,
            $config->enabledPlugins,
            $syntaxTheme,
            $translator,
            $config->trusted,
        );
    }

    /** Load and activate every plugin file exactly once per boot. */
    public function ensureLoaded(): void
    {
        if ($this->loaded) {
            return;
        }
        $this->loaded = true;
        $this->loader->loadInto($this->manager);
    }
}
