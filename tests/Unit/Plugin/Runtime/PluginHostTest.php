<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Plugin\Runtime;

use PHPUnit\Framework\TestCase;
use Porta\Config\ActionCatalog;
use Porta\Config\ActionRegistry;
use Porta\Config\IdeConfig;
use Porta\Config\KeybindingsFactory;
use Porta\Plugin\Runtime\PluginHost;
use Porta\Tests\Support\TempProject;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class PluginHostTest extends TestCase
{
    private function host(TempProject $project): PluginHost
    {
        return new PluginHost(IdeConfig::load($project->dir), new EventDispatcher(), $project->dir);
    }

    public function testBuildsTheSubsystemAndLoadsPluginsExactlyOnce(): void
    {
        $project = new TempProject([
            '.porta/plugins/greeter/plugin.php' => <<<'PHP'
                <?php
                return new class implements Porta\Plugin\Api\PortaPlugin {
                    public function name(): string { return 'greeter'; }
                    public function activate(Porta\Plugin\Api\PluginApi $api): void
                    {
                        $api->registerCommand('hello', 'Say Hello', static function (): void {});
                    }
                };
                PHP,
        ]);
        $host = $this->host($project);

        $this->assertFalse($host->manager()->hasPlugin('greeter'), 'nothing loads until ensureLoaded()');
        $host->ensureLoaded();
        $this->assertTrue($host->manager()->hasPlugin('greeter'));

        $host->ensureLoaded(); // idempotent: a second call must not re-activate
        $this->assertTrue($host->manager()->hasPlugin('greeter'));
        $this->assertSame($host->settings(), $host->settings());
        $this->assertSame($host->loader(), $host->loader());
    }

    public function testKeybindingsFactoryMergesDefaultsPluginKeysAndUserKeymap(): void
    {
        $project = new TempProject([
            '.porta.json' => json_encode(['keymap' => ['save' => ['ctrl+shift+s']]]),
            '.porta/plugins/keyed/plugin.php' => <<<'PHP'
                <?php
                return new class implements Porta\Plugin\Api\PortaPlugin {
                    public function name(): string { return 'keyed'; }
                    public function activate(Porta\Plugin\Api\PluginApi $api): void
                    {
                        $api->registerCommand('boom', 'Boom', static function (): void {}, keys: ['ctrl+alt+b']);
                    }
                };
                PHP,
        ]);
        $config = IdeConfig::load($project->dir);
        $host = new PluginHost($config, new EventDispatcher(), $project->dir);
        $keybindings = new KeybindingsFactory($host, $config, new ActionRegistry($host->manager()))->create();

        $this->assertTrue($host->manager()->hasPlugin('keyed'), 'creating the map forces the plugin load');
        $this->assertSame(['ctrl+shift+s'], $keybindings->getBindings('save'), 'user keymap wins');
        $this->assertSame(['ctrl+alt+b'], $keybindings->getBindings('keyed:boom'), 'plugin default keys merge in');
        $this->assertSame(ActionCatalog::ideDefaults()['ide_quit'], $keybindings->getBindings('ide_quit'), 'IDE defaults survive');
    }
}
