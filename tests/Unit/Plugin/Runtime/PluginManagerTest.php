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
use Porta\Event\ToolsChanged;
use Porta\Plugin\Api\AbstractPlugin;
use Porta\Plugin\Api\DisposablePlugin;
use Porta\Plugin\Api\PluginApi;
use Porta\Plugin\Api\PluginException;
use Porta\Plugin\Api\PortaPlugin;
use Porta\Plugin\Api\ServerNotFound;
use Porta\Plugin\Api\ServerRole;
use Porta\Plugin\Runtime\PluginManager;
use Porta\Plugin\Runtime\PluginMenuSpec;
use Porta\Plugin\Runtime\PluginSettings;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\TestPluginRuntime;
use Porta\Tests\Support\TestTui;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\StatusBarWidget;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;

final class PluginManagerTest extends TestCase
{
    private function buffer(string $path): Buffer
    {
        return new BufferManager()->open($path, content: '');
    }

    private StatusBarWidget $status;

    private function wiredManager(EventDispatcher $events): PluginManager
    {
        $manager = new PluginManager($events, new PluginSettings('/p', []));
        $tui = TestTui::create(120, rows: 24);
        $this->status = new StatusBarWidget();
        $manager->wireRuntime(
            TestPluginRuntime::create(new CodeEditorWidget(), $this->status, $tui),
        );

        return $manager;
    }

    public function testCommitAggregatesCommandsKeysAndMenuSpecs(): void
    {
        $manager = new PluginManager(new EventDispatcher(), new PluginSettings('/p', []));
        $api = $manager->api('demo', rootDir: '/p', settings: new PluginSettings('/p', []));
        $api->registerCommand('hello', label: 'Say Hello', handler: static function (): void {}, keys: ['ctrl+alt+y']);
        $api->registerCommand('quiet', label: 'Quiet One', handler: static function (): void {});
        $api->addMenuItem('hello');
        $manager->commit($api);

        $this->assertTrue($manager->hasPlugin('demo'));
        $this->assertSame(['demo'], \array_keys(Probe::get($manager, property: 'names')));
        $this->assertSame(['demo:hello' => ['ctrl+alt+y']], $manager->defaultKeys, 'keyless commands contribute no defaults');
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
        $api = $manager->api('demo', rootDir: '/p', settings: new PluginSettings('/p', []));
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
        $first = $manager->api('one', rootDir: '/p', settings: new PluginSettings('/p', []));
        $first->registerCommand('a', label: 'Command A', handler: static function (): void {});
        $manager->commit($first);

        $registry = new CommandRegistry();
        $manager->registerCommands($registry);

        // A live install commits a second plugin after boot registration.
        $second = $manager->api('two', rootDir: '/p', settings: new PluginSettings('/p', []));
        $second->registerCommand('b', label: 'Command B', handler: static function (): void {});
        $manager->commit($second);
        $manager->registerCommands($registry);

        $labels = \array_column($registry->paletteCommands(), 'label');
        $this->assertCount(1, \array_keys($labels, 'Command A', true), 'no palette duplicate from re-registration');
        $this->assertCount(1, \array_keys($labels, 'Command B', true));
    }

