<?php

declare(strict_types=1);

namespace Porta\Tests\Behat\Catalog;

use Porta\Config\ActionCatalog;
use Porta\Config\CommandRegistry;
use Porta\Config\IdeConfig;
use Porta\Ide;
use Porta\Onboarding\OnboardingState;
use Porta\Plugin\Runtime\PluginHost;
use Porta\Plugin\Runtime\PluginSettings;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Symfony\Component\Tui\Terminal\VirtualTerminal;

/**
 * Everything a user can ask Porta for by name, read off an Ide booted with
 * every bundled plugin on: the rebindable actions (core's and the plugins'),
 * the palette commands that are not actions, and the settings, core's by key
 * and each plugin's as "<plugin>.<key>".
 */
final class Catalog
{
    /**
     * @return array{action: list<string>, command: list<string>, setting: list<string>}
     */
    public static function read(string $pluginsDir): array
    {
        $dirs = \glob($pluginsDir.'/*', \GLOB_ONLYDIR);
        $plugins = false === $dirs ? [] : \array_map(\basename(...), $dirs);
        \putenv('PORTA_EMBEDDED_PLUGINS='.$pluginsDir);
        // No server, no git: the catalog is what is registered, not what runs
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['composer.json' => '{}']);
        new OnboardingState($project->dir)->markWelcomeSeen();
        $ide = new Ide($project->dir, null, new VirtualTerminal(120, 32), plugins: $plugins);
        $host = Services::resolve($ide, PluginHost::class);
        $host->ensureLoaded();

        $actions = [...\array_keys(ActionCatalog::ACTIONS), ...\array_keys($host->manager->rebindableActions())];
        $commands = \array_values(\array_diff(\array_column(Services::resolve($ide, CommandRegistry::class)->paletteCommands(), 'id'), $actions));
        $settings = \array_keys(IdeConfig::DEFAULT_SETTINGS);
        $pluginSettings = Probe::object($host->manager, 'settings');
        \assert($pluginSettings instanceof PluginSettings, description: 'the plugin manager holds the plugin settings');
        foreach ($plugins as $plugin) {
            foreach ($pluginSettings->rows($plugin) as $row) {
                $settings[] = $plugin.'.'.\substr($row->key, \strlen('pluginSetting:'));
            }
        }

        return ['action' => \array_values(\array_unique($actions)), 'command' => $commands, 'setting' => $settings];
    }
}
