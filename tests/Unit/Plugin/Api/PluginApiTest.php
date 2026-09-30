<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Plugin\Api;

use PHPUnit\Framework\TestCase;
use Porta\Config\ConfigFiles;
use Porta\Config\ConfigScope;
use Porta\Config\IdeConfig;
use Porta\Editor\Buffer;
use Porta\Editor\BufferManager;
use Porta\Editor\BufferSaver;
use Porta\Editor\ChangeMarkKind;
use Porta\Editor\DiagnosticSeverity;
use Porta\Editor\Document;
use Porta\Event\ActiveBufferChanged;
use Porta\Event\BufferOpened;
use Porta\Event\BufferReloaded;
use Porta\Event\BufferSaved;
use Porta\Event\BufferSaveRequested;
use Porta\Event\GitRemoteCompleted;
use Porta\Event\ThemeChanged;
use Porta\Event\WorkingTreeChanged;
use Porta\Highlight\DarkTerminalTheme;
use Porta\I18n\Translator;
use Porta\Overlay\State\ToastCenter;
use Porta\Overlay\State\ToastSeverity;
use Porta\Plugin\Api\PluginApi;
use Porta\Plugin\Api\PluginException;
use Porta\Plugin\Api\ToolEffect;
use Porta\Plugin\Runtime\PluginManager;
use Porta\Plugin\Runtime\PluginRuntime;
use Porta\Plugin\Runtime\PluginSettings;
use Porta\Run\RunController;
use Porta\Settings\SettingsScope;
use Porta\Support\Clipboard;
use Porta\Support\ProjectPaths;
use Porta\Tests\Support\EditorState;
use Porta\Tests\Support\FakePluginLifecycle;
use Porta\Tests\Support\FakeScheduler;
use Porta\Tests\Support\Presence;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\RecordingChangeMarkSink;
use Porta\Tests\Support\RecordingDiagnosticsSink;
use Porta\Tests\Support\RecordingFileOpener;
use Porta\Tests\Support\StatusReads;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\TestBottomDock;
use Porta\Tests\Support\TestDockTools;
use Porta\Tests\Support\TestLspQuery;
use Porta\Tests\Support\TestRunController;
use Porta\Tests\Support\TestTui;
use Porta\Tree\FileStatuses;
use Porta\Ui\ClickIntent;
use Porta\Ui\Markup\Markup;
use Porta\Ui\Theme\Theme;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\DebugPanelWidget;
use Porta\Widget\FileTreeWidget;
use Porta\Widget\ResultsPanelWidget;
use Porta\Widget\StatusBarWidget;
use Porta\Widget\TerminalPanelWidget;
use Porta\Workbench\Dock\DockToolController;
use Porta\Workbench\Dock\DockToolTab;
use Porta\Workbench\Dock\ToolRun;
use Porta\Workbench\Menu\MenuFileContext;
use Porta\Workbench\Menu\MenuSurface;
use Porta\Workbench\Menu\ViewMenuSource;
use Porta\Workbench\Notifier;
use Porta\Workbench\View\ContributedView;
use Porta\Workbench\View\ViewPointer;
use Porta\Workbench\View\ViewRegistry;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\FocusableInterface;

final class PluginApiTest extends TestCase
{
    /**
     * A scope pinned to Project. SettingsScope defaults to Global for a
     * project with no `settings` block, and Global is the suite's SHARED
     * fixture home — a plugin setting persisted there leaks into every later
     * test in the run.
     */
    private static function projectScope(string $rootDir): SettingsScope
    {
        $scope = new SettingsScope(ConfigFiles::for($rootDir));
        $scope->set(ConfigScope::Project);

        return $scope;
    }

    use StatusReads;

    private RecordingChangeMarkSink $changeMarks;
    private FileStatuses $fileStatuses;
    private CodeEditorWidget $editor;
    private StatusBarWidget $status;
    private RecordingFileOpener $files;
    private RecordingDiagnosticsSink $diagnostics;
    private BufferManager $buffers;
    private ViewRegistry $views;
    private EventDispatcher $events;
    private RunController $runs;
    private FakePluginLifecycle $lifecycle;

    private function api(Presence $wired = Presence::With): PluginApi
    {
        $manager = new PluginManager(new EventDispatcher(), new PluginSettings('/p', [], null, self::projectScope('/p')));
        $this->editor = new CodeEditorWidget();
        $this->status = new StatusBarWidget();
        $this->files = new RecordingFileOpener();
        $this->diagnostics = new RecordingDiagnosticsSink();
        $this->buffers = new BufferManager();
        if (Presence::With === $wired) {
            $tui = TestTui::create(100, 24);
            $manager->wireRuntime(
                new PluginRuntime(
                    new Notifier($this->status, $tui),
                    new Translator('en'),
                    $this->editor,
                    $this->files,
                    $tui,
                    $this->diagnostics,
                    $this->editor->decorations,
                    $this->changeMarks = new RecordingChangeMarkSink(),
                    $this->fileStatuses = new FileStatuses(),
                    $this->buffers,
                    TestDockTools::controller(),
                    TestDockTools::replacer($this->buffers),
                    $this->editor,
                    new MenuFileContext(),
                    $this->views = new ViewRegistry(),
                    new Clipboard($tui),
                    new StatusBarWidget(),
                    $this->editor,
                    $this->lifecycle = new FakePluginLifecycle(),
                    new FileTreeWidget(\sys_get_temp_dir()),
                    TestLspQuery::create(),
                    new BufferSaver($this->buffers, $this->events = new EventDispatcher()),
                    $this->runs = TestRunController::create(tui: $tui),
                ),
            );
        }

        return new PluginApi('demo', $manager, '/p', new PluginSettings('/p', [], null, self::projectScope('/p')));
    }

    public function testRegistrationBuffersNamespacedCommandsAndValidatesIds(): void
    {
        $api = $this->api(wired: Presence::Without);
        $this->assertSame('/p', $api->projectRoot(), 'static config, available during activate()');
        $api->registerCommand('hello', 'Say Hello', static function (): void {}, ['f8']);
        $api->onFileSaved(static function (): void {});

        $this->assertSame('demo:hello', $api->bufferedCommands()[0]['id']);
        $this->assertSame(['f8'], $api->bufferedCommands()[0]['keys']);
        $this->assertSame(BufferSaved::class, $api->bufferedHooks()[0][0]);
        $this->assertSame('demo', $api->pluginName());

        $api->addMenuItem('hello');
        $this->assertSame([['id' => 'demo:hello', 'when' => null, 'surface' => MenuSurface::Plugins]], $api->bufferedMenuItems(), 'the trailing Plugins menu is the default surface');

        try {
            $api->registerCommand('hello', 'Again', static function (): void {});
            $this->fail('duplicate id must throw');
        } catch (PluginException $e) {
            $this->assertStringContainsString('Duplicate command id', $e->getMessage());
        }
        try {
            $api->registerCommand('bad:name', 'Bad', static function (): void {});
            $this->fail('invalid id must throw');
        } catch (PluginException $e) {
            $this->assertStringContainsString('Invalid command id', $e->getMessage());
        }
        try {
            $api->addMenuItem('nope');
            $this->fail('menu item for an unregistered command must throw');
        } catch (PluginException $e) {
            $this->assertStringContainsString('register it first', $e->getMessage());
        }
    }

    public function testRegisterViewBuffersAndValidatesTheId(): void
    {
        $api = $this->api(wired: Presence::Without);
        $factory = static fn (): ?object => null;

        $api->registerView('vcs', $factory);

        $buffered = $api->bufferedViews();
        $this->assertCount(1, $buffered);
        $this->assertSame('demo', $buffered[0]['plugin']);
        $this->assertSame('vcs', $buffered[0]['id'], 'the id is NOT namespaced: a view is reached by the id it claims');
        $this->assertSame($factory, $buffered[0]['factory']);

        try {
            $api->registerView('bad id', $factory);
            $this->fail('an invalid view id must throw');
        } catch (PluginException $error) {
            $this->assertStringContainsString('Invalid view id', $error->getMessage());
        }
    }