    public function testDeactivateUndoesEverythingAndAllowsAReinstall(): void
    {
        $events = new EventDispatcher();
        $settings = new PluginSettings('/p', []);
        $manager = new PluginManager($events, $settings);
        $registry = new CommandRegistry();
        $registry->add('core:stay', static function (): void {}, keyBound: KeyDispatch::Bound, label: 'Core');

        $api = $manager->api('demo', rootDir: '/p', settings: $settings);
        $api->registerSetting('binary', label: 'Binary', kind: 'text', default: '');
        $api->registerCommand('hi', label: 'Say Hi', handler: static function (): void {}, keys: ['ctrl+alt+y']);
        $api->addMenuItem('hi');
        $hookRan = 0;
        $api->onFileSaved(static function () use (&$hookRan): void {
            ++$hookRan;
        });
        $manager->commit($api);
        $bystander = $manager->api('bystander', rootDir: '/p', settings: $settings);
        $bystander->registerCommand('stay', label: 'Bystander Stay', handler: static function (): void {});
        $manager->commit($bystander);
        $manager->registerCommands($registry);
        $this->assertContains('demo:hi', $registry->keyBoundIds());
        $this->assertCount(1, $settings->rows('demo'));

        $manager->deactivate('demo', $registry);
        $manager->deactivate('demo', $registry); // unknown now → no-op

        $this->assertFalse($manager->hasPlugin('demo'));
        $this->assertSame(['bystander'], \array_keys(Probe::get($manager, property: 'names')), 'the other plugin is untouched');
        $this->assertContains('bystander:stay', $registry->keyBoundIds());
        $this->assertSame([], $manager->menuSpecs());
        $this->assertSame([], $manager->defaultKeys);
        $this->assertNotContains('demo:hi', $registry->keyBoundIds(), 'key dispatch died');
        $this->assertContains('core:stay', $registry->keyBoundIds(), 'core commands survive');
        $this->assertNotContains('Say Hi', \array_column($registry->paletteCommands(), 'label'), 'palette entry died');
        $this->assertSame([], $settings->rows('demo'), 'setting descriptors dropped');
        $events->dispatch(new BufferSaved($this->buffer('/a')));
        $this->assertSame(0, $hookRan, 'hooks unsubscribed');

        // The same name reinstalls live, and the watermark stayed exact.
        $again = $manager->api('demo', rootDir: '/p', settings: $settings);
        $again->registerCommand('hi', label: 'Say Hi Again', handler: static function (): void {});
        $manager->commit($again);
        $manager->registerCommands($registry);
        $labels = \array_column($registry->paletteCommands(), 'label');
        $this->assertCount(1, \array_keys($labels, 'Say Hi Again', true));
        $this->assertNotContains('Say Hi', $labels, 'no stale duplicate came back');
    }

    public function testToolsCommitInLoadOrderLeaveOnDeactivateAndAnnounceOnlyALiveChange(): void
    {
        $events = new EventDispatcher();
        $announced = 0;
        $events->addListener(ToolsChanged::class, static function () use (&$announced): void {
            ++$announced;
        });
        $manager = new PluginManager($events, new PluginSettings('/p', []));
        $tool = static fn (PluginApi $api, string $id) => $api->registerTool($id, 'Tool '.$id, ['type' => 'object'], static fn (array $arguments): string => $id);

        // Boot: committed before the runtime is wired, so nothing is announced.
        $git = $manager->api('git', rootDir: '/p', settings: new PluginSettings('/p', []));
        $tool($git, id: 'status');
        $manager->commit($git);
        $quiet = $manager->api('quiet', rootDir: '/p', settings: new PluginSettings('/p', []));
        $manager->commit($quiet);
        $this->assertSame(0, $announced, 'nothing could have read the set yet');
        $this->assertSame(['git:status'], \array_map(static fn (array $t): string => $t['plugin'].':'.$t['id'], $manager->tools()));

        // Live: a plugin that offers tools arriving, and one that offers none.
        $manager->wireRuntime(Probe::get($this->wiredManager(new EventDispatcher()), property: 'runtime'));
        $review = $manager->api('review', rootDir: '/p', settings: new PluginSettings('/p', []));
        $tool($review, id: 'threads');
        $tool($review, id: 'reply');
        $manager->commit($review);
        $this->assertSame(1, $announced);
        $manager->commit($manager->api('silent', rootDir: '/p', settings: new PluginSettings('/p', [])));
        $this->assertSame(1, $announced, 'a plugin with no tools changes nothing');
        $this->assertSame(['git:status', 'review:threads', 'review:reply'], \array_map(static fn (array $t): string => $t['plugin'].':'.$t['id'], $manager->tools()));

        $registry = new CommandRegistry();
        $manager->deactivate('git', $registry);
        $this->assertSame(2, $announced);
        $this->assertSame(['review:threads', 'review:reply'], \array_map(static fn (array $t): string => $t['plugin'].':'.$t['id'], $manager->tools()), 'only the leaving plugin\'s tools went');
        $manager->deactivate('quiet', $registry);
        $this->assertSame(2, $announced, 'a plugin with no tools leaving changes nothing');
    }

