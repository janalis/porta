<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use Porta\Config\ActionRegistry;
use Porta\Config\ConfigWarning;
use Porta\Config\IdeConfig;
use Porta\Config\KeybindingsFactory;
use Porta\Plugin\Runtime\PluginHost;
use Porta\Tests\Support\TempProject;
use Symfony\Component\EventDispatcher\EventDispatcher;

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
    private function factory(TempProject $project): array
    {
        $config = IdeConfig::load($project->dir);
        $host = new PluginHost($config, new EventDispatcher(), $project->dir);

        return [new KeybindingsFactory($host, $config, new ActionRegistry($host->manager())), $host];
    }

    public function testAPluginPanesActionIsBindableFromPortaJson(): void
    {
        // The seam this whole arrangement exists for: a third-party pane
        // declares an action, and the user's keymap can rebind it — without
        // core's catalog naming it, which it cannot.
        $project = new TempProject([
            '.porta.json' => json_encode(['keymap' => ['demo_zoom' => 'f6']]),
            '.porta/plugins/paned/plugin.php' => self::PLUGIN,
        ]);
        [$factory, $host] = $this->factory($project);

        $keys = $factory->create();

        $this->assertSame(['f6'], $keys->getBindings('demo_zoom'));
        $this->assertSame([], $host->manager()->warnings(), 'a declared action is not a mystery');
    }

    public function testAnIdNothingDeclaresIsDroppedAndReportedOnce(): void
    {
        $project = new TempProject(['.porta.json' => json_encode(['keymap' => ['nobody_declares_this' => 'f7', 'save' => 'f8']])]);
        [$factory, $host] = $this->factory($project);

        $keys = $factory->create();

        $this->assertSame([], $keys->getBindings('nobody_declares_this'));
        $this->assertSame(['f8'], $keys->getBindings('save'), "core's own actions still bind");
        $this->assertEquals([new ConfigWarning('Unknown keymap action "%action%" in .porta.json', ['%action%' => 'nobody_declares_this'])], $host->manager()->warnings());

        // LiveKeybindings::rebuild() re-enters this on every keymap edit, and
        // the boot warnings are flashed from one array — reporting per call
        // would grow it without bound.
        $factory->create();
        $this->assertCount(1, $host->manager()->warnings(), 'reported once, not once per rebuild');
    }

    public function testANamespacedPluginCommandIdIsNeverChecked(): void
    {
        // "plugin:command" ids dispatch by id and carry no descriptor, so there
        // is nothing to check them against — they simply bind if their plugin
        // loads, which may be after this runs.
        $project = new TempProject(['.porta.json' => json_encode(['keymap' => ['demo:hello' => 'f9']])]);
        [$factory, $host] = $this->factory($project);

        $this->assertSame(['f9'], $factory->create()->getBindings('demo:hello'));
        $this->assertSame([], $host->manager()->warnings());
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
        $this->assertEquals([new ConfigWarning('Unknown keymap action "%action%" in .porta.json', ['%action%' => 'still_nothing'])], $host->manager()->warnings());
    }

    public function testMultipleDeclaredActionsAreAllKept(): void
    {
        $project = new TempProject(['.porta.json' => json_encode(['keymap' => ['save' => 'f2', 'find' => 'f3']])]);
        [$factory] = $this->factory($project);

        $keys = $factory->create();

        $this->assertSame(['f2'], $keys->getBindings('save'));
        $this->assertSame(['f3'], $keys->getBindings('find'));
    }
}
