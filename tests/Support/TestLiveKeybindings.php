<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use Porta\Config\ActionRegistry;
use Porta\Config\IdeConfig;
use Porta\Config\KeybindingsFactory;
use Porta\Config\KeymapPlatform;
use Porta\Config\LiveKeybindings;
use Porta\Plugin\Runtime\PluginHost;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * A {@see LiveKeybindings} the way the container builds one — the factory
 * over a plugin host, booted once — for tests that must construct something
 * holding it (the live settings applier) or that drive its rebuilds.
 *
 * $rootDir defaults to a directory that does not exist: no `.porta.json`, so
 * the shipped and platform defaults are the whole map.
 */
final class TestLiveKeybindings
{
    public static function create(?string $rootDir = null, KeymapPlatform $host = KeymapPlatform::Linux): LiveKeybindings
    {
        $rootDir ??= \sys_get_temp_dir().'/porta-no-project';
        $config = IdeConfig::load($rootDir);
        $plugins = new PluginHost($config, new EventDispatcher(), $rootDir);
        $factory = new KeybindingsFactory($plugins, $config, new ActionRegistry($plugins->manager), $host->value);

        return new LiveKeybindings($factory, $factory->create(), $rootDir);
    }
}