    /** Deactivated, a plugin's annotations leave the editor with it — another plugin's stay. */
    public function testDeactivateTakesBackWhatThePluginPutOnScreen(): void
    {
        $manager = $this->wiredManager(new EventDispatcher());
        $editor = Probe::get(Probe::get($manager, property: 'runtime'), property: 'editor');
        $blame = $manager->api('blame', rootDir: '/p', settings: new PluginSettings('/p', []));
        $manager->commit($blame);
        $other = $manager->api('other', rootDir: '/p', settings: new PluginSettings('/p', []));
        $manager->commit($other);
        $blame->setLineAnnotations([0 => 'pierre, 2 days ago']);
        $other->setLineAnnotations([1 => 'kept']);

        $manager->deactivate('blame', new CommandRegistry());

        $this->assertSame([1 => 'kept'], $editor->decorations->lineAnnotations);

        // What its own teardown publishes on the way out goes too.
        $late = $manager->api('late', rootDir: '/p', settings: new PluginSettings('/p', []));
        $manager->commit($late, new readonly class($late) implements PortaPlugin, DisposablePlugin {
            public function __construct(
                private PluginApi $api,
            ) {}

            public function name(): string
            {
                return 'late';
            }

            public function activate(PluginApi $api): void {}

            public function deactivate(): void
            {
                $this->api->setLineAnnotations([2 => 'published while leaving']);
            }
        });
        $manager->deactivate('late', new CommandRegistry());
        $this->assertSame([1 => 'kept'], $editor->decorations->lineAnnotations);

        // A retract that fails is reported, and the plugin still leaves.
        $other->setLineAnnotations([1 => 'again']);
        Probe::set($manager, property: 'runtime', value: null);
        $manager->deactivate('other', new CommandRegistry());
        $this->assertFalse($manager->hasPlugin('other'));
        $this->assertNotSame([], $manager->warnings, 'the failure was said');
    }

    public function testADisposablePluginIsTornDownExactlyOnceOnDeactivate(): void
    {
        $settings = new PluginSettings('/p', []);
        $manager = new PluginManager(new EventDispatcher(), $settings);
        $plugin = $this->disposablePlugin('demo');
        $manager->commit($manager->api('demo', rootDir: '/p', settings: $settings), $plugin);

        $manager->deactivate('demo', new CommandRegistry());
        $manager->deactivate('demo', new CommandRegistry()); // unknown now → no-op

        $this->assertSame(1, $plugin->tornDown, 'teardown runs once, not per call');
    }

    /** The IDE closing tears down every disposable plugin — the next project opens in this process, on this loop — each in its barrier. */
    public function testClosingTheIdeTearsDownEveryDisposablePlugin(): void
    {
        $settings = new PluginSettings('/p', []);
        $manager = new PluginManager(new EventDispatcher(), $settings);
        $first = $this->disposablePlugin('first');
        $manager->commit($manager->api('first', rootDir: '/p', settings: $settings), new class('broken') extends AbstractPlugin implements DisposablePlugin {
            public function activate(PluginApi $api): void {}

            public function deactivate(): void
            {
                throw new \RuntimeException('teardown exploded');
            }
        });
        $manager->commit($manager->api('second', rootDir: '/p', settings: $settings), $first);
        $manager->commit($manager->api('plain', rootDir: '/p', settings: $settings), new class('plain') extends AbstractPlugin {
            public function activate(PluginApi $api): void {}
        });

        $manager->disposeAll();

        $this->assertSame(1, $first->tornDown, 'torn down, though the one before it threw');
        $this->assertStringContainsString('teardown exploded', $manager->warnings[0]->params['%message%'] ?? '');
    }

