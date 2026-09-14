<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Plugin\Runtime;

use PHPUnit\Framework\TestCase;
use Porta\Config\CommandRegistry;
use Porta\Config\ConfigWarning;
use Porta\Config\KeyDispatch;
use Porta\Editor\ActiveBufferTracker;
use Porta\Editor\Buffer;
use Porta\Editor\BufferManager;
use Porta\Event\ActiveBufferChanged;
use Porta\Event\BufferOpened;
use Porta\Event\BufferSaved;
use Porta\Event\BufferSwitched;
use Porta\I18n\Translator;
use Porta\Plugin\Api\PluginApi;
use Porta\Plugin\Api\PluginException;
use Porta\Plugin\Runtime\PluginManager;
use Porta\Plugin\Runtime\PluginMenuSpec;
use Porta\Plugin\Runtime\PluginRuntime;
use Porta\Plugin\Runtime\PluginSettings;
use Porta\Support\Clipboard;
use Porta\Tests\Support\FakePluginLifecycle;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\RecordingChangeMarkSink;
use Porta\Tests\Support\RecordingDiagnosticsSink;
use Porta\Tests\Support\RecordingFileOpener;
use Porta\Tests\Support\TestDockTools;
use Porta\Tests\Support\TestTui;
use Porta\Tree\FileStatuses;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\FileTreeWidget;
use Porta\Widget\StatusBarWidget;
use Porta\Workbench\Menu\MenuFileContext;
use Porta\Workbench\Notifier;
use Porta\Workbench\View\ViewRegistry;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class PluginManagerTest extends TestCase
{
    private function buffer(string $path): Buffer
    {
        return new BufferManager()->open($path, '');
    }

    private StatusBarWidget $status;

    private function wiredManager(EventDispatcher $events): PluginManager
    {
        $manager = new PluginManager($events, new PluginSettings('/p', []));
        $tui = TestTui::create(120, 24);
        $this->status = new StatusBarWidget();
        $manager->wireRuntime(
            new PluginRuntime(
                new Notifier($this->status, $tui),
                new Translator('en'),
                new CodeEditorWidget(),
                new RecordingFileOpener(),
                $tui,
                new RecordingDiagnosticsSink(),
                new CodeEditorWidget()->decorations(),
                new RecordingChangeMarkSink(),
                new FileStatuses(),
                new BufferManager(),
                TestDockTools::controller(),
                TestDockTools::replacer(),
                new CodeEditorWidget(),
                new MenuFileContext(),
                new ViewRegistry(),
                new Clipboard($tui),
                new StatusBarWidget(),
                new CodeEditorWidget(),
                new FakePluginLifecycle(),
                new FileTreeWidget(\sys_get_temp_dir()),
            ),
        );

        return $manager;
    }

    public function testCommitAggregatesCommandsKeysAndMenuSpecs(): void
    {
        $manager = new PluginManager(new EventDispatcher(), new PluginSettings('/p', []));
        $api = new PluginApi('demo', $manager, '/p', new PluginSettings('/p', []));
        $api->registerCommand('hello', 'Say Hello', static function (): void {}, ['ctrl+alt+y']);
        $api->registerCommand('quiet', 'Quiet One', static function (): void {});
        $api->addMenuItem('hello');
        $manager->commit($api);

        $this->assertTrue($manager->hasPlugin('demo'));
        $this->assertSame(['demo'], \array_keys(Probe::get($manager, 'names')));
        $this->assertSame(['demo:hello' => ['ctrl+alt+y']], $manager->defaultKeys(), 'keyless commands contribute no defaults');
        $this->assertEquals([new PluginMenuSpec('demo', 'i:demo:hello')], $manager->menuSpecs());

        $registry = new CommandRegistry();
        $manager->registerCommands($registry);
        $this->assertSame('Say Hello', $registry->label('demo:hello'));
        $this->assertContains('demo:quiet', $registry->keyBoundIds(), 'always keyBound so user rebinds dispatch');
        $this->assertContains('Say Hello', \array_column($registry->paletteCommands(), 'label'));
    }

    public function testCommittedViewsSurviveToTheRegistrarAndLeaveOnDeactivate(): void
    {
        // A view registered during activate() has to reach ViewRegistrar, and
        // has to go when its plugin does — a view left behind would keep a
        // removed plugin's workspace takeover reachable by id.
        $manager = new PluginManager(new EventDispatcher(), new PluginSettings('/p', []));
        $api = new PluginApi('demo', $manager, '/p', new PluginSettings('/p', []));
        $factory = static fn (): ?object => null;
        $api->registerView('vcs', $factory);
        $manager->commit($api);

        $views = $manager->contributedViews();
        $this->assertCount(1, $views);
        $this->assertSame(['plugin' => 'demo', 'id' => 'vcs', 'factory' => $factory], $views[0]);

        $manager->deactivate('demo', new CommandRegistry());
        $this->assertSame([], $manager->contributedViews());
    }

    public function testAViewFailureIsReportedThroughThePluginsOwnBarrier(): void
    {
        // The channel ViewRegistrar reports a broken factory on — the same one
        // a broken pane uses, so a plugin author is told the same way whichever
        // of its contributions failed.
        $manager = $this->wiredManager(new EventDispatcher());

        $manager->reportViewFailure('demo', new \RuntimeException('boom'));

        $this->assertStringContainsString('boom', $this->renderedStatus());
    }

    public function testRegisterCommandsIsIncrementalAcrossCommits(): void
    {
        $manager = new PluginManager(new EventDispatcher(), new PluginSettings('/p', []));
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

        $labels = \array_column($registry->paletteCommands(), 'label');
        $this->assertSame(1, \count(\array_keys($labels, 'Command A', true)), 'no palette duplicate from re-registration');
        $this->assertSame(1, \count(\array_keys($labels, 'Command B', true)));
    }

    public function testDeactivateUndoesEverythingAndAllowsAReinstall(): void
    {
        $events = new EventDispatcher();
        $settings = new PluginSettings('/p', []);
        $manager = new PluginManager($events, $settings);
        $registry = new CommandRegistry();
        $registry->add('core:stay', static function (): void {}, keyBound: KeyDispatch::Bound, label: 'Core');

        $api = new PluginApi('demo', $manager, '/p', $settings);
        $api->registerSetting('binary', 'Binary', 'text', '');
        $api->registerCommand('hi', 'Say Hi', static function (): void {}, ['ctrl+alt+y']);
        $api->addMenuItem('hi');
        $hookRan = 0;
        $api->onFileSaved(static function () use (&$hookRan): void {
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
        $this->assertSame(['bystander'], \array_keys(Probe::get($manager, 'names')), 'the other plugin is untouched');
        $this->assertContains('bystander:stay', $registry->keyBoundIds());
        $this->assertSame([], $manager->menuSpecs());
        $this->assertSame([], $manager->defaultKeys());
        $this->assertNotContains('demo:hi', $registry->keyBoundIds(), 'key dispatch died');
        $this->assertContains('core:stay', $registry->keyBoundIds(), 'core commands survive');
        $this->assertNotContains('Say Hi', \array_column($registry->paletteCommands(), 'label'), 'palette entry died');
        $this->assertSame([], $settings->rows('demo'), 'setting descriptors dropped');
        $events->dispatch(new BufferSaved($this->buffer('/a')));
        $this->assertSame(0, $hookRan, 'hooks unsubscribed');

        // The same name reinstalls live, and the watermark stayed exact.
        $again = new PluginApi('demo', $manager, '/p', $settings);
        $again->registerCommand('hi', 'Say Hi Again', static function (): void {});
        $manager->commit($again);
        $manager->registerCommands($registry);
        $labels = \array_column($registry->paletteCommands(), 'label');
        $this->assertSame(1, \count(\array_keys($labels, 'Say Hi Again', true)));
        $this->assertNotContains('Say Hi', $labels, 'no stale duplicate came back');
    }

    public function testADisposablePluginIsTornDownExactlyOnceOnDeactivate(): void
    {
        $settings = new PluginSettings('/p', []);
        $manager = new PluginManager(new EventDispatcher(), $settings);
        $plugin = $this->disposablePlugin('demo');
        $manager->commit(new PluginApi('demo', $manager, '/p', $settings), $plugin);

        $manager->deactivate('demo', new CommandRegistry());
        $manager->deactivate('demo', new CommandRegistry()); // unknown now → no-op

        $this->assertSame(1, $plugin->tornDown, 'teardown runs once, not per call');
    }

    public function testAThrowingTeardownIsReportedAndTheRegistriesStillEmpty(): void
    {
        // The barrier placement is the invariant: a teardown that throws must
        // not leave the plugin's commands or menu entries standing.
        $settings = new PluginSettings('/p', []);
        $manager = new PluginManager(new EventDispatcher(), $settings);
        $registry = new CommandRegistry();
        $api = new PluginApi('demo', $manager, '/p', $settings);
        $api->registerCommand('hi', 'Say Hi', static function (): void {});
        $api->addMenuItem('hi');
        $plugin = new class('demo') extends \Porta\Plugin\Api\AbstractPlugin implements \Porta\Plugin\Api\DisposablePlugin {
            public function activate(PluginApi $api): void {}

            public function deactivate(): void
            {
                throw new \RuntimeException('teardown exploded');
            }
        };
        $manager->commit($api, $plugin);
        $manager->registerCommands($registry);

        $manager->deactivate('demo', $registry);

        $this->assertFalse($manager->hasPlugin('demo'));
        $this->assertNotContains('demo:hi', $registry->keyBoundIds(), 'the registries emptied despite the throw');
        $this->assertSame([], $manager->menuSpecs());
        $this->assertStringContainsString('teardown exploded', $manager->warnings()[0]->params['%message%'] ?? '', 'the throw is reported, not swallowed');
    }

    public function testACommitWithoutAnInstanceDeactivatesUntouched(): void
    {
        // The drain-only callers (tests, incremental drains) pass no instance;
        // deactivation must not trip over the absence.
        $settings = new PluginSettings('/p', []);
        $manager = new PluginManager(new EventDispatcher(), $settings);
        $manager->commit(new PluginApi('demo', $manager, '/p', $settings));

        $manager->deactivate('demo', new CommandRegistry());

        $this->assertFalse($manager->hasPlugin('demo'));
    }

    public function testAReinstallStoresTheFreshInstanceForItsOwnTeardown(): void
    {
        // The shadow-fallback path: deactivate one copy, commit the next. The
        // second deactivate must tear down the SECOND instance, not a stale ref.
        $settings = new PluginSettings('/p', []);
        $manager = new PluginManager(new EventDispatcher(), $settings);
        $first = $this->disposablePlugin('demo');
        $manager->commit(new PluginApi('demo', $manager, '/p', $settings), $first);
        $manager->deactivate('demo', new CommandRegistry());

        $second = $this->disposablePlugin('demo');
        $manager->commit(new PluginApi('demo', $manager, '/p', $settings), $second);
        $manager->deactivate('demo', new CommandRegistry());

        $this->assertSame(1, $first->tornDown);
        $this->assertSame(1, $second->tornDown, 'the fresh instance owns the second teardown');
    }

    /** @return \Porta\Plugin\Api\AbstractPlugin&\Porta\Plugin\Api\DisposablePlugin&object{tornDown: int} */
    private function disposablePlugin(string $name): object
    {
        return new class($name) extends \Porta\Plugin\Api\AbstractPlugin implements \Porta\Plugin\Api\DisposablePlugin {
            public int $tornDown = 0;

            public function activate(PluginApi $api): void {}

            public function deactivate(): void
            {
                ++$this->tornDown;
            }
        };
    }

    public function testLanguageServersResolveWithFirstAvailableWinningTheLanguage(): void
    {
        $manager = new PluginManager(new EventDispatcher(), new PluginSettings('/p', []));

        // one registers a php server whose binary is missing (resolver null).
        $one = new PluginApi('one', $manager, '/p', new PluginSettings('/p', []));
        $one->registerLanguageServer('php', 'one-ls', static fn (): ?array => null);
        $manager->commit($one);

        // two provides an available php server and a typescript server.
        $two = new PluginApi('two', $manager, '/p', new PluginSettings('/p', []));
        $two->registerLanguageServer('php', 'two-ls', static fn (): array => ['/bin/two']);
        $two->registerLanguageServer('typescript', 'ts', static fn (): array => ['/bin/ts', '--stdio']);
        $manager->commit($two);

        // three's php server loses — two already serves php.
        $three = new PluginApi('three', $manager, '/p', new PluginSettings('/p', []));
        $three->registerLanguageServer('php', 'three-ls', static fn (): array => ['/bin/three']);
        $manager->commit($three);

        $this->assertSame(
            [
                'php' => ['command' => ['/bin/two'], 'name' => 'two-ls'],
                'typescript' => ['command' => ['/bin/ts', '--stdio'], 'name' => 'ts'],
            ],
            $manager->languageServers(),
            'first available wins; a null resolver yields to the next provider',
        );
        $this->assertTrue($manager->providesLanguageServer('one'), 'a provider even with a missing binary');
        $this->assertTrue($manager->providesLanguageServer('two'));
        $this->assertFalse($manager->providesLanguageServer('nobody'));
    }

    public function testLanguageServerInitializationOptionsResolveLazilyIntoTheEntry(): void
    {
        $settings = new PluginSettings('/p', []);
        $manager = new PluginManager(new EventDispatcher(), $settings);

        $trust = false;
        $sf = new PluginApi('sf', $manager, '/p', $settings);
        $sf->registerLanguageServer('twig', 'sf-ls', static fn (): array => ['/bin/sf'], static function () use (&$trust): array {
            return ['workspaceTrust' => $trust];
        });
        $sf->registerLanguageServer('yaml', 'sf-ls', static fn (): array => ['/bin/sf'], static fn (): array => []);
        $manager->commit($sf);

        $this->assertSame(
            [
                'twig' => ['command' => ['/bin/sf'], 'name' => 'sf-ls', 'initializationOptions' => ['workspaceTrust' => false]],
                'yaml' => ['command' => ['/bin/sf'], 'name' => 'sf-ls'],
            ],
            $manager->languageServers(),
            'options land in the entry; an empty options array sends nothing',
        );

        $trust = true;
        $this->assertSame(
            ['workspaceTrust' => true],
            $manager->languageServers()['twig']['initializationOptions'],
            'the closure re-resolves on every call, so a settings edit reaches the next reconfigure',
        );
    }

    public function testAThrowingInitializationOptionsClosureLeavesTheLanguageUnserved(): void
    {
        $settings = new PluginSettings('/p', []);
        $manager = new PluginManager(new EventDispatcher(), $settings);

        $bad = new PluginApi('bad', $manager, '/p', $settings);
        $bad->registerLanguageServer('twig', 'bad-ls', static fn (): array => ['/bin/bad'], static fn (): array => throw new \RuntimeException('boom'));
        $manager->commit($bad);

        $this->assertSame([], $manager->languageServers());
        $this->assertNotEmpty($manager->warnings(), 'the failure is reported like a throwing command resolver');
    }

    public function testNonEncodableInitializationOptionsAreAReportedFailureNotARenderFault(): void
    {
        // The session pool json-encodes options into its session signature on
        // the render path — a value json cannot carry must die here as one
        // reported plugin failure, not recur as a render fault every frame.
        $settings = new PluginSettings('/p', []);
        $manager = new PluginManager(new EventDispatcher(), $settings);

        $bad = new PluginApi('bad', $manager, '/p', $settings);
        $bad->registerLanguageServer('twig', 'bad-ls', static fn (): array => ['/bin/bad'], static fn (): array => ['timeout' => \INF]);
        $manager->commit($bad);

        $this->assertSame([], $manager->languageServers(), 'the language stays unserved rather than carrying poison');
        $this->assertNotEmpty($manager->warnings());
    }

    public function testLanguageServersReportAThrowingResolverAndDeactivateDropsProviders(): void
    {
        $events = new EventDispatcher();
        $settings = new PluginSettings('/p', []);
        $manager = new PluginManager($events, $settings);
        $registry = new CommandRegistry();

        $boom = new PluginApi('boom', $manager, '/p', $settings);
        $boom->registerLanguageServer('php', 'boom-ls', static fn (): array => throw new \RuntimeException('bad binary'));
        $manager->commit($boom);
        $this->assertSame([], $manager->languageServers(), 'a throwing resolver leaves the language unserved');
        $this->assertNotEmpty($manager->warnings(), 'and reports a boot warning');

        $ok = new PluginApi('ok', $manager, '/p', $settings);
        $ok->registerLanguageServer('php', 'ok-ls', static fn (): array => ['/bin/ok']);
        $manager->commit($ok);
        $this->assertArrayHasKey('php', $manager->languageServers());

        $manager->deactivate('ok', $registry);
        $this->assertFalse($manager->providesLanguageServer('ok'), 'a deactivated provider drops its server');
        $this->assertArrayNotHasKey('php', $manager->languageServers());
    }

    public function testInstallOfferReportsToolAndLiveAvailabilityAndDropsOnDeactivate(): void
    {
        $settings = new PluginSettings('/p', []);
        $manager = new PluginManager(new EventDispatcher(), $settings);
        $registry = new CommandRegistry();

        $available = false;
        $plugin = new PluginApi('linter', $manager, '/p', $settings);
        $plugin->registerInstaller(
            'phpstan',
            static function () use (&$available): bool {
                return $available;
            },
            [['composer', 'phpstan/phpstan']],
        );
        $manager->commit($plugin);

        $this->assertNull($manager->installOffer('other'), 'a plugin that declared none has no offer');

        $offer = $manager->installOffer('linter');
        $this->assertNotNull($offer);
        $this->assertSame('phpstan', $offer['tool']);
        $this->assertSame([['composer', 'phpstan/phpstan']], $offer['via']);
        $this->assertFalse($offer['available'], 'the predicate is evaluated live');

        $available = true;
        $this->assertTrue($manager->installOffer('linter')['available'], 'flipping the predicate is reflected');

        $manager->deactivate('linter', $registry);
        $this->assertNull($manager->installOffer('linter'), 'a deactivated plugin drops its offer');
    }

    public function testAThrowingMenuWhenClosureReadsDisabledAndReportsOnce(): void
    {
        $manager = $this->wiredManager(new EventDispatcher());
        $api = new PluginApi('demo', $manager, '/p', new PluginSettings('/p', []));
        $api->registerCommand('hi', 'Say Hi', static function (): void {});
        $api->addMenuItem('hi', static function (): bool {
            throw new \RuntimeException('broken when');
        });
        $manager->commit($api);

        $when = $manager->menuSpecs()[0]->when;
        $this->assertNotNull($when);
        $this->assertFalse($when(), 'a throw reads as disabled');
        $this->assertFalse($when(), 'still disabled on re-render');
        $this->assertStringContainsString('Plugin "demo" failed: broken when', $this->renderedStatus(), 'reported as a flash');
    }

    public function testWrappedCommandHandlersCatchAndFlash(): void
    {
        $manager = $this->wiredManager(new EventDispatcher());
        $api = new PluginApi('demo', $manager, '/p', new PluginSettings('/p', []));
        $ran = false;
        $api->registerCommand('ok', 'Ok', static function () use (&$ran): void {
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
        $events = new EventDispatcher();
        $manager = $this->wiredManager($events);
        $api = new PluginApi('demo', $manager, '/p', new PluginSettings('/p', []));
        $seen = [];
        $api->onFileSaved(static function (string $p) use (&$seen): void {
            $seen[] = $p;
        });
        $api->onFileOpened(static function (): void {
            throw new \LogicException('bad hook');
        });
        $api->onBufferSwitched(static function (string $p) use (&$seen): void {
            $seen[] = "switch:{$p}";
        });
        $manager->commit($api);

        $events->dispatch(new BufferSaved($this->buffer('/a')));
        $events->dispatch(new BufferOpened($this->buffer('/a')));
        // onBufferSwitched listens to the DEDUPED stream, so the tracker that
        // derives it has to be on the bus — the same wiring the container does.
        $events->addListener(BufferSwitched::class, new ActiveBufferTracker($events)->onBufferSwitched(...), -100);
        $events->dispatch(new BufferSwitched($this->buffer('/a')));
        $this->assertSame(['/a', 'switch:/a'], $seen);
        $this->assertStringContainsString('Plugin "demo" failed: bad hook', $this->renderedStatus());

        // Before the runtime exists, failures collect as boot warnings instead.
        $cold = new PluginManager(new EventDispatcher(), new PluginSettings('/p', []));
        $cold->reportFailure('demo', new \RuntimeException('early'));
        $this->assertEquals([new ConfigWarning('Plugin "%name%" failed: %message%', ['%name%' => 'demo', '%message%' => 'early'])], $cold->warnings());
    }

    private function renderedStatus(): string
    {
        return \Symfony\Component\Tui\Ansi\AnsiUtils::stripAnsiCodes(\implode(' ', $this->status->render(new \Symfony\Component\Tui\Render\RenderContext(120, 1))));
    }

    public function testDeactivatingOnePluginLeavesAnothersListenerOnTheSameEventAlive(): void
    {
        // removeListener() matches by identity, so the manager has to hand back
        // the exact wrapped instances it registered — not "everything on this
        // event", which would silence the innocent plugin beside it.
        $events = new EventDispatcher();
        $manager = $this->wiredManager($events);
        $seen = [];
        foreach (['one', 'two'] as $name) {
            $api = new PluginApi($name, $manager, '/p', new PluginSettings('/p', []));
            $api->onFileSaved(static function (string $path) use (&$seen, $name): void {
                $seen[] = $name;
            });
            $manager->commit($api);
        }

        $manager->deactivate('one', new CommandRegistry());
        $events->dispatch(new BufferSaved($this->buffer('/a')));

        $this->assertSame(['two'], $seen);
    }

    public function testAnEnableDisableEnableCycleNeitherStrandsAListenerNorDoubleFires(): void
    {
        $events = new EventDispatcher();
        $manager = $this->wiredManager($events);
        $calls = 0;
        $subscribe = static function (PluginManager $manager) use (&$calls): void {
            $api = new PluginApi('demo', $manager, '/p', new PluginSettings('/p', []));
            $api->onFileSaved(static function () use (&$calls): void {
                ++$calls;
            });
            $manager->commit($api);
        };

        $subscribe($manager);
        $manager->deactivate('demo', new CommandRegistry());
        $subscribe($manager);
        $events->dispatch(new BufferSaved($this->buffer('/a')));

        $this->assertSame(1, $calls, 'one live registration, not two and not none');
    }

    public function testOnRefusesAnEventThatIsNotObservable(): void
    {
        // BufferSaved carries a mutable Buffer, so it is deliberately not part
        // of the frozen subset — even though a core adapter listens to it on
        // the plugin's behalf for onFileSaved().
        $manager = $this->wiredManager(new EventDispatcher());
        $api = new PluginApi('demo', $manager, '/p', new PluginSettings('/p', []));

        $this->expectException(PluginException::class);
        $this->expectExceptionMessage('is not a plugin-observable event');
        $api->on(BufferSaved::class, static fn () => null);
    }

    public function testOnSubscribesToAnObservableEvent(): void
    {
        $events = new EventDispatcher();
        $manager = $this->wiredManager($events);
        $api = new PluginApi('demo', $manager, '/p', new PluginSettings('/p', []));
        $seen = null;
        $api->on(ActiveBufferChanged::class, static function (ActiveBufferChanged $event) use (&$seen): void {
            $seen = $event->path;
        });
        $manager->commit($api);

        $events->dispatch(new ActiveBufferChanged('/a'));

        $this->assertSame('/a', $seen, 'the listener receives the event OBJECT, not a string');
    }
}
