<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\RequiresMethod;
use PHPUnit\Framework\TestCase;
use Porta\Config\ActionRegistry;
use Porta\Config\ConfigWarning;
use Porta\Config\IdeConfig;
use Porta\Config\KeybindingsFactory;
use Porta\Config\KeymapPlatform;
use Porta\Plugin\Runtime\PluginHost;
use Porta\Tests\Support\TempProject;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Tui\Input\Key;

/**
 * The keymap's membership check lives here rather than in {@see IdeConfig},
 * because here the catalog is complete: `ensureLoaded()` has just run, so a
 * plugin pane's actions are as declared as core's.
 */
final class KeybindingsFactoryTest extends TestCase
{
    private const string PLUGIN = <<<'PHP'
        <?php
        return new class('paned') extends \Porta\Plugin\Api\AbstractPlugin {
            public function activate(\Porta\Plugin\Api\PluginApi $api): void
            {
                $api->registerActions(['demo_zoom' => 'Demo: Zoom']);
            }
        };
        PHP;

    /** @return array{KeybindingsFactory, PluginHost} */
    private function factory(TempProject $project, string $hostKeymap = 'linux'): array
    {
        $config = IdeConfig::load($project->dir);
        $host = new PluginHost($config, new EventDispatcher(), $project->dir);

        return [new KeybindingsFactory($host, $config, new ActionRegistry($host->manager), $hostKeymap), $host];
    }

    public function testAPluginPanesActionIsBindableFromPortaJson(): void
    {
        // The seam this whole arrangement exists for: a third-party pane
        // declares an action, and the user's keymap can rebind it — without
        // core's catalog naming it, which it cannot.
        $project = new TempProject([
            '.porta.json' => \json_encode(['keymap' => ['demo_zoom' => 'f6']], \JSON_THROW_ON_ERROR),
            '.porta/plugins/paned/plugin.php' => self::PLUGIN,
        ]);
        [$factory, $host] = $this->factory($project);

        $keys = $factory->create();

        $this->assertSame(['f6'], $keys->getBindings('demo_zoom'));
        $this->assertSame([], $host->manager->warnings, 'a declared action is not a mystery');
    }

    public function testAnIdNothingDeclaresIsDroppedAndReportedOnce(): void
    {
        $project = new TempProject(['.porta.json' => \json_encode(['keymap' => ['nobody_declares_this' => 'f7', 'save' => 'f8']], \JSON_THROW_ON_ERROR)]);
        [$factory, $host] = $this->factory($project);

        $keys = $factory->create();

        $this->assertSame([], $keys->getBindings('nobody_declares_this'));
        $this->assertSame(['f8'], $keys->getBindings('save'), "core's own actions still bind");
        $this->assertEquals([new ConfigWarning('Unknown keymap action "%action%" in .porta.json', ['%action%' => 'nobody_declares_this'])], $host->manager->warnings);

        // LiveKeybindings::rebuild() re-enters this on every keymap edit, and
        // the boot warnings are flashed from one array — reporting per call
        // would grow it without bound.
        $factory->create();
        $this->assertCount(1, $host->manager->warnings, 'reported once, not once per rebuild');
    }

    public function testANamespacedPluginCommandIdIsNeverChecked(): void
    {
        // "plugin:command" ids dispatch by id and carry no descriptor, so there
        // is nothing to check them against — they simply bind if their plugin
        // loads, which may be after this runs.
        $project = new TempProject(['.porta.json' => \json_encode(['keymap' => ['demo:hello' => 'f9']], \JSON_THROW_ON_ERROR)]);
        [$factory, $host] = $this->factory($project);

        $this->assertSame(['f9'], $factory->create()->getBindings('demo:hello'));
        $this->assertSame([], $host->manager->warnings);
    }

    public function testAFresherKeymapIsJudgedToo(): void
    {
        // LiveKeybindings hands in a re-read keymap rather than the boot
        // snapshot; an id it added is as unknown as one that was there at boot.
        $project = new TempProject([]);
        [$factory, $host] = $this->factory($project);

        $keys = $factory->create(keymap: ['still_nothing' => ['f4'], 'save' => ['f5']]);

        $this->assertSame([], $keys->getBindings('still_nothing'));
        $this->assertSame(['f5'], $keys->getBindings('save'));
        $this->assertEquals([new ConfigWarning('Unknown keymap action "%action%" in .porta.json', ['%action%' => 'still_nothing'])], $host->manager->warnings);
    }