    public function testAThrowingTeardownIsReportedAndTheRegistriesStillEmpty(): void
    {
        // The barrier placement is the invariant: a teardown that throws must
        // not leave the plugin's commands or menu entries standing.
        $settings = new PluginSettings('/p', []);
        $manager = new PluginManager(new EventDispatcher(), $settings);
        $registry = new CommandRegistry();
        $api = $manager->api('demo', rootDir: '/p', settings: $settings);
        $api->registerCommand('hi', label: 'Say Hi', handler: static function (): void {});
        $api->addMenuItem('hi');
        $plugin = new class('demo') extends AbstractPlugin implements DisposablePlugin {
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
        $this->assertStringContainsString('teardown exploded', $manager->warnings[0]->params['%message%'] ?? '', 'the throw is reported, not swallowed');
    }

    public function testACommitWithoutAnInstanceDeactivatesUntouched(): void
    {
        // The drain-only callers (tests, incremental drains) pass no instance;
        // deactivation must not trip over the absence.
        $settings = new PluginSettings('/p', []);
        $manager = new PluginManager(new EventDispatcher(), $settings);
        $manager->commit($manager->api('demo', rootDir: '/p', settings: $settings));

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
        $manager->commit($manager->api('demo', rootDir: '/p', settings: $settings), $first);
        $manager->deactivate('demo', new CommandRegistry());

        $second = $this->disposablePlugin('demo');
        $manager->commit($manager->api('demo', rootDir: '/p', settings: $settings), $second);
        $manager->deactivate('demo', new CommandRegistry());

        $this->assertSame(1, $first->tornDown);
        $this->assertSame(1, $second->tornDown, 'the fresh instance owns the second teardown');
    }

    /** @return \Porta\Plugin\Api\AbstractPlugin&\Porta\Plugin\Api\DisposablePlugin&object{tornDown: int} */
    private function disposablePlugin(string $name): object
    {
        return new class($name) extends AbstractPlugin implements DisposablePlugin {
            public int $tornDown = 0;

            public function activate(PluginApi $api): void {}

            public function deactivate(): void
            {
                ++$this->tornDown;
            }
        };
    }

    public function testAResolverThatThrowsIsAFailureNotAMissingBinary(): void
    {
        $manager = new PluginManager(new EventDispatcher(), new PluginSettings('/p', []));
        $api = $manager->api('broken', rootDir: '/p', settings: new PluginSettings('/p', []));
        $api->registerLanguageServer('php', name: 'broken-ls', resolveCommand: static fn (): array => throw new \RuntimeException('no'));
        $manager->commit($api);

        $this->assertSame([], $manager->missingServers());
    }

    public function testLanguageServersResolveWithFirstAvailableWinningTheLanguage(): void
    {
        $manager = new PluginManager(new EventDispatcher(), new PluginSettings('/p', []));

        // one registers a php server whose binary is missing (resolver null).
        $one = $manager->api('one', rootDir: '/p', settings: new PluginSettings('/p', []));
        $one->registerLanguageServer('php', name: 'one-ls', resolveCommand: static fn (): ServerNotFound => new ServerNotFound());
        $one->registerLanguageServer('typescript', name: 'not-mine', resolveCommand: static fn (): ?array => null);
        $manager->commit($one);

        // two provides an available php server and a typescript server.
        $two = $manager->api('two', rootDir: '/p', settings: new PluginSettings('/p', []));
        $two->registerLanguageServer('php', name: 'two-ls', resolveCommand: static fn (): array => ['/bin/two']);
        $two->registerLanguageServer('typescript', name: 'ts', resolveCommand: static fn (): array => ['/bin/ts', '--stdio']);
        $manager->commit($two);

        // three's php server loses — two already serves php.
        $three = $manager->api('three', rootDir: '/p', settings: new PluginSettings('/p', []));
        $three->registerLanguageServer('php', name: 'three-ls', resolveCommand: static fn (): array => ['/bin/three']);
        $manager->commit($three);

        $this->assertSame(
            [
                'php' => [['command' => ['/bin/two'], 'name' => 'two-ls']],
                'typescript' => [['command' => ['/bin/ts', '--stdio'], 'name' => 'ts']],
            ],
            $manager->languageServers(),
            'first available wins; a null resolver yields to the next provider',
        );
        $this->assertSame(['php' => ['one-ls']], $manager->missingServers(), 'and the one that could not, named');
        $this->assertTrue($manager->providesLanguageServer('one'), 'a provider even with a missing binary');
        $this->assertTrue($manager->providesLanguageServer('two'));
        $this->assertFalse($manager->providesLanguageServer('nobody'));
    }

    public function testLanguageServerInitializationOptionsResolveLazilyIntoTheEntry(): void
    {
        $settings = new PluginSettings('/p', []);
        $manager = new PluginManager(new EventDispatcher(), $settings);

        $trust = false;
        $sf = $manager->api('sf', rootDir: '/p', settings: $settings);
        $sf->registerLanguageServer('twig', name: 'sf-ls', resolveCommand: static fn (): array => ['/bin/sf'], initializationOptions: static function () use (&$trust): array {
            return ['workspaceTrust' => $trust];
        });
        $sf->registerLanguageServer('yaml', name: 'sf-ls', resolveCommand: static fn (): array => ['/bin/sf'], initializationOptions: static fn (): array => []);
        $manager->commit($sf);

        $this->assertSame(
            [
                'twig' => [['command' => ['/bin/sf'], 'name' => 'sf-ls', 'initializationOptions' => ['workspaceTrust' => false]]],
                'yaml' => [['command' => ['/bin/sf'], 'name' => 'sf-ls']],
            ],
            $manager->languageServers(),
            'options land in the entry; an empty options array sends nothing',
        );

        $trust = true;
        $this->assertSame(
            ['workspaceTrust' => true],
            $manager->languageServers()['twig'][0]['initializationOptions'],
            'the closure re-resolves on every call, so a settings edit reaches the next reconfigure',
        );
    }

    public function testAThrowingInitializationOptionsClosureLeavesTheLanguageUnserved(): void
    {
        $settings = new PluginSettings('/p', []);
        $manager = new PluginManager(new EventDispatcher(), $settings);

        $bad = $manager->api('bad', rootDir: '/p', settings: $settings);
        $bad->registerLanguageServer('twig', name: 'bad-ls', resolveCommand: static fn (): array => ['/bin/bad'], initializationOptions: static fn (): array => throw new \RuntimeException('boom'));
        $manager->commit($bad);

        $this->assertSame([], $manager->languageServers());
        $this->assertNotEmpty($manager->warnings, 'the failure is reported like a throwing command resolver');
    }

    public function testNonEncodableInitializationOptionsAreAReportedFailureNotARenderFault(): void
    {
        // The session pool json-encodes options into its session signature on
        // the render path — a value json cannot carry must die here as one
        // reported plugin failure, not recur as a render fault every frame.
        $settings = new PluginSettings('/p', []);
        $manager = new PluginManager(new EventDispatcher(), $settings);

        $bad = $manager->api('bad', rootDir: '/p', settings: $settings);
        $bad->registerLanguageServer('twig', name: 'bad-ls', resolveCommand: static fn (): array => ['/bin/bad'], initializationOptions: static fn (): array => ['timeout' => \INF]);
        $manager->commit($bad);

        $this->assertSame([], $manager->languageServers(), 'the language stays unserved rather than carrying poison');
        $this->assertNotEmpty($manager->warnings);
    }

    public function testLanguageServersReportAThrowingResolverAndDeactivateDropsProviders(): void
    {
        $events = new EventDispatcher();
        $settings = new PluginSettings('/p', []);
        $manager = new PluginManager($events, $settings);
        $registry = new CommandRegistry();

        $boom = $manager->api('boom', rootDir: '/p', settings: $settings);
        $boom->registerLanguageServer('php', name: 'boom-ls', resolveCommand: static fn (): array => throw new \RuntimeException('bad binary'));
        $manager->commit($boom);
        $this->assertSame([], $manager->languageServers(), 'a throwing resolver leaves the language unserved');
        $this->assertNotEmpty($manager->warnings, 'and reports a boot warning');

        $ok = $manager->api('ok', rootDir: '/p', settings: $settings);
        $ok->registerLanguageServer('php', name: 'ok-ls', resolveCommand: static fn (): array => ['/bin/ok']);
        $manager->commit($ok);
        $this->assertArrayHasKey('php', $manager->languageServers());

        $manager->deactivate('ok', $registry);
        $this->assertFalse($manager->providesLanguageServer('ok'), 'a deactivated provider drops its server');
        $this->assertArrayNotHasKey('php', $manager->languageServers());
    }

    public function testRegisteredInstallersAreHandedOverAndDropOnDeactivate(): void
    {
        $settings = new PluginSettings('/p', []);
        $manager = new PluginManager(new EventDispatcher(), $settings);
        $registry = new CommandRegistry();

        $available = false;
        $plugin = $manager->api('linter', rootDir: '/p', settings: $settings);
        $plugin->registerInstaller(
            'phpstan',
            static function () use (&$available): bool {
                return $available;
            },
            [['composer', 'phpstan/phpstan']],
        );
        $manager->commit($plugin);

        $this->assertSame([], $manager->registeredInstallers('other'), 'a plugin that declared none has none');

        $installers = $manager->registeredInstallers('linter');
        $this->assertCount(1, $installers);
        $this->assertSame('phpstan', $installers[0]['tool']);
        $this->assertSame([['composer', 'phpstan/phpstan']], $installers[0]['via']);
        $this->assertFalse($installers[0]['available'](), 'the predicate is handed over, to be asked live');

        $manager->deactivate('linter', $registry);
        $this->assertSame([], $manager->registeredInstallers('linter'), 'a deactivated plugin drops its installer');
    }

    public function testAThrowingMenuWhenClosureReadsDisabledAndReportsOnce(): void
    {
        $manager = $this->wiredManager(new EventDispatcher());
        $api = $manager->api('demo', rootDir: '/p', settings: new PluginSettings('/p', []));
        $api->registerCommand('hi', label: 'Say Hi', handler: static function (): void {});
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
        $api = $manager->api('demo', rootDir: '/p', settings: new PluginSettings('/p', []));
        $ran = false;
        $api->registerCommand('ok', label: 'Ok', handler: static function () use (&$ran): void {
            $ran = true;
        });
        $api->registerCommand('boom', label: 'Boom', handler: static function (): never {
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
        $api = $manager->api('demo', rootDir: '/p', settings: new PluginSettings('/p', []));
        $seen = [];
        $api->onFileSaved(static function (string $p) use (&$seen): void {
            $seen[] = $p;
        });
        $api->onFileOpened(static function (): never {
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
        $this->assertEquals([new ConfigWarning('Plugin "%name%" failed: %message%', ['%name%' => 'demo', '%message%' => 'early'])], $cold->warnings);
    }

    private function renderedStatus(): string
    {
        return AnsiUtils::stripAnsiCodes(\implode(' ', $this->status->render(new RenderContext(120, 1))));
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
            $api = $manager->api($name, rootDir: '/p', settings: new PluginSettings('/p', []));
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
            $api = $manager->api('demo', rootDir: '/p', settings: new PluginSettings('/p', []));
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
        $api = $manager->api('demo', rootDir: '/p', settings: new PluginSettings('/p', []));

        $this->expectException(PluginException::class);
        $this->expectExceptionMessageIsOrContains('is not a plugin-observable event');
        $api->on(BufferSaved::class, static fn (): null => null);
    }

    public function testOnSubscribesToAnObservableEvent(): void
    {
        $events = new EventDispatcher();
        $manager = $this->wiredManager($events);
        $api = $manager->api('demo', rootDir: '/p', settings: new PluginSettings('/p', []));
        $seen = null;
        $api->on(ActiveBufferChanged::class, static function (ActiveBufferChanged $event) use (&$seen): void {
            $seen = $event->path;
        });
        $manager->commit($api);

        $events->dispatch(new ActiveBufferChanged('/a'));

        $this->assertSame('/a', $seen, 'the listener receives the event OBJECT, not a string');
    }

    /**
     * Two plugins claiming one language as its OWN server: the first wins and
     * the second is dropped, as before. Two general servers for one language
     * is double the processes for one answer, and neither is the right one to
     * ask.
     */
    public function testASecondPrimaryClaimIsStillDropped(): void
    {
        $manager = $this->wiredManager(new EventDispatcher());
        $settings = new PluginSettings('/p', []);
        foreach (['one' => '/bin/one', 'two' => '/bin/two'] as $plugin => $binary) {
            $api = $manager->api($plugin, rootDir: '/p', settings: $settings);
            $api->registerLanguageServer('php', $plugin, static fn (): array => [$binary]);
            $manager->commit($api);
        }

        $this->assertSame(
            ['php' => [['command' => ['/bin/one'], 'name' => 'one']]],
            $manager->languageServers(),
        );
    }

    /**
     * A supplementary claim runs BESIDE the language's own server, second in
     * the list.
     *
     * Before this it was dropped outright, and which of the two survived came
     * down to the directory glob being alphabetical — so symfony-lsp lost php
     * to phpantom because of its name.
     */
    public function testASupplementaryClaimRunsBesideThePrimary(): void
    {
        $manager = $this->wiredManager(new EventDispatcher());
        $settings = new PluginSettings('/p', []);
        $beside = $manager->api('symfony-lsp', rootDir: '/p', settings: $settings);
        $beside->registerLanguageServer('php', name: 'symfony-lsp', resolveCommand: static fn (): array => ['/bin/sf'], role: ServerRole::Supplementary);
        $manager->commit($beside);
        $own = $manager->api('phpantom', rootDir: '/p', settings: $settings);
        $own->registerLanguageServer('php', name: 'phpantom', resolveCommand: static fn (): array => ['/bin/phpantom']);
        $manager->commit($own);

        $this->assertSame(
            [
                'php' => [
                    ['command' => ['/bin/phpantom'], 'name' => 'phpantom'],
                    ['command' => ['/bin/sf'], 'name' => 'symfony-lsp'],
                ],
            ],
            $manager->languageServers(),
            'the language\'s own server leads however the plugins were ordered',
        );
    }

    /**
     * A server run elsewhere is the language's own, as a primary is — the
     * first claim wins, and a supplementary one runs beside it — and it is
     * the only kind {@see PluginManager::relayedServers()} names.
     */
    public function testARelayedClaimIsTheLanguagesServerAndTheOnlyOneRelayed(): void
    {
        $manager = $this->wiredManager(new EventDispatcher());
        $settings = new PluginSettings('/p', []);
        $relay = $manager->api('assist', rootDir: '/p', settings: $settings);
        $relay->registerLanguageServer('php', name: 'assist', resolveCommand: static fn (): array => ['/bin/relay'], role: ServerRole::Relayed);
        $manager->commit($relay);
        foreach (['phpantom' => ServerRole::Primary, 'symfony-lsp' => ServerRole::Supplementary] as $plugin => $role) {
            $api = $manager->api($plugin, rootDir: '/p', settings: $settings);
            $api->registerLanguageServer('php', $plugin, static fn (): array => ['/bin/'.$plugin], role: $role);
            $api->registerLanguageServer('typescript', $plugin, static fn (): array => ['/bin/'.$plugin], role: $role);
            $manager->commit($api);
        }

        $this->assertSame(
            ['command' => ['/bin/relay'], 'name' => 'assist'],
            $manager->languageServers()['php'][0] ?? null,
            'the first claim leads; the later primary is dropped',
        );
        $this->assertCount(2, $manager->languageServers()['php'] ?? [], 'the supplementary one beside it');
        $this->assertSame(['php' => [['command' => ['/bin/relay'], 'name' => 'assist']]], $manager->relayedServers());
    }

    /**
     * A supplementary claim with nothing beside it IS the language's server.
     *
     * That is what keeps symfony-lsp serving php on a project with no PHP
     * server enabled — the behaviour the plugin already documented, minus the
     * accident that used to produce it.
     */
    public function testALoneSupplementaryClaimIsPromoted(): void
    {
        $manager = $this->wiredManager(new EventDispatcher());
        $api = $manager->api('symfony-lsp', rootDir: '/p', settings: new PluginSettings('/p', []));
        $api->registerLanguageServer('php', name: 'symfony-lsp', resolveCommand: static fn (): array => ['/bin/sf'], role: ServerRole::Supplementary);
        $manager->commit($api);

        $this->assertSame(
            ['php' => [['command' => ['/bin/sf'], 'name' => 'symfony-lsp']]],
            $manager->languageServers(),
        );
    }

    /**
     * A primary whose binary is missing lets a supplementary one lead, rather
     * than leaving the language unserved beside a server that could have
     * taken it.
     */
    public function testAnUnavailablePrimaryLetsTheSupplementaryOneLead(): void
    {
        $manager = $this->wiredManager(new EventDispatcher());
        $settings = new PluginSettings('/p', []);
        $own = $manager->api('phpantom', rootDir: '/p', settings: $settings);
        $own->registerLanguageServer('php', name: 'phpantom', resolveCommand: static fn (): ?array => null);
        $manager->commit($own);
        $beside = $manager->api('symfony-lsp', rootDir: '/p', settings: $settings);
        $beside->registerLanguageServer('php', name: 'symfony-lsp', resolveCommand: static fn (): array => ['/bin/sf'], role: ServerRole::Supplementary);
        $manager->commit($beside);

        $this->assertSame(
            ['php' => [['command' => ['/bin/sf'], 'name' => 'symfony-lsp']]],
            $manager->languageServers(),
        );
    }

    /**
     * Two providers resolving to one launch are one entry — otherwise the
     * pool would start, feed and ask the same process twice.
     */
    public function testProvidersResolvingToTheSameLaunchCollapse(): void
    {
        $manager = $this->wiredManager(new EventDispatcher());
        $settings = new PluginSettings('/p', []);
        $own = $manager->api('phpantom', rootDir: '/p', settings: $settings);
        $own->registerLanguageServer('php', name: 'phpantom', resolveCommand: static fn (): array => ['/bin/shared']);
        $manager->commit($own);
        $beside = $manager->api('other', rootDir: '/p', settings: $settings);
        $beside->registerLanguageServer('php', name: 'other', resolveCommand: static fn (): array => ['/bin/shared'], role: ServerRole::Supplementary);
        $manager->commit($beside);

        $this->assertSame(
            ['php' => [['command' => ['/bin/shared'], 'name' => 'phpantom']]],
            $manager->languageServers(),
        );
    }

    /** Same argv, different initializationOptions: two servers, not one. */
    public function testTheSameArgvUnderDifferentOptionsStaysTwoServers(): void
    {
        $manager = $this->wiredManager(new EventDispatcher());
        $settings = new PluginSettings('/p', []);
        $own = $manager->api('one', rootDir: '/p', settings: $settings);
        $own->registerLanguageServer('php', name: 'one', resolveCommand: static fn (): array => ['/bin/shared'], initializationOptions: static fn (): array => ['trust' => true]);
        $manager->commit($own);
        $beside = $manager->api('two', rootDir: '/p', settings: $settings);
        $beside->registerLanguageServer(
            'php',
            name: 'two',
            resolveCommand: static fn (): array => ['/bin/shared'],
            initializationOptions: static fn (): array => ['trust' => false],
            role: ServerRole::Supplementary,
        );
        $manager->commit($beside);

        $this->assertCount(2, $manager->languageServers()['php']);
    }
}
