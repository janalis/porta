<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use Porta\Config\ActionRegistry;
use Porta\Config\CommandRegistry;
use Porta\Config\IdeConfig;
use Porta\Config\KeybindingsFactory;
use Porta\Config\LiveKeybindings;
use Porta\Plugin\Runtime\PluginHost;
use Porta\Tests\Support\TempProject;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * The rebuildable keybindings holder: a live plugin toggle re-merges the map
 * so default keys dispatch (or die) without a restart, while the user keymap
 * still wins and the shared KeyParser — the object the Tui mutates when the
 * terminal negotiates the kitty protocol — survives every rebuild.
 */
final class LiveKeybindingsTest extends TestCase
{
    private const string PLUGIN = <<<'PHP'
        <?php
        return new class implements Porta\Plugin\Api\PortaPlugin {
            public function name(): string { return 'keyed'; }
            public function activate(Porta\Plugin\Api\PluginApi $api): void
            {
                $api->registerCommand('boom', 'Boom', static function (): void {}, keys: ['ctrl+alt+b']);
            }
        };
        PHP;

    /** @return array{LiveKeybindings, PluginHost} */
    private function live(TempProject $project): array
    {
        $config = IdeConfig::load($project->dir);
        $host = new PluginHost($config, new EventDispatcher(), $project->dir);
        $factory = new KeybindingsFactory($host, $config, new ActionRegistry($host->manager()));

        return [new LiveKeybindings($factory, $factory->create(), $project->dir), $host];
    }

    public function testRebuildPicksUpALiveActivatedPluginsDefaultKeys(): void
    {
        $project = new TempProject([]);
        [$live, $host] = $this->live($project);
        $this->assertFalse($live->matches("\x1b\x02", 'keyed:boom'), 'no plugin yet: the key is unbound');

        // The plugin lands after boot (a live install) and loads.
        $file = $project->write('.porta/plugins/keyed/plugin.php', self::PLUGIN);
        $host->loader()->loadFile($file, $host->manager());
        $this->assertFalse($live->matches("\x1b\x02", 'keyed:boom'), 'the frozen map does not know the new keys yet');

        $live->rebuild();
        $this->assertSame(['ctrl+alt+b'], $live->getBindings('keyed:boom'), 'rebuild merged the live-activated defaults');
    }

    public function testRebuildDropsADeactivatedPluginsKeysAndUserKeymapStillWins(): void
    {
        $project = new TempProject([
            '.porta.json' => json_encode(['keymap' => ['save' => ['ctrl+shift+s']]]),
            '.porta/plugins/keyed/plugin.php' => self::PLUGIN,
        ]);
        [$live, $host] = $this->live($project);
        $this->assertSame(['ctrl+alt+b'], $live->getBindings('keyed:boom'), 'boot merged the installed plugin');

        $host->manager()->deactivate('keyed', new CommandRegistry());
        $live->rebuild();
        $this->assertSame([], $live->getBindings('keyed:boom'), 'rebuild dropped the deactivated defaults');
        $this->assertSame(['ctrl+shift+s'], $live->getBindings('save'), 'the user keymap still wins after a rebuild');
    }

    public function testRebuildPreservesTheSharedParserInstance(): void
    {
        $project = new TempProject([]);
        [$live] = $this->live($project);
        $parser = $live->current()->getParser();

        $live->rebuild();
        $this->assertSame($parser, $live->current()->getParser(), 'the kitty-negotiated parser rides through every rebuild');
        $this->assertNotSame([], $live->current()->all(), 'the IDE defaults are in the rebuilt map');
    }
}
