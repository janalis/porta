<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\RequiresMethod;
use PHPUnit\Framework\TestCase;
use Porta\Config\ActionRegistry;
use Porta\Config\CommandRegistry;
use Porta\Config\IdeConfig;
use Porta\Config\KeybindingsFactory;
use Porta\Config\KeymapPlatform;
use Porta\Config\LiveKeybindings;
use Porta\Plugin\Runtime\PluginHost;
use Porta\Tests\Support\PreservesPortaHome;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\TestLiveKeybindings;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Input\Keybindings;

/**
 * The rebuildable keybindings holder: a live plugin toggle re-merges the map
 * so default keys dispatch (or die) without a restart, while the user keymap
 * still wins and the shared KeyParser — the object the Tui mutates when the
 * terminal negotiates the kitty protocol — survives every rebuild.
 */
final class LiveKeybindingsTest extends TestCase
{
    use PreservesPortaHome;

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
        $factory = new KeybindingsFactory($host, $config, new ActionRegistry($host->manager));

        return [new LiveKeybindings($factory, $factory->create(), $project->dir), $host];
    }

    /**
     * A rebuild re-reads through `IdeConfig::load()`, so the user's own keymap
     * survives it.
     *
     * The global config layer was landed on the claim that this comes "for
     * free" because rebuild() re-reads the file — true, and asserted nowhere,
     * which is the same shape as a rule recorded in the decision history (`history-01.md`, "The global config scope") and left
     * unimplemented for eleven commits. A claim in a commit message is not a
     * guard.
     */
    public function testARebuildKeepsTheUsersOwnKeymapNotJustTheProjects(): void
    {
        $home = new TempProject(['.porta/config.json' => \json_encode(
            ['keymap' => ['save_all' => ['ctrl+alt+g']]],
            \JSON_THROW_ON_ERROR,
        )]);
        $project = new TempProject([]);
        \putenv('PORTA_HOME='.$home->dir);
        [$live] = $this->live($project);

        $this->assertTrue($live->matches("\x1b\x07", 'save_all'), 'the user file bound it at boot');

        $live->rebuild();

        $this->assertTrue($live->matches("\x1b\x07", 'save_all'), 'and the rebuild did not drop the global layer');
        $this->assertFalse($live->matches("\x1b\x07", 'save'), 'and bound the action the file named, not a neighbour');
    }

    public function testRebuildPicksUpALiveActivatedPluginsDefaultKeys(): void
    {
        $project = new TempProject([]);
        [$live, $host] = $this->live($project);
        $this->assertFalse($live->matches("\x1b\x02", 'keyed:boom'), 'no plugin yet: the key is unbound');

        // The plugin lands after boot (a live install) and loads.
        $file = $project->write('.porta/plugins/keyed/porta.php', self::PLUGIN);
        $host->loader->loadFile($file, $host->manager);
        $this->assertFalse($live->matches("\x1b\x02", 'keyed:boom'), 'the frozen map does not know the new keys yet');

        $live->rebuild();
        $this->assertSame(['ctrl+alt+b'], $live->getBindings('keyed:boom'), 'rebuild merged the live-activated defaults');
    }

    public function testRebuildDropsADeactivatedPluginsKeysAndUserKeymapStillWins(): void
    {
        $project = new TempProject([
            '.porta.json' => \json_encode(['keymap' => ['save' => ['ctrl+shift+s']]], \JSON_THROW_ON_ERROR),
            '.porta/plugins/keyed/porta.php' => self::PLUGIN,
        ]);
        [$live, $host] = $this->live($project);
        $this->assertSame(['ctrl+alt+b'], $live->getBindings('keyed:boom'), 'boot merged the installed plugin');

        $host->manager->deactivate('keyed', new CommandRegistry());
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

    public function testAPlatformSwitchRebuildsTheMapAndHandsItToEveryObserver(): void
    {
        $project = new TempProject([]);
        $live = TestLiveKeybindings::create($project->dir, KeymapPlatform::Linux);
        $handed = [];
        $live->onRebuild(static function (Keybindings $keys) use (&$handed): void {
            $handed[] = $keys;
        });
        $this->assertSame([], $live->getBindings('reset_binding'), 'Linux keeps the list\'s own Delete');

        $live->usePlatform('macos');

        $this->assertSame(KeymapPlatform::MacOs, $live->platform());
        $this->assertSame(['backspace', 'delete'], $live->getBindings('reset_binding'));
        $this->assertCount(1, $handed);
        $this->assertSame($live->current(), $handed[0], 'the observer gets the map that is now live');

        $live->usePlatform('auto');
        $this->assertSame(KeymapPlatform::Linux, $live->platform(), 'auto is the detected platform again');
        $this->assertSame([], $live->getBindings('reset_binding'));
    }

    public function testAPlainKeyIsNoEvidenceOfAnything(): void
    {
        $project = new TempProject([]);
        $live = TestLiveKeybindings::create($project->dir, KeymapPlatform::MacOs);
        $rebuilt = false;
        $live->onRebuild(static function () use (&$rebuilt): void {
            $rebuilt = true;
        });

        $this->assertFalse($live->noteInput('c'));
        $this->assertFalse($live->noteInput("\x1b[99;5u"), 'ctrl+c');
        $this->assertFalse($live->noteInput("\x1b[<0;3;4M"), 'a mouse press parses to nothing');
        $this->assertFalse($rebuilt);
    }

    #[RequiresMethod(Key::class, 'super')]
    public function testACmdChordLeadsItsActionOnceItArrives(): void
    {
        $project = new TempProject([]);
        $live = TestLiveKeybindings::create($project->dir, KeymapPlatform::MacOs);
        $this->assertSame(['ctrl+c', 'super+c'], $live->getBindings('copy'), 'Ctrl leads until Cmd is proven');

        $this->assertTrue($live->noteInput("\x1b[99;9u"), 'the first Cmd+C rebuilds');
        $this->assertSame(['super+c', 'ctrl+c'], $live->getBindings('copy'));
        $this->assertSame(['ctrl+z', 'super+z'], $live->getBindings('undo'), 'only the chord that arrived');

        $this->assertFalse($live->noteInput("\x1b[99;9u"), 'once per chord');
        $this->assertTrue($live->noteInput("\x1b[122::119;10u"), 'Cmd+Shift+Z as Ghostty sends it on AZERTY');
        $this->assertSame(['super+shift+z', 'ctrl+shift+z'], $live->getBindings('redo'));

        $live->rebuild();
        $this->assertSame(['super+c', 'ctrl+c'], $live->getBindings('copy'), 'what arrived survives a plain rebuild');
    }
}
