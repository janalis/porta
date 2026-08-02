<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Plugin\Api;

use PHPUnit\Framework\TestCase;
use Porta\Config\IdeConfig;
use Porta\Editor\BufferManager;
use Porta\Editor\Document;
use Porta\Event\BufferSaved;
use Porta\Event\WorkingTreeChanged;
use Porta\I18n\Translator;
use Porta\Plugin\Api\PluginApi;
use Porta\Plugin\Api\PluginException;
use Porta\Plugin\Runtime\PluginManager;
use Porta\Plugin\Runtime\PluginRuntime;
use Porta\Plugin\Runtime\PluginSettings;
use Porta\Tests\Support\EditorState;
use Porta\Tests\Support\Presence;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\RecordingChangeMarkSink;
use Porta\Tests\Support\RecordingDiagnosticsSink;
use Porta\Tests\Support\RecordingFileOpener;
use Porta\Tests\Support\StatusReads;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\TestBottomDock;
use Porta\Tests\Support\TestDockTools;
use Porta\Tests\Support\TestTui;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\DebugPanelWidget;
use Porta\Widget\ResultsPanelWidget;
use Porta\Widget\StatusBarWidget;
use Porta\Widget\TerminalPanelWidget;
use Porta\Workbench\Dock\DockToolController;
use Porta\Workbench\Dock\ToolRun;
use Porta\Workbench\Menu\MenuFileContext;
use Porta\Workbench\Menu\MenuSurface;
use Porta\Workbench\Notifier;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class PluginApiTest extends TestCase
{
    use StatusReads;

    private RecordingChangeMarkSink $changeMarks;
    private CodeEditorWidget $editor;
    private StatusBarWidget $status;
    private RecordingFileOpener $files;
    private RecordingDiagnosticsSink $diagnostics;
    private BufferManager $buffers;

    private function api(Presence $wired = Presence::With): PluginApi
    {
        $manager = new PluginManager(new EventDispatcher(), new PluginSettings('/p', []));
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
                    $this->editor,
                    $this->changeMarks = new RecordingChangeMarkSink(),
                    $this->buffers,
                    TestDockTools::controller(),
                    TestDockTools::replacer($this->buffers),
                    $this->editor,
                    new MenuFileContext(),
                ),
            );
        }

        return new PluginApi('demo', $manager, '/p', new PluginSettings('/p', []));
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

        foreach ([
            ['   ', [['composer', 'x/y']], 'non-empty tool name'],
            ['t', [], 'at least one'],
            ['t', [['pip', 'x']], 'Unknown install manager'],
            ['t', [['composer', '  ']], 'non-empty package name'],
        ] as [$tool, $via, $needle]) {
            try {
                $api->registerInstaller($tool, static fn (): bool => true, $via);
                $this->fail('invalid installer must throw: '.$needle);
            } catch (PluginException $e) {
                $this->assertStringContainsString($needle, $e->getMessage());
            }
        }
    }

    public function testRegisterSettingValidatesAndSettingReadsSeededConfig(): void
    {
        $manager = new PluginManager(new EventDispatcher(), new PluginSettings('/p', []));
        $seeded = new PluginSettings('/p', ['demo' => ['binary' => '/opt/x', 'strict' => 'yes', 'level' => 'nope', 'jobs' => '7']]);
        $api = new PluginApi('demo', $manager, '/p', $seeded);

        // Reads work immediately after registration — i.e. during activate().
        $api->registerSetting('binary', 'Binary', 'text', '');
        $api->registerSetting('strict', 'Strict', 'bool', false);
        $api->registerSetting('level', 'Level', 'choice', 'low', ['low', 'high']);
        $api->registerSetting('jobs', 'Jobs', 'int', 4);
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
            ['unknown read', static fn () => $api->setting('ghost')],
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
            static fn () => $api->setChangeMarks('/x', []),
            static fn () => $api->emit(new WorkingTreeChanged()),
            static fn () => $api->changeSetting('flag', true),
            static fn () => $api->showList('todo'),
            static fn () => $api->updateList('todo', []),
            static fn () => $api->pick('t', [], static function (string $v): void {}),
            static fn () => $api->replaceDocument('x'),
            static fn () => $api->trans('x'),
            static fn () => $api->prompt('t', '', static function (string $v): void {}),
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
        $manager = new PluginManager(new EventDispatcher(), $settings = new PluginSettings($project->dir, []));
        $tui = TestTui::create(100, 24);
        $manager->wireRuntime(
            new PluginRuntime(
                new Notifier(new StatusBarWidget(), $tui),
                new Translator('en'),
                new CodeEditorWidget(),
                new RecordingFileOpener(),
                $tui,
                new RecordingDiagnosticsSink(),
                new CodeEditorWidget(),
                $this->changeMarks = new RecordingChangeMarkSink(),
                new BufferManager(),
                TestDockTools::controller(),
                TestDockTools::replacer(),
                new CodeEditorWidget(),
                new MenuFileContext(),
            ),
        );
        $api = new PluginApi('demo', $manager, $project->dir, $settings);

        $api->registerSetting('jobs', 'Jobs', 'int', 4);
        $settings->registerFor('demo', $api->bufferedSettings()); // what PluginManager::commit does

        $api->changeSetting('jobs', '7');

        $this->assertSame(7, $api->setting('jobs'), 'coerced to the declared int kind, live');
        $this->assertSame(7, IdeConfig::load($project->dir)->plugins['demo']['jobs'], 'persisted to .porta.json');

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
            [0 => 'added', 1 => 'modified', 2 => 'deleted'],
            $published[0]['marks'],
            'an unknown kind is dropped at the boundary rather than reaching the renderer',
        );
    }

    public function testSetLineAnnotationsReachesTheEditorAndClearsWithAnEmptyMap(): void
    {
        $api = $this->api();

        $api->setLineAnnotations([0 => 'abc123 alice 2026-01-01']);
        $this->assertSame(
            [0 => 'abc123 alice 2026-01-01'],
            Probe::get($this->editor, 'decorations')->lineAnnotations(),
            'the annotations reached the active editor',
        );

        $api->setLineAnnotations([]);
        $this->assertSame([], Probe::get($this->editor, 'decorations')->lineAnnotations(), 'an empty map clears them');
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
                    ['severity' => 1, 'message' => 'boom', 'col' => 0, 'endCol' => 0],
                    ['severity' => 2, 'message' => 'warn', 'col' => 4, 'endCol' => 4],
                ],
                0 => [['severity' => 1, 'message' => 'clamped', 'col' => 0, 'endCol' => 0]],
            ],
            $this->diagnostics->published[0]['byLine'],
        );

        $api->setDiagnostics('/p/a.php', []); // empty list clears
        $this->assertSame([], $this->diagnostics->published[1]['byLine']);
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
        $manager = new PluginManager(new EventDispatcher(), new PluginSettings('/p', []));
        $tui = TestTui::create(100, 24);
        $dock = TestBottomDock::create($tui, new TerminalPanelWidget(), new ResultsPanelWidget(), new DebugPanelWidget());
        $manager->wireRuntime(new PluginRuntime(
            new Notifier(new StatusBarWidget(), $tui),
            new Translator('en'),
            new CodeEditorWidget(),
            new RecordingFileOpener(),
            $tui,
            new RecordingDiagnosticsSink(),
            new CodeEditorWidget(),
            $this->changeMarks = new RecordingChangeMarkSink(),
            new BufferManager(),
            new DockToolController($manager, $dock, '/p'), // the manager IS the source
            TestDockTools::replacer(),
            new CodeEditorWidget(),
            new MenuFileContext(),
        ));
        $api = new PluginApi('demo', $manager, '/p', new PluginSettings('/p', []));
        $api->registerListTool('todo', 'TODO');
        $manager->commit($api);

        $api->showList('todo', [['path' => 'a.php', 'line' => 0, 'text' => 'first']], ToolRun::Running);
        $tab = $dock->findTool('demo:todo');
        $this->assertNotNull($tab, 'the bare id was namespaced on the way out');
        $this->assertTrue($tab->searching);

        $api->updateList('todo', [['path' => 'a.php', 'line' => 0, 'text' => 'second']]);
        $this->assertSame('second', $tab->rows[0]['text']);
        $this->assertFalse($tab->searching);
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
        $api->onFileSaved(static fn () => null);
        $api->bufferedHooks(); // what commit() does

        $this->expectException(PluginException::class);
        $this->expectExceptionMessage('Listeners must be registered during activate()');
        $api->onFileSaved(static fn () => null);
    }
}
