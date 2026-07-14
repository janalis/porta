<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Plugin;

use PHPUnit\Framework\TestCase;
use Porta\Config\CommandRegistry;
use Porta\Editor\Buffer;
use Porta\Editor\BufferManager;
use Porta\Event\BufferOpened;
use Porta\Event\BufferSaved;
use Porta\Event\BufferSwitched;
use Porta\I18n\Translator;
use Porta\Plugin\PluginApi;
use Porta\Plugin\PluginEvents;
use Porta\Plugin\PluginManager;
use Porta\Plugin\PluginRuntime;
use Porta\Plugin\PluginSettings;
use Porta\Widget\CodeEditorWidget;

final class PluginManagerTest extends TestCase
{
    private function buffer(string $path): Buffer
    {
        return new BufferManager()->open($path, '');
    }

    /** @var list<string> */
    private array $flashes = [];

    private function wiredManager(PluginEvents $events): PluginManager
    {
        $manager = new PluginManager($events, new PluginSettings('/p', []));
        $this->flashes = [];
        $manager->wireRuntime(new PluginRuntime(
            flash: function (string $message, float $seconds): void {
                $this->flashes[] = $message;
            },
            translator: new Translator('en'),
            editor: new CodeEditorWidget(),
            openFile: static function (): void {},
            requestRender: static function (): void {},
            setDiagnostics: static function (): void {},
        ));

        return $manager;
    }

    public function testCommitAggregatesCommandsKeysAndMenuSpecs(): void
    {
        $manager = new PluginManager(new PluginEvents(), new PluginSettings('/p', []));
        $api = new PluginApi('demo', $manager, '/p', new PluginSettings('/p', []));
        $api->registerCommand('hello', 'Say Hello', static function (): void {}, ['ctrl+alt+y']);
        $api->registerCommand('quiet', 'Quiet One', static function (): void {});
        $api->addMenuItem('hello');
        $manager->commit($api);

        $this->assertTrue($manager->hasPlugin('demo'));
        $this->assertSame(['demo'], $manager->names());
        $this->assertSame(['demo:hello' => ['ctrl+alt+y']], $manager->defaultKeys(), 'keyless commands contribute no defaults');
        $this->assertSame([['plugin' => 'demo', 'spec' => 'i:demo:hello', 'when' => null]], $manager->menuSpecs());

        $registry = new CommandRegistry();
        $manager->registerCommands($registry);
        $this->assertSame('Say Hello', $registry->label('demo:hello'));
        $this->assertContains('demo:quiet', $registry->keyBoundIds(), 'always keyBound so user rebinds dispatch');
        $this->assertContains('Say Hello', array_column($registry->paletteCommands(), 'label'));
    }

    public function testRegisterCommandsIsIncrementalAcrossCommits(): void
    {
        $manager = new PluginManager(new PluginEvents(), new PluginSettings('/p', []));
        $first = new PluginApi('one', $manager, '/p', new PluginSettings('/p', []));
        $first->registerCommand('a', 'Command A', static function (): void {});
        $manager->commit($first);

        $registry = new CommandRegistry();
        $manager->registerCommands($registry);

        // A live install commits a second plugin after boot registration.
        $second = new PluginApi('two', $manager, '/p', new PluginSettings('/p', []));
        $second->registerCommand('b', 'Command B', static function (): void {});
        $manager->commit($second);
        $manager->registerCommands($registry);

        $labels = array_column($registry->paletteCommands(), 'label');
        $this->assertSame(1, \count(array_keys($labels, 'Command A', true)), 'no palette duplicate from re-registration');
        $this->assertSame(1, \count(array_keys($labels, 'Command B', true)));
    }