    public function testTheViewAndClipboardActionsReachTheRuntime(): void
    {
        $api = $this->api();
        $view = new class implements ContributedView {
            public bool $open = false;

            public function viewId(): string
            {
                return 'vcs';
            }

            public function ownsMainArea(): bool
            {
                return true;
            }

            public function isOpen(): bool
            {
                return $this->open;
            }

            public function open(): void
            {
                $this->open = true;
            }

            public function close(): void
            {
                $this->open = false;
            }

            public function toggle(): void
            {
                $this->open = !$this->open;
            }

            public function focusTarget(): AbstractWidget&FocusableInterface
            {
                return new CodeEditorWidget();
            }

            public function pointer(): ?ViewPointer
            {
                return null;
            }

            public function contextMenus(): ?ViewMenuSource
            {
                return null;
            }

            public function cycleFocus(int $direction): bool
            {
                return false;
            }
        };
        $this->views->register($view);

        $api->openView('vcs');
        $this->assertTrue($view->isOpen(), 'a view opens by id, without the caller holding it');

        $api->closeView('vcs');
        $this->assertFalse($view->isOpen());

        // toggleView is not openView-or-closeView spelled shorter: the plugin
        // does not know which it wants, and finding out any other way means
        // keeping its own controller alive to read one bool.
        $api->toggleView('vcs');
        $this->assertTrue($view->isOpen(), 'closed → open');
        $api->toggleView('vcs');
        $this->assertFalse($view->isOpen(), 'and back, from the same call');

        // Clipboard has no readable state here; that it reaches the runtime at
        // all is the contract — the alternative was a view with no route to it.
        $api->copyToClipboard('deadbeef');
        $this->addToAssertionCount(1);
    }

    public function testRegisterLanguageServerBuffersAndValidatesTheLanguageId(): void
    {
        $api = $this->api(wired: Presence::Without);
        $resolve = static fn (): array => ['/bin/ls'];
        $api->registerLanguageServer('php', 'my-ls', $resolve);

        $buffered = $api->bufferedLanguageServers();
        $this->assertCount(1, $buffered);
        $this->assertSame('demo', $buffered[0]['plugin']);
        $this->assertSame('php', $buffered[0]['languageId']);
        $this->assertSame('my-ls', $buffered[0]['name']);
        $this->assertSame(['/bin/ls'], $buffered[0]['resolve']());
        $this->assertNull($buffered[0]['initOptions'], 'options are opt-in');

        $api->registerLanguageServer('twig', 'my-ls', $resolve, static fn (): array => ['environment' => 'dev']);
        $options = $api->bufferedLanguageServers()[1]['initOptions'];
        $this->assertNotNull($options);
        $this->assertSame(['environment' => 'dev'], $options());

        try {
            $api->registerLanguageServer('bad:id', 'X', static fn (): ?array => null);
            $this->fail('invalid language id must throw');
        } catch (PluginException $e) {
            $this->assertStringContainsString('Invalid language id', $e->getMessage());
        }
    }

    public function testRegisterInstallerBuffersAndValidatesToolPackageAndManager(): void
    {
        $api = $this->api(wired: Presence::Without);
        $api->registerInstaller('phpantom', static fn (): bool => true, [
            ['brew',  'phpantom-lsp'],
            ['cargo', 'phpantom_lsp'],
        ]);

        $buffered = $api->bufferedInstallers();
        $this->assertCount(1, $buffered);
        $this->assertSame('demo', $buffered[0]['plugin']);
        $this->assertSame('phpantom', $buffered[0]['tool']);
        $this->assertSame(
            [
                ['brew',  'phpantom-lsp'],
                ['cargo', 'phpantom_lsp'],
            ],
            $buffered[0]['via'],
        );
        $this->assertTrue($buffered[0]['available']());
        $this->assertNull($buffered[0]['describe'], 'a describer is optional');

        foreach ([
            ['   ', [['composer', 'x/y']], 'non-empty tool name'],
            ['t', [], 'at least one'],
            ['t', [['pip', 'x']], 'Unknown install manager'],
            ['t', [['composer', '  ']], 'non-empty package name'],
            // A self-install candidate names one of the plugin's OWN
            // commands. Checked here rather than at press time, because
            // CommandRegistry::run() is a no-op for an unknown id — a typo
            // would otherwise ship a button that silently does nothing.
            ['t', [[PluginApi::INSTALL_SELF, 'nope']], 'not a command this plugin registered'],
        ] as [$tool, $via, $needle]) {
            try {
                $api->registerInstaller($tool, static fn (): bool => true, $via);
                $this->fail('invalid installer must throw: '.$needle);
            } catch (PluginException $e) {
                $this->assertStringContainsString($needle, $e->getMessage());
            }
        }
    }

    /**
     * A tool no package manager ships installs itself: the candidate names one
     * of the plugin's own commands, and the settings button runs that.
     *
     * symfony-lsp is the case the kind exists for — its server is a GitHub
     * release, so before this the one plugin that most needed a Settings
     * button was the only one that could not have one.
     */
    public function testAnInstallerCanNameThePluginsOwnCommandAndDescribeWhatIsInstalled(): void
    {
        $api = $this->api(wired: Presence::Without);
        $api->registerCommand('install', 'Install Server', static function (): void {});
        $api->registerInstaller(
            'symfony-lsp',
            static fn (): bool => true,
            [[PluginApi::INSTALL_SELF, 'install']],
            static fn (): string => '0.21.0',
        );

        $buffered = $api->bufferedInstallers();
        $this->assertSame([['self', 'install']], $buffered[0]['via']);
        $this->assertSame('0.21.0', $buffered[0]['describe']());
    }

