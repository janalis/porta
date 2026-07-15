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
use Porta\Tests\Support\RecordingDiagnosticsSink;
use Porta\Tests\Support\RecordingFileOpener;
use Porta\Ui\Notifier;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\StatusBarWidget;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Style\StyleSheet;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

final class PluginManagerTest extends TestCase
{
    private function buffer(string $path): Buffer
    {
        return new BufferManager()->open($path, '');
    }

    private StatusBarWidget $status;

    private function wiredManager(PluginEvents $events): PluginManager
    {
        $manager = new PluginManager($events, new PluginSettings('/p', []));
        $tui = new Tui(terminal: new VirtualTerminal(columns: 120, rows: 24), keybindings: new Keybindings([]), renderer: new Renderer(new StyleSheet()));
        $this->status = new StatusBarWidget();
        $manager->wireRuntime(new PluginRuntime(new Notifier($this->status, $tui), new Translator('en'), new CodeEditorWidget(), new RecordingFileOpener(), $tui, new RecordingDiagnosticsSink()));

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
        $this->assertStringContainsString('Plugin "demo" failed: broken when', $this->renderedStatus(), 'reported as a flash');
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
        $this->assertStringContainsString('Plugin "demo" failed: kaboom', $this->renderedStatus(), 'the throw became a flash, not a crash');
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
        $this->assertStringContainsString('Plugin "demo" failed: bad hook', $this->renderedStatus());

        // Before the runtime exists, failures collect as boot warnings instead.
        $cold = new PluginManager(new PluginEvents(), new PluginSettings('/p', []));
        $cold->reportFailure('demo', new \RuntimeException('early'));
        $this->assertSame(['Plugin "demo" failed: early'], $cold->warnings());
    }

    private function renderedStatus(): string
    {
        return \Symfony\Component\Tui\Ansi\AnsiUtils::stripAnsiCodes(implode(' ', $this->status->render(new \Symfony\Component\Tui\Render\RenderContext(120, 1))));
    }
}