    public function testDeactivateUndoesEverythingAndAllowsAReinstall(): void
    {
        $events = new PluginEvents();
        $settings = new PluginSettings('/p', []);
        $manager = new PluginManager($events, $settings);
        $registry = new CommandRegistry();
        $registry->add('core:stay', static function (): void {}, keyBound: true, label: 'Core');

        $api = new PluginApi('demo', $manager, '/p', $settings);
        $api->registerSetting('binary', 'Binary', 'text', '');
        $api->registerCommand('hi', 'Say Hi', static function (): void {}, ['ctrl+alt+y']);
        $api->addMenuItem('hi');
        $hookRan = 0;
        $api->onFileSaved(function () use (&$hookRan): void {
            ++$hookRan;
        });
        $manager->commit($api);
        $bystander = new PluginApi('bystander', $manager, '/p', $settings);
        $bystander->registerCommand('stay', 'Bystander Stay', static function (): void {});
        $manager->commit($bystander);
        $manager->registerCommands($registry);
        $this->assertContains('demo:hi', $registry->keyBoundIds());
        $this->assertCount(1, $settings->rows('demo'));

        $manager->deactivate('demo', $registry);
        $manager->deactivate('demo', $registry); // unknown now → no-op

        $this->assertFalse($manager->hasPlugin('demo'));
        $this->assertSame(['bystander'], $manager->names(), 'the other plugin is untouched');
        $this->assertContains('bystander:stay', $registry->keyBoundIds());
        $this->assertSame([], $manager->menuSpecs());
        $this->assertSame([], $manager->defaultKeys());
        $this->assertNotContains('demo:hi', $registry->keyBoundIds(), 'key dispatch died');
        $this->assertContains('core:stay', $registry->keyBoundIds(), 'core commands survive');
        $this->assertNotContains('Say Hi', array_column($registry->paletteCommands(), 'label'), 'palette entry died');
        $this->assertSame([], $settings->rows('demo'), 'setting descriptors dropped');
        $events->onBufferSaved(new BufferSaved($this->buffer('/a')));
        $this->assertSame(0, $hookRan, 'hooks unsubscribed');

        // The same name reinstalls live, and the watermark stayed exact.
        $again = new PluginApi('demo', $manager, '/p', $settings);
        $again->registerCommand('hi', 'Say Hi Again', static function (): void {});
        $manager->commit($again);
        $manager->registerCommands($registry);
        $labels = array_column($registry->paletteCommands(), 'label');
        $this->assertSame(1, \count(array_keys($labels, 'Say Hi Again', true)));
        $this->assertNotContains('Say Hi', $labels, 'no stale duplicate came back');
    }

    public function testAThrowingMenuWhenClosureReadsDisabledAndReportsOnce(): void
    {
        $manager = $this->wiredManager(new PluginEvents());
        $api = new PluginApi('demo', $manager, '/p', new PluginSettings('/p', []));
        $api->registerCommand('hi', 'Say Hi', static function (): void {});
        $api->addMenuItem('hi', static function (): bool {
            throw new \RuntimeException('broken when');
        });
        $manager->commit($api);

        $when = $manager->menuSpecs()[0]['when'];
        $this->assertNotNull($when);
        $this->assertFalse($when(), 'a throw reads as disabled');
        $this->assertFalse($when(), 'still disabled on re-render');
        $this->assertSame(['Plugin "demo" failed: broken when'], $this->flashes, 'reported exactly once');
    }

    public function testWrappedCommandHandlersCatchAndFlash(): void
    {
        $manager = $this->wiredManager(new PluginEvents());
        $api = new PluginApi('demo', $manager, '/p', new PluginSettings('/p', []));
        $ran = false;
        $api->registerCommand('ok', 'Ok', function () use (&$ran): void {
            $ran = true;
        });
        $api->registerCommand('boom', 'Boom', static function (): void {
            throw new \RuntimeException('kaboom');
        });
        $manager->commit($api);

        $registry = new CommandRegistry();
        $manager->registerCommands($registry);
        $registry->run('demo:ok');
        $this->assertTrue($ran);

        $registry->run('demo:boom');
        $this->assertSame(['Plugin "demo" failed: kaboom'], $this->flashes, 'the throw became a flash, not a crash');
    }

    public function testWrappedHooksCatchAndUnwiredFailuresBecomeWarnings(): void
    {
        $events = new PluginEvents();
        $manager = $this->wiredManager($events);
        $api = new PluginApi('demo', $manager, '/p', new PluginSettings('/p', []));
        $seen = [];
        $api->onFileSaved(function (string $p) use (&$seen): void {
            $seen[] = $p;
        });
        $api->onFileOpened(static function (): void {
            throw new \LogicException('bad hook');
        });
        $api->onBufferSwitched(function (string $p) use (&$seen): void {
            $seen[] = "switch:{$p}";
        });
        $manager->commit($api);

        $events->onBufferSaved(new BufferSaved($this->buffer('/a')));
        $events->onBufferOpened(new BufferOpened($this->buffer('/a')));
        $events->onBufferSwitched(new BufferSwitched($this->buffer('/a')));
        $this->assertSame(['/a', 'switch:/a'], $seen);
        $this->assertSame(['Plugin "demo" failed: bad hook'], $this->flashes);

        // Before the runtime exists, failures collect as boot warnings instead.
        $cold = new PluginManager(new PluginEvents(), new PluginSettings('/p', []));
        $cold->reportFailure('demo', new \RuntimeException('early'));
        $this->assertSame(['Plugin "demo" failed: early'], $cold->warnings());
    }
}