    public function testRegisterSettingValidatesAndSettingReadsSeededConfig(): void
    {
        $manager = new PluginManager(new EventDispatcher(), new PluginSettings('/p', [], null, self::projectScope('/p')));
        $seeded = new PluginSettings('/p', ['demo' => ['binary' => '/opt/x', 'strict' => 'yes', 'level' => 'nope', 'jobs' => '7']], null, self::projectScope('/p'));
        $api = new PluginApi('demo', $manager, '/p', $seeded);

        // Reads work immediately after registration — i.e. during activate().
        $api->registerSetting('binary', 'Binary', 'text', '');
        $api->registerSetting('strict', 'Strict', 'bool', false);
        $api->registerSetting('level', 'Level', 'choice', 'low', ['low', 'high']);
        $api->registerSetting('jobs', 'Jobs', 'int', 4, min: 1, max: 64, step: 2);
        $this->assertSame('/opt/x', $api->setting('binary'));
        $this->assertTrue($api->setting('strict'), 'coerced to the declared bool kind');
        $this->assertSame('low', $api->setting('level'), 'out-of-options choice falls back to the default');
        $this->assertSame(7, $api->setting('jobs'), 'the raw string coerced to the declared int kind');

        $this->assertCount(4, $api->bufferedSettings());

        foreach ([
            ['bad key', static fn () => $api->registerSetting('bad:key', 'X')],
            ['duplicate', static fn () => $api->registerSetting('binary', 'Again')],
            ['bad kind', static fn () => $api->registerSetting('x', 'X', 'float')],
            ['choice needs options', static fn () => $api->registerSetting('y', 'Y', 'choice', 'a')],
            ['default not in options', static fn () => $api->registerSetting('z', 'Z', 'choice', 'a', ['b'])],
            // A bound on anything but a number would advertise a range nothing
            // keeps: the stepper only clamps ints.
            ['range on a bool', static fn () => $api->registerSetting('r1', 'R', 'bool', false, min: 1)],
            ['range on a text', static fn () => $api->registerSetting('r2', 'R', 'text', '', max: 9)],
            ['step on a choice', static fn () => $api->registerSetting('r3', 'R', 'choice', 'a', ['a'], step: 2)],
            ['inverted range', static fn () => $api->registerSetting('r4', 'R', 'int', 1, min: 9, max: 2)],
            ['unknown read', static fn (): bool|int|string => $api->setting('ghost')],
        ] as [$label, $action]) {
            try {
                $action();
                $this->fail("{$label} must throw");
            } catch (PluginException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testEveryActionMethodThrowsBeforeTheRuntimeIsWired(): void
    {
        $api = $this->api(wired: Presence::Without);
        $api->registerSetting('flag', 'Flag', 'bool', false); // a registered key, so changeSetting reaches the runtime gate
        $api->registerListTool('todo', 'TODO'); // likewise: a registered tool, so showList reaches the gate
        $actions = [
            static fn () => $api->flash('x'),
            $api->activeFile(...),
            $api->documentText(...),
            $api->caretPosition(...),
            static fn () => $api->insertAtCaret('x'),
            static fn () => $api->openFile('/x'),
            static fn () => $api->jumpTo(0),
            static fn () => $api->setDiagnostics('/x', []),
            static fn () => $api->setLineAnnotations([]),
            static fn () => $api->setLineBlocks([]),
            static fn () => $api->setChangeMarks('/x', []),
            static fn () => $api->setCommentMarks('/x', []),
            static fn () => $api->emit(new WorkingTreeChanged()),
            static fn () => $api->openView('git'),
            static fn () => $api->closeView('git'),
            static fn () => $api->copyToClipboard('x'),
            static fn () => $api->changeSetting('flag', true),
            static fn () => $api->showList('todo'),
            static fn () => $api->updateList('todo', []),
            static fn () => $api->pick('t', [], static function (string $v): void {}),
            static fn () => $api->replaceDocument('x'),
            static fn (): string => $api->trans('x'),
            static fn () => $api->prompt('t', '', static function (string $v): void {}),
            $api->openBuffers(...),
            $api->runs(...),
            $api->tools(...),
            $api->codeIntelligence(...),
        ];
        foreach ($actions as $i => $action) {
            try {
                $action();
                $this->fail("action #{$i} must throw during activate()");
            } catch (PluginException $e) {
                $this->assertStringContainsString('unavailable during activate()', $e->getMessage());
            }
        }
    }

    public function testChangeSettingValidatesPersistsAndCoerces(): void
    {
        $project = new TempProject();
        $manager = new PluginManager(new EventDispatcher(), $settings = new PluginSettings($project->dir, [], null, self::projectScope($project->dir)));
        $tui = TestTui::create(100, 24);
        $manager->wireRuntime(
            new PluginRuntime(
                new Notifier(new StatusBarWidget(), $tui),
                new Translator('en'),
                new CodeEditorWidget(),
                new RecordingFileOpener(),
                $tui,
                new RecordingDiagnosticsSink(),
                new CodeEditorWidget()->decorations,
                $this->changeMarks = new RecordingChangeMarkSink(),
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
                lifecycle: $lifecycle = new FakePluginLifecycle(),
                tree: new FileTreeWidget(\sys_get_temp_dir()),
                lspQuery: TestLspQuery::create(),
                saver: new BufferSaver(new BufferManager(), new EventDispatcher()),
                runs: TestRunController::create(),
            ),
        );
        $api = new PluginApi('demo', $manager, $project->dir, $settings);

        $api->registerSetting('jobs', 'Jobs', 'int', 4, min: 1, max: 64, step: 2);
        $settings->registerFor('demo', $api->bufferedSettings()); // what PluginManager::commit does

        $api->changeSetting('jobs', '7');

        $this->assertSame(7, $api->setting('jobs'), 'coerced to the declared int kind, live');
        $this->assertSame(7, IdeConfig::load($project->dir)->plugins['demo']['jobs'], 'persisted to .porta.json');
        $this->assertSame(
            [['demo', 'jobs', 7]],
            $lifecycle->pluginSettingChanged,
            'Settings-page parity: the lifecycle hook fires with the coerced value, so hooks run and a provider plugin reconfigures the LSP',
        );

        try {
            $api->changeSetting('ghost', true);
            $this->fail('an unregistered key must throw');
        } catch (PluginException $e) {
            $this->assertStringContainsString('Unknown setting "ghost"', $e->getMessage());
        }
    }

    public function testActionsAgainstALiveEditor(): void
    {
        $api = $this->api();

        $api->flash('hi', 1.5);
        $this->assertStringContainsString('hi', $this->statusRow($this->status));

        // No file open: reads are null, insert is a no-op.
        $this->assertNull($api->activeFile());
        $this->assertNull($api->documentText());
        $this->assertNull($api->caretPosition());
        $api->insertAtCaret('nope');

        $this->editor->showDocument('/p/a.php', new Document("hello\nworld\n"));
        $this->buffers->open('/p/a.php', "hello\nworld\n"); // activeFile() is buffer truth
        $this->editor->getDocument()->setCursor(1, 2);
        $this->assertSame('/p/a.php', $api->activeFile());
        $this->assertSame("hello\nworld\n", $api->documentText());
        $this->assertSame(['line' => 1, 'column' => 2], $api->caretPosition());

        $api->insertAtCaret('XY');
        $this->assertSame("hello\nwoXYrld\n", $this->editor->getDocument()->getText());

        $api->jumpTo(0, 1);
        $this->assertSame(['line' => 0, 'column' => 1], $api->caretPosition());

        $api->openFile('/p/b.php');
        $this->assertSame([['path' => '/p/b.php', 'focus' => true]], $this->files->opened);
    }

    public function testRegisterToolBuffersAndValidates(): void
    {
        $api = $this->api();
        $handler = static fn (array $arguments): string => 'ok';
        $api->registerTool('status', 'The status', ['type' => 'object'], $handler);
        $api->registerTool('push', 'Push', ['type' => 'object'], $handler, ToolEffect::Writes);

        $this->assertSame(
            [
                ['plugin' => 'demo', 'id' => 'status', 'description' => 'The status', 'inputSchema' => ['type' => 'object'], 'handler' => $handler, 'effect' => null],
                ['plugin' => 'demo', 'id' => 'push', 'description' => 'Push', 'inputSchema' => ['type' => 'object'], 'handler' => $handler, 'effect' => ToolEffect::Writes],
            ],
            $api->bufferedTools(),
        );
        $this->assertSame([], $api->tools(), 'buffered, not yet committed');

        $refusals = [
            'Invalid tool id "bad:id"' => static fn () => $api->registerTool('bad:id', 'x', ['type' => 'object'], $handler),
            'Duplicate tool id "status"' => static fn () => $api->registerTool('status', 'x', ['type' => 'object'], $handler),
            'Tool "blank" needs a description' => static fn () => $api->registerTool('blank', ' ', ['type' => 'object'], $handler),
            'Tool "untyped" needs an object input schema' => static fn () => $api->registerTool('untyped', 'x', [], $handler),
            'Tool "listy" needs an object input schema' => static fn () => $api->registerTool('listy', 'x', ['type' => 'array'], $handler),
        ];
        foreach ($refusals as $says => $register) {
            try {
                $register();
                $this->fail($says);
            } catch (PluginException $e) {
                $this->assertStringStartsWith($says, $e->getMessage());
            }
        }
        $this->assertCount(2, $api->bufferedTools(), 'a refused tool leaves nothing behind');
    }

    public function testOpenBuffersAndDiagnosticsReadEveryOpenBuffer(): void
    {
        $api = $this->api();
        $this->assertSame([], $api->openBuffers()->files());
        $this->assertSame([], $api->codeIntelligence()->diagnostics());

        $a = $this->buffers->open('/p/a.php', "one\n");
        $b = $this->buffers->open('/p/b.php', "two\n");
        $this->buffers->openPane('/p/c.png', 'image', null);
        $b->document->insert('!');
        $a->diagnostics = [
            3 => [
                ['severity' => DiagnosticSeverity::Warning, 'message' => 'Unused', 'col' => 4],
                ['severity' => DiagnosticSeverity::Error, 'message' => 'Undefined', 'col' => 0, 'code' => 7, 'source' => 'phpstan'],
            ],
        ];
        $b->diagnostics = [0 => [['severity' => DiagnosticSeverity::Hint, 'message' => 'Hint', 'col' => 1, 'code' => 'rule.x']]];

        $this->assertSame(
            [['path' => '/p/a.php', 'dirty' => false], ['path' => '/p/b.php', 'dirty' => true]],
            $api->openBuffers()->files(),
            'the viewer tab has no text and is left out',
        );
        $this->assertSame("one\n", $api->openBuffers()->text('/p/a.php'));
        $this->assertSame("!two\n", $api->openBuffers()->text('/p/b.php'), 'the unsaved edit, not the disk');
        $this->assertNull($api->openBuffers()->text('/p/c.png'), 'a viewer has no text');
        $this->assertNull($api->openBuffers()->text('/p/nope.php'));

        $this->assertSame(
            [
                ['path' => '/p/a.php', 'line' => 3, 'column' => 4, 'severity' => 2, 'message' => 'Unused'],
                ['path' => '/p/a.php', 'line' => 3, 'column' => 0, 'severity' => 1, 'message' => 'Undefined', 'source' => 'phpstan', 'code' => '7'],
                ['path' => '/p/b.php', 'line' => 0, 'column' => 1, 'severity' => 4, 'message' => 'Hint', 'code' => 'rule.x'],
            ],
            $api->codeIntelligence()->diagnostics(),
        );
        $this->assertSame(['/p/b.php'], \array_column($api->codeIntelligence()->diagnostics('/p/b.php'), 'path'));
        $this->assertSame([], $api->codeIntelligence()->diagnostics('/p/nope.php'));
    }

    public function testTheCodeIntelligenceVerbsAskTheRuntimesLanguageServers(): void
    {
        // The runtime's query serves no language here, so each verb's answer
        // is its refusal — which is enough to show it reached the query.
        $api = $this->api();
        $said = [];
        $keep = static function (mixed $answer, ?string $error) use (&$said): void {
            $said[] = [$answer, $error];
        };

        $code = $api->codeIntelligence();
        $code->definition('/p/a.php', 0, 0, $keep);
        $code->references('/p/a.php', 0, 0, $keep);
        $code->hover('/p/a.php', 0, 0, $keep);
        $code->symbols('A', $keep);

        $this->assertSame(
            [
                [[], 'No language server is configured for a.php'],
                [[], 'No language server is configured for a.php'],
                [null, 'No language server is configured for a.php'],
                [[], 'No language server is running — open a file of the project first'],
            ],
            $said,
        );
    }

    public function testSavingAnOpenBufferAsksForTheUsersSaveAndSaysWhenItWillNot(): void
    {
        $api = $this->api();
        $asked = [];
        $this->events->addListener(BufferSaveRequested::class, function (BufferSaveRequested $event) use (&$asked): void {
            $asked[] = $event->path;
            $this->buffers->get($event->path)?->document->markSaved(); // what the real save leaves behind
        });
        $clean = $this->buffers->open('/p/clean.php', "<?php\n");
        $edited = $this->buffers->open('/p/edited.php', "<?php\n");
        $edited->document->insert('// x');
        $moved = $this->buffers->open('/p/moved.php', "<?php\n");
        $moved->document->insert('// y');
        $moved->diskChangedUnderEdit = true;
        $this->buffers->openPane('/p/c.png', 'image', null);
        $buffers = $api->openBuffers();

        $this->assertNull($buffers->save('/p/clean.php'), 'nothing to save is not a failure');
        $this->assertNull($buffers->save('/p/edited.php'));
        $this->assertFalse($edited->isDirty());
        $this->assertSame('it is not open in the editor', $buffers->save('/p/nope.php'));
        $this->assertSame('it is not a text file', $buffers->save('/p/c.png'));
        $this->assertStringStartsWith('it changed on disk since it was opened', (string) $buffers->save('/p/moved.php'));
        $this->assertSame(['/p/edited.php'], $asked, 'only the edited buffer was saved; the moved one never reached the save');
        $this->assertFalse($clean->document->isDirty());

        $this->events->removeListener(BufferSaveRequested::class, $this->events->getListeners(BufferSaveRequested::class)[0]);
        $moved->diskChangedUnderEdit = false;
        $this->assertSame('it could not be written', $buffers->save('/p/moved.php'), 'the save ran and the buffer is still modified');
    }

    public function testRelaunchLanguageServerIsAnActionThatNamesThePluginToTheLifecycle(): void
    {
        try {
            $this->api(wired: Presence::Without)->relaunchLanguageServer();
            $this->fail('calling it during activate() must throw');
        } catch (PluginException $e) {
            $this->assertStringContainsString('unavailable during activate()', $e->getMessage());
        }

        $this->api()->relaunchLanguageServer();

        $this->assertSame(['demo'], $this->lifecycle->serverBinaryReplaced, 'the lifecycle decides whether this plugin serves a language');
    }

    public function testRunsListTheMenusTasksAndReadWhatARunPrinted(): void
    {
        $api = $this->api();
        $runs = $api->runs();

        $this->assertSame([], $runs->configurations(), 'no file, no composer.json, no Makefile under /p');
        $this->assertNull($runs->output('nothing'));
    }

    public function testSetChangeMarksNormalisesAndReachesTheSink(): void
    {
        $api = $this->api();

        $api->setChangeMarks('/p/a.php', [
            0 => 'added',
            1 => 'modified',
            2 => 'deleted',
            3 => 'exploded', // not a kind core has a Role for
            -1 => 'added', // clamped
        ]);

        $published = $this->changeMarks->published;
        $this->assertCount(1, $published);
        $this->assertSame('/p/a.php', $published[0]['path']);
        $this->assertSame(
            [0 => ChangeMarkKind::Added, 1 => ChangeMarkKind::Modified, 2 => ChangeMarkKind::Deleted],
            $published[0]['marks'],
            'an unknown kind is dropped at the boundary rather than reaching the renderer',
        );
    }

    public function testSetCommentMarksClampsAndReachesTheSinkUnderThePluginsName(): void
    {
        $api = $this->api();

        $api->setCommentMarks('/p/a.php', [2 => 'alice: rename', -1 => 'clamped']);

        $this->assertSame(
            [['path' => '/p/a.php', 'marks' => [2 => 'alice: rename', 0 => 'clamped'], 'owner' => 'demo']],
            $this->changeMarks->comments,
        );
    }

    public function testSetFileStatusesNormalisesAndReachesTheSink(): void
    {
        $api = $this->api();

        $api->setFileStatuses([
            '/p/a.php' => 'added',
            '/p/b.php' => 'modified',
            '/p/c.php' => 'deleted',
            '/p/d.php' => 'exploded', // not a kind core has a Role for
            '' => 'added', // no file to mark
        ]);

        $this->assertSame(
            [
                '/p/a.php' => ChangeMarkKind::Added,
                '/p/b.php' => ChangeMarkKind::Modified,
                '/p/c.php' => ChangeMarkKind::Deleted,
            ],
            $this->fileStatuses->statuses,
            'an unknown kind and a pathless entry are both dropped at the boundary',
        );
    }

    /** The map replaces whole: a file leaving it is how "back to unmodified" is said. */
    public function testSetFileStatusesReplacesTheWholeMap(): void
    {
        $api = $this->api();

        $api->setFileStatuses(['/p/a.php' => 'modified']);
        $api->setFileStatuses(['/p/b.php' => 'added']);

        $this->assertSame(['/p/b.php' => ChangeMarkKind::Added], $this->fileStatuses->statuses);
    }

    public function testHidingTheChangeMarkGutterDropsWhatWasShowingInIt(): void
    {
        // The strip and the marks are two questions — "which lines changed?" is
        // the plugin's, "is there a column for them?" is the user's — and
        // hiding has to answer both, or the marks come back the moment the
        // strip does. The setting behind this exists to remove the empty
        // one-column indent a plugin that publishes nothing would leave.
        $api = $this->api();
        $this->editor->decorations->setVcsMarks([0 => ChangeMarkKind::Added]);

        $api->showChangeMarkGutter(false);

        $this->assertFalse($this->editor->display->vcsGutter, 'the strip is hidden');
        $this->assertSame([], $this->editor->decorations->vcsMarks, 'and it took the stale marks with it');
    }

    public function testShowingTheChangeMarkGutterIsTheDefaultAndIsIdempotent(): void
    {
        // On at boot, which is why the plugin only ever calls this to turn it
        // OFF — and calling it twice must not be a second render request or a
        // second clear.
        $api = $this->api();

        $api->showChangeMarkGutter(true);
        $this->editor->decorations->setVcsMarks([0 => ChangeMarkKind::Added]);
        $api->showChangeMarkGutter(true);

        $this->assertTrue($this->editor->display->vcsGutter);
        $this->assertSame([0 => ChangeMarkKind::Added], $this->editor->decorations->vcsMarks, 'turning on what is already on clears nothing');
    }

    public function testSetLineAnnotationsReachesTheEditorAndClearsWithAnEmptyMap(): void
    {
        $api = $this->api();

        $api->setLineAnnotations([0 => 'abc123 alice 2026-01-01']);
        $this->assertSame(
            [0 => 'abc123 alice 2026-01-01'],
            $this->editor->decorations->lineAnnotations,
            'the annotations reached the active editor',
        );

        $api->setLineAnnotations([]);
        $this->assertSame([], $this->editor->decorations->lineAnnotations, 'an empty map clears them');
    }

    public function testSetLineBlocksWritesUnderThePluginsNameAndLeavesAnotherOwnersRows(): void
    {
        $api = $this->api();
        $this->editor->decorations->setLineBlocks([1 => ['theirs']], 'other');

        $api->setLineBlocks([1 => ['mine', 'more']]);
        $this->assertSame(['theirs', 'mine', 'more'], $this->editor->decorations->lineBlocksAt(1));

        $api->setLineBlocks([]);
        $this->assertSame(['theirs'], $this->editor->decorations->lineBlocksAt(1), 'clearing its own leaves the other plugin\'s');
    }

    public function testSetLineAnnotationsWritesUnderThePluginsNameAndLeavesAnotherOwnersNotes(): void
    {
        $api = $this->api();
        $this->editor->decorations->setLineAnnotations([0 => 'theirs'], 'other');

        $api->setLineAnnotations([0 => 'mine']);
        $this->assertSame([0 => 'theirs  · mine'], $this->editor->decorations->lineAnnotations);

        $api->setLineAnnotations([]);
        $this->assertSame([0 => 'theirs'], $this->editor->decorations->lineAnnotations, 'clearing its own leaves the other plugin\'s');
    }

    public function testSetDiagnosticsNormalizesIntoTheByLineShape(): void
    {
        $api = $this->api();

        $api->setDiagnostics('/p/a.php', [
            ['line' => 2, 'message' => 'boom'],
            ['line' => 2, 'message' => 'warn', 'severity' => 2, 'column' => 4],
            ['line' => -1, 'message' => 'clamped'],
        ]);

        $this->assertSame('file:///p/a.php', $this->diagnostics->published[0]['uri'], 'the path travels as the buffer URI');
        $this->assertSame(
            [
                2 => [
                    ['severity' => DiagnosticSeverity::Error, 'message' => 'boom', 'col' => 0, 'endCol' => 0],
                    ['severity' => DiagnosticSeverity::Warning, 'message' => 'warn', 'col' => 4, 'endCol' => 4],
                ],
                0 => [['severity' => DiagnosticSeverity::Error, 'message' => 'clamped', 'col' => 0, 'endCol' => 0]],
            ],
            $this->diagnostics->published[0]['byLine'],
        );

        $api->setDiagnostics('/p/a.php', []); // empty list clears
        $this->assertSame([], $this->diagnostics->published[1]['byLine']);
    }

    public function testTheRULEThatFiredTravelsAsItsOwnFieldOrNotAtAll(): void
    {
        // Its own field so the Problems panel can put it at the END of the row.
        // Absent when the tool named no rule: an empty `code` there would show
        // as a blank handle after every message.
        $api = $this->api();

        $api->setDiagnostics('/p/a.php', [
            ['line' => 0, 'message' => 'boom', 'code' => 'no-empty-catch-clause'],
            ['line' => 1, 'message' => 'no rule'],
            ['line' => 2, 'message' => 'blank rule', 'code' => ''],
        ]);

        $this->assertSame(
            [
                0 => [['severity' => DiagnosticSeverity::Error, 'message' => 'boom', 'col' => 0, 'endCol' => 0, 'code' => 'no-empty-catch-clause']],
                1 => [['severity' => DiagnosticSeverity::Error, 'message' => 'no rule', 'col' => 0, 'endCol' => 0]],
                2 => [['severity' => DiagnosticSeverity::Error, 'message' => 'blank rule', 'col' => 0, 'endCol' => 0]],
            ],
            $this->diagnostics->published[0]['byLine'],
        );
    }

    public function testPublishingToAListToolTheresNoRegistrationForIsRefused(): void
    {
        // The id is the plugin's own bare one and gets namespaced on the way
        // out, so a typo here can only ever be this plugin's — saying so beats
        // a tab that silently never appears.
        $api = $this->api();

        $this->expectException(PluginException::class);
        $this->expectExceptionMessageMatches('/Unknown list tool "ghost" — registerListTool\(\) it first/');
        $api->showList('ghost');
    }

    public function testAListToolPublishesAndRefreshesThroughTheRuntime(): void
    {
        // The whole path a plugin's tool takes: register, commit, publish by
        // bare id, and land in the dock under the namespaced one.
        $manager = new PluginManager(new EventDispatcher(), new PluginSettings('/p', [], null, self::projectScope('/p')));
        $tui = TestTui::create(100, 24);
        $dock = TestBottomDock::create($tui, new TerminalPanelWidget(), new ResultsPanelWidget(), new DebugPanelWidget());
        $manager->wireRuntime(new PluginRuntime(
            new Notifier(new StatusBarWidget(), $tui),
            new Translator('en'),
            new CodeEditorWidget(),
            new RecordingFileOpener(),
            $tui,
            new RecordingDiagnosticsSink(),
            new CodeEditorWidget()->decorations,
            $this->changeMarks = new RecordingChangeMarkSink(),
            new FileStatuses(),
            new BufferManager(),
            new DockToolController($manager, $dock, new ProjectPaths('/p')), // the manager IS the source
            TestDockTools::replacer(),
            new CodeEditorWidget(),
            new MenuFileContext(),
            new ViewRegistry(),
            new Clipboard($tui),
            new StatusBarWidget(),
            new CodeEditorWidget(),
            new FakePluginLifecycle(),
            new FileTreeWidget(\sys_get_temp_dir()),
            TestLspQuery::create(),
            new BufferSaver(new BufferManager(), new EventDispatcher()),
            TestRunController::create(),
        ));
        $api = new PluginApi('demo', $manager, '/p', new PluginSettings('/p', [], null, self::projectScope('/p')));
        $api->registerListTool('todo', 'TODO');
        $manager->commit($api);

        $api->showList('todo', [['path' => 'a.php', 'line' => 0, 'text' => 'first']], ToolRun::Running);
        $tab = $dock->findTool('demo:todo');
        $this->assertInstanceOf(DockToolTab::class, $tab, 'the bare id was namespaced on the way out');
        $this->assertTrue($tab->searching);

        $api->updateList('todo', [['path' => 'a.php', 'line' => 0, 'text' => 'second']]);
        $this->assertSame('second', $tab->rows[0]['text']);
        $this->assertFalse($tab->searching);
    }

    public function testAPluginSeesTheFilesSelectedInTheTree(): void
    {
        // The half a plugin cannot get to on its own: the tree is core's, and
        // a command that acts on a pair has to know which pair.
        $project = new TempProject(['a.php' => "<?php\n", 'b.php' => "<?php\n"]);
        $manager = new PluginManager(new EventDispatcher(), new PluginSettings($project->dir, [], null, self::projectScope($project->dir)));
        $tui = TestTui::create(100, 24);
        $dock = TestBottomDock::create($tui, new TerminalPanelWidget(), new ResultsPanelWidget(), new DebugPanelWidget());
        $tree = new FileTreeWidget($project->dir);
        $manager->wireRuntime(new PluginRuntime(
            new Notifier(new StatusBarWidget(), $tui),
            new Translator('en'),
            new CodeEditorWidget(),
            new RecordingFileOpener(),
            $tui,
            new RecordingDiagnosticsSink(),
            new CodeEditorWidget()->decorations,
            new RecordingChangeMarkSink(),
            new FileStatuses(),
            new BufferManager(),
            new DockToolController($manager, $dock, new ProjectPaths($project->dir)),
            TestDockTools::replacer(),
            new CodeEditorWidget(),
            new MenuFileContext(),
            new ViewRegistry(),
            new Clipboard($tui),
            new StatusBarWidget(),
            new CodeEditorWidget(),
            new FakePluginLifecycle(),
            $tree,
            TestLspQuery::create(),
            new BufferSaver(new BufferManager(), new EventDispatcher()),
            TestRunController::create(),
        ));
        $api = new PluginApi('demo', $manager, $project->dir, new PluginSettings($project->dir, [], null, self::projectScope($project->dir)));

        $this->assertSame(['a.php'], \array_map(basename(...), $api->selectedFiles()), 'the tree opens with its cursor row selected');

        $tree->clickRow(2, ClickIntent::Add);

        $this->assertSame(['a.php', 'b.php'], \array_map(basename(...), $api->selectedFiles()));
    }

    public function testAPluginMayAskTheUserSomething(): void
    {
        // Before this, flash() told and nothing asked — a plugin could not put
        // a question on screen at all.
        $api = $this->api();
        $picked = null;
        $typed = null;

        $api->pick('Pick a target', ['a' => 'Alpha', 'b' => 'Beta'], static function (string $value) use (&$picked): void {
            $picked = $value;
        });
        $this->assertTrue(EditorState::pickerOpen($this->editor), 'the list overlay is up');

        /** @var array{items: list<array{label: string, hint: string, value: string}>, onPick: \Closure} $overlay */
        $overlay = Probe::get(Probe::get($this->editor, 'overlayHost'), 'overlay');
        $this->assertSame(
            [
                ['label' => 'Alpha', 'hint' => '', 'value' => 'a'],
                ['label' => 'Beta', 'hint' => '', 'value' => 'b'],
            ],
            $overlay['items'],
            'value => label, in the order given',
        );

        $api->prompt('New name', 'old', static function (string $value) use (&$typed): void {
            $typed = $value;
        });
        $this->assertTrue(EditorState::promptOpen($this->editor), 'and the prompt over it');

        // The callbacks are the plugin's own — core hands them the answer verbatim.
        $overlay['onPick']('b');
        $this->assertSame('b', $picked);
        /** @var array{onSubmit: \Closure} $prompt */
        $prompt = Probe::get(Probe::get($this->editor, 'overlayHost'), 'prompt');
        $prompt['onSubmit']('new');
        $this->assertSame('new', $typed);
    }

    public function testReplacingTheDocumentIsOneUndoStepAndRefusesWhatItCannotEdit(): void
    {
        // The verb behind "apply what my tool produced". The refusals matter as
        // much as the swap: an async tool's subprocess may land when the user
        // has moved to another tab, or to none.
        $api = $this->api();
        $api->replaceDocument("nothing open\n"); // no buffer: silent

        $this->editor->showDocument('/p/a.php', new Document("one\ntwo\n"));
        $this->buffers->open('/p/a.php', "one\ntwo\n");
        $this->buffers->setActive('/p/a.php');
        $this->editor->getDocument()->markSaved();

        $api->replaceDocument("replaced\n");
        $this->assertSame("replaced\n", $this->buffers->active()?->document->getText());

        $this->buffers->active()?->document->undo();
        $this->assertSame("one\ntwo\n", $this->buffers->active()?->document->getText(), 'one Ctrl+Z puts it all back');

        // Identical text changes nothing at all — not even the modified flag.
        $this->buffers->active()?->document->markSaved();
        $api->replaceDocument("one\ntwo\n");
        $this->assertFalse($this->buffers->active()?->document->isDirty(), 'a tool that changed nothing does not look like it did');
    }

    public function testTransGoesThroughTheIdesOwnCatalogs(): void
    {
        // Why it exists: a bundled feature that moves into a plugin keeps its
        // translations, because the message id IS the English source string.
        $api = $this->api();

        $this->assertSame('Save File', $api->trans('Save File'), 'a known string round-trips through the en catalog');
        $this->assertSame('Rebound Save File to F12', $api->trans('Rebound %action% to %key%', ['%action%' => 'Save File', '%key%' => 'F12']), 'placeholders are filled');
        $this->assertSame('a plugin string core never heard of', $api->trans('a plugin string core never heard of'), 'and an unknown one passes through');
    }

    public function testSubscribingAfterTheBufferIsDrainedIsARefusalNotANoOp(): void
    {
        // Registrations are collected during activate() and committed
        // atomically. One arriving later used to append to an array nobody
        // reads again — the listener simply never fired, with nothing said.
        $api = $this->api();
        $api->onFileSaved(static fn (): null => null);
        $api->bufferedHooks(); // what commit() does

        $this->expectException(PluginException::class);
        $this->expectExceptionMessageIsOrContains('Listeners must be registered during activate()');
        $api->onFileSaved(static fn (): null => null);
    }

    /** A manually-wired runtime for tests that need a collaborator the shared {@see api()} helper does not expose. */
    private function wiredApi(Notifier $notifier, MenuFileContext $menuFile, BufferManager $buffers, CodeEditorWidget $editor, PluginManager $manager, ?StatusBarWidget $statusBar = null): PluginApi
    {
        $tui = TestTui::create(100, 24);
        $manager->wireRuntime(new PluginRuntime(
            $notifier,
            new Translator('en'),
            $editor,
            new RecordingFileOpener(),
            $tui,
            new RecordingDiagnosticsSink(),
            $editor->decorations,
            new RecordingChangeMarkSink(),
            new FileStatuses(),
            $buffers,
            TestDockTools::controller(),
            TestDockTools::replacer($buffers),
            $editor,
            $menuFile,
            new ViewRegistry(),
            new Clipboard($tui),
            $statusBar ?? new StatusBarWidget(),
            $editor,
            new FakePluginLifecycle(),
            new FileTreeWidget(\sys_get_temp_dir()),
            TestLspQuery::create(),
            new BufferSaver(new BufferManager(), new EventDispatcher()),
            TestRunController::create(),
        ));

        return new PluginApi('demo', $manager, '/p', new PluginSettings('/p', [], null, self::projectScope('/p')));
    }

    public function testSyntaxThemeReturnsTheGivenThemeOrDefaultsToDark(): void
    {
        $manager = new PluginManager(new EventDispatcher(), new PluginSettings('/p', [], null, self::projectScope('/p')));
        $noTheme = new PluginApi('demo', $manager, '/p', new PluginSettings('/p', [], null, self::projectScope('/p')));
        $this->assertInstanceOf(DarkTerminalTheme::class, $noTheme->syntaxTheme(), 'no theme given: falls back to the default');

        $theme = new DarkTerminalTheme();
        $withTheme = new PluginApi('demo', $manager, '/p', new PluginSettings('/p', [], null, self::projectScope('/p')), $theme);
        $this->assertSame($theme, $withTheme->syntaxTheme(), 'a given theme is returned as-is, not wrapped');
    }

    public function testAdoptCommandTakesACoreAdoptableIdBareWithCoreLabelAndValidates(): void
    {
        $api = $this->api(wired: Presence::Without);
        $api->adoptCommand('ide_todo', static function (): void {});

        $this->assertSame('ide_todo', $api->bufferedCommands()[0]['id'], 'bare, not namespaced');
        $this->assertSame('Show TODOs', $api->bufferedCommands()[0]['label'], 'the label comes from the core catalog');
        $this->assertSame([], $api->bufferedCommands()[0]['keys'], 'defaults already live in ActionCatalog::ideDefaults()');

        $api->addMenuItem('ide_todo');
        $this->assertSame('ide_todo', $api->bufferedMenuItems()[0]['id'], 'an adopted id is not namespaced in its menu spec either');

        try {
            $api->adoptCommand('ide_todo', static function (): void {});
            $this->fail('a duplicate adopted id must throw');
        } catch (PluginException $e) {
            $this->assertStringContainsString('Duplicate command id', $e->getMessage());
        }
        try {
            $api->adoptCommand('cursor_up', static function (): void {});
            $this->fail('a non-adoptable core id must throw');
        } catch (PluginException $e) {
            $this->assertStringContainsString('not adoptable', $e->getMessage());
        }
    }

    public function testRegisterHighlighterDefaultsToPluginNameValidatesAndSupportsBothLanguageShapes(): void
    {
        $api = $this->api(wired: Presence::Without);
        $highlight = static function (string $content, string $language, \Closure $deliver): void {};

        $api->registerHighlighter('Demo', ['php', 'JS'], $highlight);
        $buffered = $api->bufferedHighlighters();
        $this->assertSame('demo', $buffered[0]['id'], 'no id given: defaults to the plugin name');
        $this->assertSame(['php', 'JS'], $buffered[0]['languages'](), 'an array is wrapped in a closure returning it verbatim');

        $closureLangs = static fn (): array => ['python'];
        $api->registerHighlighter('Demo2', $closureLangs, $highlight, id: 'demo2');
        $this->assertSame($closureLangs, $api->bufferedHighlighters()[1]['languages'], 'a closure is passed through, not re-wrapped');

        try {
            $api->registerHighlighter('Bad', ['x'], $highlight, id: 'bad id');
            $this->fail('an invalid highlighter id must throw');
        } catch (PluginException $e) {
            $this->assertStringContainsString('Invalid highlighter id', $e->getMessage());
        }
        try {
            $api->registerHighlighter('Empty', [], $highlight);
            $this->fail('an empty language list must throw');
        } catch (PluginException $e) {
            $this->assertStringContainsString('at least one language', $e->getMessage());
        }
    }

    public function testRegisterViewerValidatesAndTranslatesAnArrayClaimIntoACaseInsensitivePredicate(): void
    {
        $api = $this->api(wired: Presence::Without);
        $claimsFn = static fn (string $path): bool => true;

        $api->registerViewer('img', ['PNG', 'jpg']);
        $buffered = $api->bufferedViewers();
        $this->assertTrue($buffered[0]['claims']('a.PNG'), 'matches case-insensitively despite the declared case');
        $this->assertTrue($buffered[0]['claims']('a.jpg'));
        $this->assertFalse($buffered[0]['claims']('a.gif'));

        $api->registerViewer('closurey', $claimsFn);
        $this->assertSame($claimsFn, $api->bufferedViewers()[1]['claims'], 'a closure predicate is passed through, not wrapped');

        try {
            $api->registerViewer('bad id', ['x']);
            $this->fail('an invalid viewer id must throw');
        } catch (PluginException $e) {
            $this->assertStringContainsString('Invalid viewer id', $e->getMessage());
        }
        try {
            $api->registerViewer('empty', []);
            $this->fail('an empty claims list must throw');
        } catch (PluginException $e) {
            $this->assertStringContainsString('at least one file extension', $e->getMessage());
        }
    }

    public function testRegisterPaneValidatesTheId(): void
    {
        $api = $this->api(wired: Presence::Without);
        $pane = static fn (): ?object => null;
        $load = static fn (string $path): mixed => null;

        $api->registerPane('graph', $pane, $load);
        $this->assertSame('graph', $api->bufferedPanes()[0]['id']);

        try {
            $api->registerPane('bad id', $pane, $load);
            $this->fail('an invalid pane id must throw');
        } catch (PluginException $e) {
            $this->assertStringContainsString('Invalid pane id', $e->getMessage());
        }
    }

    public function testRegisterListToolValidatesIdDuplicateAndTitle(): void
    {
        $api = $this->api(wired: Presence::Without);
        $api->registerListTool('todo', 'TODO');

        try {
            $api->registerListTool('bad id', 'X');
            $this->fail('an invalid list tool id must throw');
        } catch (PluginException $e) {
            $this->assertStringContainsString('Invalid list tool id', $e->getMessage());
        }
        try {
            $api->registerListTool('todo', 'Again');
            $this->fail('a duplicate list tool id must throw');
        } catch (PluginException $e) {
            $this->assertStringContainsString('Duplicate list tool id', $e->getMessage());
        }
        try {
            $api->registerListTool('other', '   ');
            $this->fail('a whitespace-only title must throw');
        } catch (PluginException $e) {
            $this->assertStringContainsString('needs a title', $e->getMessage());
        }
    }

    public function testRegisterDebugAdapterBuffersAndValidatesTheLanguageId(): void
    {
        $api = $this->api(wired: Presence::Without);
        $resolve = static fn (): array => ['/bin/dap'];
        $api->registerDebugAdapter('php', $resolve);

        $buffered = $api->bufferedDebugAdapters();
        $this->assertSame('php', $buffered[0]['languageId']);
        $this->assertSame(['/bin/dap'], $buffered[0]['resolve']());

        try {
            $api->registerDebugAdapter('bad:id', static fn (): ?array => null);
            $this->fail('an invalid language id must throw');
        } catch (PluginException $e) {
            $this->assertStringContainsString('Invalid language id', $e->getMessage());
        }
    }

    public function testRegisterMenuBuffersAContributionAndRequiresATitle(): void
    {
        $api = $this->api(wired: Presence::Without);
        $visible = static fn (): bool => true;

        $api->registerMenu('Tools', 'View', ['i:demo:hello'], $visible, 'vcs', ['i:demo:hello']);

        $contribution = $api->bufferedMenus()[0]['contribution'];
        $this->assertSame('Tools', $contribution->title);
        $this->assertSame('View', $contribution->after);
        $this->assertSame(['i:demo:hello'], $contribution->items);
        $this->assertSame($visible, $contribution->visible);
        $this->assertSame('vcs', $contribution->viewId);
        $this->assertSame(['i:demo:hello'], $contribution->viewSafe);

        try {
            $api->registerMenu('', 'View', []);
            $this->fail('an empty title must throw');
        } catch (PluginException $e) {
            $this->assertStringContainsString('needs a title', $e->getMessage());
        }
    }

    public function testRefreshStatusSegmentsInvalidatesTheBar(): void
    {
        $tui = TestTui::create(100, 24);
        $statusBar = new StatusBarWidget();
        $manager = new PluginManager(new EventDispatcher(), new PluginSettings('/p', [], null, self::projectScope('/p')));
        $api = $this->wiredApi(new Notifier(new StatusBarWidget(), $tui), new MenuFileContext(), new BufferManager(), new CodeEditorWidget(), $manager, $statusBar);
        $before = $statusBar->getRenderRevision();

        $api->refreshStatusSegments();

        $this->assertGreaterThan($before, $statusBar->getRenderRevision(), 'the cached bar line is dropped so the next frame re-pulls segments');
    }

    public function testRegisterActionsValidatesEachIdAndRejectsACoreCollision(): void
    {
        $api = $this->api(wired: Presence::Without);
        $api->registerActions(['zoom_in' => 'Zoom In', 'zoom_out' => 'Zoom Out']);

        $buffered = $api->bufferedActions();
        $this->assertSame(['zoom_in', 'zoom_out'], \array_column($buffered, 'id'));
        $this->assertSame(['Zoom In', 'Zoom Out'], \array_column($buffered, 'label'));

        try {
            $api->registerActions(['bad id' => 'X']);
            $this->fail('an invalid action id must throw');
        } catch (PluginException $e) {
            $this->assertStringContainsString('Invalid action id', $e->getMessage());
        }
        try {
            $api->registerActions(['cursor_up' => 'X']);
            $this->fail('a core action id must throw');
        } catch (PluginException $e) {
            $this->assertStringContainsString('is a core action', $e->getMessage());
        }
    }

    public function testRegisterTokenSpansValidatesAndSupportsBothLanguageShapes(): void
    {
        $api = $this->api(wired: Presence::Without);
        $classify = static fn (string $content, string $language): ?array => null;

        $api->registerTokenSpans(['php'], $classify);
        $buffered = $api->bufferedTokenSpans();
        $this->assertSame(['php'], $buffered[0]['languages']());
        $this->assertSame($classify, $buffered[0]['classify']);

        $closureLangs = static fn (): array => ['*'];
        $api->registerTokenSpans($closureLangs, $classify);
        $this->assertSame($closureLangs, $api->bufferedTokenSpans()[1]['languages'], 'a closure is passed through, not re-wrapped');

        try {
            $api->registerTokenSpans([], $classify);
            $this->fail('an empty language list must throw');
        } catch (PluginException $e) {
            $this->assertStringContainsString('at least one language', $e->getMessage());
        }
    }

    public function testContextFilePrefersTheRightClickedPathOverTheActiveFile(): void
    {
        $manager = new PluginManager(new EventDispatcher(), new PluginSettings('/p', [], null, self::projectScope('/p')));
        $editor = new CodeEditorWidget();
        $buffers = new BufferManager();
        $menuFile = new MenuFileContext();
        $tui = TestTui::create(100, 24);
        $api = $this->wiredApi(new Notifier(new StatusBarWidget(), $tui), $menuFile, $buffers, $editor, $manager);

        $this->assertNull($api->contextFile(), 'nothing open at all');

        $editor->showDocument('/p/a.php', new Document("x\n"));
        $buffers->open('/p/a.php', "x\n");
        $this->assertSame('/p/a.php', $api->contextFile(), 'falls back to the active file outside a context-menu invocation');

        $menuFile->with('/p/other.php', function () use ($api): void {
            $this->assertSame('/p/other.php', $api->contextFile(), 'the right-clicked path wins over the active file');
        });

        $this->assertSame('/p/a.php', $api->contextFile(), 'and reverts to the active file once the invocation ends');
    }

    public function testNotifySendsTheGivenSeverityToTheToastCenter(): void
    {
        $tui = TestTui::create(100, 24);
        $toasts = new ToastCenter(new FakeScheduler(), $tui);
        $notifier = new Notifier(new StatusBarWidget(), $tui, toasts: $toasts);
        $manager = new PluginManager(new EventDispatcher(), new PluginSettings('/p', [], null, self::projectScope('/p')));
        $api = $this->wiredApi($notifier, new MenuFileContext(), new BufferManager(), new CodeEditorWidget(), $manager);

        $api->notify('careful <b>', ToastSeverity::Warn);
        $api->flash(Markup::of('Pushed <info>main</>'));

        $pushed = $toasts->toasts();
        $this->assertCount(2, $pushed);
        $this->assertSame('careful <b>', $pushed[1]->message->plain(), 'a string is shown as written');
        $this->assertSame(ToastSeverity::Warn, $pushed[1]->severity, 'the given severity travels, not always Info');
        $this->assertSame('Pushed <info>main</>', $pushed[0]->message->source, 'a Markup reaches the toast as markup');
    }

    public function testOnRejectsAnEventClassThatIsNotPluginObservable(): void
    {
        $api = $this->api(wired: Presence::Without);

        try {
            $api->on(BufferOpened::class, static function (): void {});
            $this->fail('a non-observable event class must throw');
        } catch (PluginException $e) {
            $this->assertStringContainsString('not a plugin-observable event', $e->getMessage());
        }

        $api->on(WorkingTreeChanged::class, static function (): void {});
        $this->assertSame(WorkingTreeChanged::class, $api->bufferedHooks()[0][0], 'an observable class is accepted verbatim');
    }

    public function testNamedEventHooksMapTheirEventToTheDocumentedListenerArgument(): void
    {
        $api = $this->api(wired: Presence::Without);
        $received = [];

        $api->onFileOpened(static function (string $path) use (&$received): void {
            $received['opened'] = $path;
        });
        $api->onFileReloaded(static function (string $path) use (&$received): void {
            $received['reloaded'] = $path;
        });
        $api->onBufferSwitched(static function (string $path) use (&$received): void {
            $received['switched'] = $path;
        });
        $api->onThemeChanged(static function (string $theme) use (&$received): void {
            $received['theme'] = $theme;
        });

        $byClass = [];
        foreach ($api->bufferedHooks() as [$class, $listener]) {
            $byClass[$class] = $listener;
        }

        $buffer = new Buffer('/p/a.php', new Document("x\n"));
        $byClass[BufferOpened::class](new BufferOpened($buffer));
        $byClass[BufferReloaded::class](new BufferReloaded($buffer));
        $byClass[ActiveBufferChanged::class](new ActiveBufferChanged('/p/b.php'));
        $byClass[ThemeChanged::class](new ThemeChanged(Theme::Dark));

        $this->assertSame(
            [
                'opened' => '/p/a.php',
                'reloaded' => '/p/a.php',
                'switched' => '/p/b.php',
                'theme' => 'dark',
            ],
            $received,
        );
    }

    public function testEmitDispatchesOnTheLiveBusOnceWired(): void
    {
        $dispatcher = new EventDispatcher();
        $manager = new PluginManager($dispatcher, new PluginSettings('/p', [], null, self::projectScope('/p')));
        $tui = TestTui::create(100, 24);
        $api = $this->wiredApi(new Notifier(new StatusBarWidget(), $tui), new MenuFileContext(), new BufferManager(), new CodeEditorWidget(), $manager);

        $received = null;
        $dispatcher->addListener(WorkingTreeChanged::class, static function (WorkingTreeChanged $event) use (&$received): void {
            $received = $event;
        });

        $event = new WorkingTreeChanged();
        $api->emit($event);

        $this->assertSame($event, $received, 'the event reached the dispatcher, not just the not-during-activate() gate');

        // Plugin to plugin, payload intact: git emits this one and review hears it.
        $completed = null;
        $dispatcher->addListener(GitRemoteCompleted::class, static function (GitRemoteCompleted $event) use (&$completed): void {
            $completed = [$event->operation, $event->ok];
        });
        $api->emit(new GitRemoteCompleted('pull', ok: false));

        $this->assertSame(['pull', false], $completed);
    }
}