    public function testAKeyTheParserCannotReadIsDroppedAndReportedOnce(): void
    {
        // symfony/tui's Keybindings throws on such an id; unvetted, a typo in
        // .porta.json would end the boot and every rebuild after it. The order
        // matters: a typo ahead of a good key, and a wholly dropped action
        // ahead of one still to be vetted.
        $project = new TempProject(['.porta.json' => \json_encode(['keymap' => ['copy_path' => ['ctrl+nope'], 'save' => ['ctlr+s', 'ctrl+s', 'ctrl+nope']]], \JSON_THROW_ON_ERROR)]);
        [$factory, $host] = $this->factory($project);
        $default = $factory->create(keymap: [])->getBindings('copy_path');

        $keys = $factory->create();

        $this->assertSame(['ctrl+s'], $keys->getBindings('save'));
        $this->assertSame($default, $keys->getBindings('copy_path'), 'a list left empty by typos keeps the default, which only a deliberate [] unbinds');
        $this->assertNotSame([], $default);
        $this->assertEquals(
            [
                new ConfigWarning('Invalid key "%key%" bound to "%action%"', ['%key%' => 'ctrl+nope', '%action%' => 'copy_path']),
                new ConfigWarning('Invalid key "%key%" bound to "%action%"', ['%key%' => 'ctlr+s', '%action%' => 'save']),
                new ConfigWarning('Invalid key "%key%" bound to "%action%"', ['%key%' => 'ctrl+nope', '%action%' => 'save']),
            ],
            $host->manager->warnings,
        );

        $factory->create();
        $this->assertCount(3, $host->manager->warnings, 'reported once, not once per rebuild');
    }

    public function testADeliberateEmptyListStillUnbinds(): void
    {
        [$factory, $host] = $this->factory(new TempProject(['.porta.json' => \json_encode(['keymap' => ['copy_path' => []]], \JSON_THROW_ON_ERROR)]));

        $this->assertSame([], $factory->create()->getBindings('copy_path'));
        $this->assertSame([], $host->manager->warnings);
    }

    public function testAPluginKeyTheParserCannotReadIsDroppedToo(): void
    {
        $project = new TempProject(['.porta/plugins/typo/plugin.php' => <<<'PHP'
                <?php
                return new class('typo') extends \Porta\Plugin\Api\AbstractPlugin {
                    public function activate(\Porta\Plugin\Api\PluginApi $api): void
                    {
                        $api->registerCommand('go', 'Go', static function (): void {}, keys: ['ctrl+alt+g', 'ctrl+gg']);
                    }
                };
                PHP]);
        [$factory, $host] = $this->factory($project);

        $this->assertSame(['ctrl+alt+g'], $factory->create()->getBindings('typo:go'));
        $this->assertEquals([new ConfigWarning('Invalid key "%key%" bound to "%action%"', ['%key%' => 'ctrl+gg', '%action%' => 'typo:go'])], $host->manager->warnings);
    }

    public function testMultipleDeclaredActionsAreAllKept(): void
    {
        $project = new TempProject(['.porta.json' => \json_encode(['keymap' => ['save' => 'f2', 'find' => 'f3']], \JSON_THROW_ON_ERROR)]);
        [$factory] = $this->factory($project);

        $keys = $factory->create();

        $this->assertSame(['f2'], $keys->getBindings('save'));
        $this->assertSame(['f3'], $keys->getBindings('find'));
    }

    public function testThePlatformIsTheSettingsElseTheDetectedOne(): void
    {
        [$mac] = $this->factory(new TempProject([]), 'macos');
        $this->assertSame(KeymapPlatform::MacOs, $mac->platform());
        $this->assertSame(['backspace', 'delete'], $mac->create()->getBindings('reset_binding'), 'the Mac keymap is laid over the defaults');

        [$overSsh] = $this->factory(new TempProject(['.porta.json' => \json_encode(['settings' => ['keymapPlatform' => 'macos']], \JSON_THROW_ON_ERROR)]), 'linux');
        $this->assertSame(KeymapPlatform::MacOs, $overSsh->platform(), 'the setting beats detection');
        $this->assertSame(KeymapPlatform::Windows, $overSsh->platform('windows'), 'a fresher setting beats the boot one');
        $this->assertSame([], $overSsh->create(platform: 'linux')->getBindings('reset_binding'));

        [$odd] = $this->factory(new TempProject([]), 'beos');
        $this->assertSame(KeymapPlatform::Linux, $odd->platform(), 'an unknown detection is Linux');
    }

    #[RequiresMethod(Key::class, 'super')]
    public function testTheUsersKeymapWinsOverThePlatforms(): void
    {
        [$factory] = $this->factory(new TempProject(['.porta.json' => \json_encode(['keymap' => ['copy' => 'cmd+k']], \JSON_THROW_ON_ERROR)]), 'macos');

        $keys = $factory->create();

        $this->assertSame(['super+k'], $keys->getBindings('copy'), 'written as cmd, read as super');
        $this->assertSame(['ctrl+x', 'super+x'], $keys->getBindings('cut'), 'the rest of the Mac keymap stands');
        $this->assertSame(['super+x', 'ctrl+x'], $factory->create(arrived: ['super+x'])->getBindings('cut'));
    }
}
