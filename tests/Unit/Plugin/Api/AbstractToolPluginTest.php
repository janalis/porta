<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Plugin\Api;

use PHPUnit\Framework\TestCase;
use Porta\Config\ConfigFiles;
use Porta\Config\ConfigScope;
use Porta\Editor\BufferManager;
use Porta\Editor\BufferSaver;
use Porta\Filesystem\ProjectLocation;
use Porta\I18n\Translator;
use Porta\Plugin\Api\AbstractPlugin;
use Porta\Plugin\Api\AbstractToolPlugin;
use Porta\Plugin\Api\BinarySearch;
use Porta\Plugin\Api\PluginApi;
use Porta\Plugin\Runtime\PluginManager;
use Porta\Plugin\Runtime\PluginRuntime;
use Porta\Plugin\Runtime\PluginSettings;
use Porta\Settings\SettingsScope;
use Porta\Support\Clipboard;
use Porta\Tests\Support\FakePluginLifecycle;
use Porta\Tests\Support\RecordingChangeMarkSink;
use Porta\Tests\Support\RecordingDiagnosticsSink;
use Porta\Tests\Support\RecordingFileOpener;
use Porta\Tests\Support\StatusReads;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\TestDockTools;
use Porta\Tests\Support\TestLspQuery;
use Porta\Tests\Support\TestParticipants;
use Porta\Tests\Support\TestProjectSwitcher;
use Porta\Tests\Support\TestRuns;
use Porta\Tests\Support\TestTui;
use Porta\Tests\Support\TestWorkspaceEditor;
use Porta\Tests\Support\UntrustsProjects;
use Porta\Tree\FileStatuses;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\FileTreeWidget;
use Porta\Widget\StatusBarWidget;
use Porta\Workbench\Menu\MenuFileContext;
use Porta\Workbench\Notifier;
use Porta\Workbench\View\ViewRegistry;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * The shared tool-plugin machinery, driven through a real {@see PluginApi}
 * (the PluginApiTest rig). These lines used to be copied into plugin files,
 * outside every gate; the point of the base class is that they are tested
 * once, here.
 */
final class AbstractToolPluginTest extends TestCase
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
    use UntrustsProjects;

    private StatusBarWidget $status;
    private BufferManager $buffers;
    private CodeEditorWidget $editor;
    private TempProject $project;
    private PluginManager $manager;

    /** A concrete tool plugin exposing the protected surface for assertions. */
    private function plugin(string $name = 'demo', ?string $binaryName = null, BinarySearch $search = BinarySearch::VendorBinThenPath): AbstractToolPlugin
    {
        return new class($name, $binaryName ?? $name, $search) extends AbstractToolPlugin {
            public function activate(PluginApi $api): void
            {
                // PluginApi::setting() refuses unregistered keys, so the base
                // class's `binary` row must exist before binary() reads it —
                // which is exactly what registerBinarySetting() does.
                $this->registerBinarySetting($api, [['composer', 'demo/demo']]);
            }

            public function callBinary(PluginApi $api): ?string
            {
                return $this->binary($api);
            }

            public function callAcquire(PluginApi $api): ?string
            {
                return $this->acquire($api);
            }

            public function callRelease(): void
            {
                $this->release();
            }

            public function callActiveFile(PluginApi $api, string $extension = '.php'): ?string
            {
                return $this->activeFileOrFlash($api, $extension);
            }

            public function callAvailable(PluginApi $api): \Closure
            {
                return $this->binaryAvailable($api);
            }

            public function callApplyFormatted(PluginApi $api, string $file, ?string $before): void
            {
                $this->applyFormatted($api, $file, $before);
            }

            public function callTextBefore(PluginApi $api, string $file): ?string
            {
                return $this->textBefore($api, $file);
            }

            public function callRegisterBinarySetting(PluginApi $api, array $via): void
            {
                $this->registerBinarySetting($api, $via);
            }

            public function callRegisterToggle(PluginApi $api, string $id, string $label, string $key, string $phrase): void
            {
                $this->registerToggle($api, $id, $label, $key, $phrase);
            }

            public function callFlashExit(PluginApi $api, int $code, string $success): void
            {
                $this->flashExit($api, $code, $success);
            }

            public function callFlashCount(PluginApi $api, int $count, string $noun, string $file): void
            {
                $this->flashCount($api, $count, $noun, $file);
            }

            public function callRunAndApply(PluginApi $api, array $arguments, string $success): void
            {
                $this->runAndApply($api, $arguments, $success);
            }
        };
    }

    /** A live api over a temp project, with the runtime wired so action methods work. */
    private function api(string $pluginName = 'demo', string $locale = 'en'): PluginApi
    {
        $this->project ??= new TempProject();
        // ONE settings instance, like PluginLoader wires it — two would read
        // back stale values after changeSetting().
        $settings = new PluginSettings($this->project->dir, [], null, self::projectScope($this->project->dir));
        $manager = new PluginManager(new EventDispatcher(), $settings);
        $this->manager = $manager;
        $this->status = new StatusBarWidget();
        $this->buffers = new BufferManager();
        $this->editor = new CodeEditorWidget();
        $tui = TestTui::create(100, 24);
        $manager->wireRuntime(new PluginRuntime(
            new Notifier($this->status, $tui),
            new Translator($locale),
            $this->editor,
            new RecordingFileOpener(),
            $tui,
            new RecordingDiagnosticsSink(),
            $this->editor->decorations,
            new RecordingChangeMarkSink(),
            new FileStatuses(),
            $this->buffers,
            TestDockTools::controller(),
            TestDockTools::replacer($this->buffers),
            $this->editor,
            new MenuFileContext(),
            new ViewRegistry(),
            new Clipboard($tui),
            new StatusBarWidget(),
            new CodeEditorWidget(),
            new FakePluginLifecycle(),
            new FileTreeWidget(\sys_get_temp_dir()),
            TestLspQuery::create(),
            new BufferSaver(new BufferManager(), new EventDispatcher()),
            TestRuns::create(),
            TestWorkspaceEditor::create(),
            TestParticipants::create(),
            TestProjectSwitcher::create(),
        ));

        return new PluginApi($pluginName, $manager, $this->project->dir, $settings);
    }

    /**
     * The production sequence: activate() buffers the registrations, commit()
     * lands them — only then does PluginSettings know the descriptors, which
     * is what setting() reads through.
     */
    private function activate(AbstractPlugin $plugin, PluginApi $api): void
    {
        $plugin->activate($api);
        $this->manager->commit($api);
    }

    public function testBinaryDiscoveryWalksItsThreeTiers(): void
    {
        $project = new TempProject(['vendor/bin/demo' => "#!/bin/sh\n"]);
        \chmod($project->path('vendor/bin/demo'), permissions: 0o755);
        $this->project = $project;
        $api = $this->api();
        $plugin = $this->plugin();
        $this->activate($plugin, $api);

        // Tier 2: the project-local shim, since no setting is configured.
        $this->assertSame($project->dir.'/vendor/bin/demo', $plugin->callBinary($api));

        // Tier 1: an explicit setting wins over everything, unchecked.
        $api->changeSetting('binary', '/opt/custom/demo');
        $this->assertSame('/opt/custom/demo', $plugin->callBinary($api));

        // Tier 3: no setting, no shim → PATH (nothing named this exists there).
        $api->changeSetting('binary', '');
        $this->assertSame($project->dir.'/vendor/bin/demo', $plugin->callBinary($api), 'clearing the setting falls back');

        $missingApi = $this->api();
        $missing = $this->plugin('demo', 'porta-nonexistent-tool');
        $this->activate($missing, $missingApi);
        $this->assertNull($missing->callBinary($missingApi));
    }

    /** An untrusted project's vendor/bin is the project's code: not run, whatever it is named. */
    public function testAnUntrustedProjectsVendorShimIsNotTaken(): void
    {
        $this->untrustProjects();
        $project = new TempProject(['vendor/bin/demo' => "#!/bin/sh\n"]);
        \chmod($project->path('vendor/bin/demo'), permissions: 0o755);
        $this->project = $project;
        $api = $this->api();
        $plugin = $this->plugin();
        $this->activate($plugin, $api);

        $this->assertNull($plugin->callBinary($api));
        $api->changeSetting('binary', '/opt/custom/demo');
        $this->assertSame('/opt/custom/demo', $plugin->callBinary($api), 'what the user configured still applies');
    }

    /**
     * A tool that executes the project's PHP does not run in an untrusted
     * project, whichever binary it is — and says why, where a missing binary
     * would offer an install that fixes nothing.
     */
    public function testAToolThatRunsProjectCodeRefusesAnUntrustedProject(): void
    {
        $tool = static fn (): AbstractToolPlugin => new class('demo', 'demo') extends AbstractToolPlugin {
            public function activate(PluginApi $api): void
            {
                $this->registerBinarySetting($api, [['composer', 'acme/demo']]);
            }

            #[\Override]
            protected function runsProjectCode(): bool
            {
                return true;
            }

            public function callAcquire(PluginApi $api): ?string
            {
                return $this->acquire($api);
            }
        };
        $trusted = $tool();
        $api = $this->api();
        $this->activate($trusted, $api);
        $api->changeSetting('binary', '/opt/custom/demo');
        $this->assertSame('/opt/custom/demo', $trusted->callAcquire($api), 'trusted: it runs');

        $this->untrustProjects();
        $untrusted = $tool();
        $this->project = new TempProject();
        $api = $this->api();
        $this->activate($untrusted, $api);
        $api->changeSetting('binary', '/opt/custom/demo');
        $this->assertNull($untrusted->callAcquire($api));
        $this->assertStringContainsString('demo runs code of the project\'s own: trust the project to use it', $this->flashText($this->status));
    }

    public function testPathOnlySearchSkipsTheVendorShim(): void
    {
        $project = new TempProject(['vendor/bin/demo' => "#!/bin/sh\n"]);
        \chmod($project->path('vendor/bin/demo'), permissions: 0o755);
        $this->project = $project;

        $api = $this->api();
        $plugin = $this->plugin('demo', 'demo', BinarySearch::PathOnly);
        $this->activate($plugin, $api);

        $this->assertNull($plugin->callBinary($api), 'a PATH-only tool ignores vendor/bin — composer never installs it');
    }

    public function testAcquireFlashesWhatIsWrongAndReleasesTheSlot(): void
    {
        $api = $this->api();
        $missing = $this->plugin('demo', 'porta-nonexistent-tool');
        $this->activate($missing, $api);

        $this->assertNull($missing->callAcquire($api));
        $this->assertStringContainsString('demo not found (vendor/bin or PATH)', $this->flashText($this->status));

        $project = new TempProject(['vendor/bin/demo' => "#!/bin/sh\n"]);
        \chmod($project->path('vendor/bin/demo'), permissions: 0o755);
        $this->project = $project;
        $api = $this->api();
        $plugin = $this->plugin();
        $this->activate($plugin, $api);

        $this->assertNotNull($plugin->callAcquire($api), 'the slot is free');
        $this->assertNull($plugin->callAcquire($api), 'the slot is taken');
        $this->assertStringContainsString('demo: already running', $this->flashText($this->status));

        $plugin->callRelease();
        $this->assertNotNull($plugin->callAcquire($api), 'released, so it runs again');
    }

    /**
     * A remote project's cache holds what was opened, not the project: a
     * tool that reads the project would read placeholders. Refused before
     * the slot or the binary is looked at, so nothing is left held.
     */
    public function testARemoteProjectIsNotTheToolsToRun(): void
    {
        $project = new TempProject(['vendor/bin/demo' => "#!/bin/sh\n", ProjectLocation::MARKER => '{"url": "ssh://example.org/srv/app"}']);
        \chmod($project->path('vendor/bin/demo'), permissions: 0o755);
        $this->project = $project;
        $api = $this->api();
        $plugin = $this->plugin();
        $this->activate($plugin, $api);

        $this->assertNull($plugin->callAcquire($api));
        $this->assertSame('This project is on example.org: running it needs it on this machine', $this->flashText($this->status));
    }

    public function testPathOnlyToolsGetTheirOwnMissingMessage(): void
    {
        $api = $this->api();
        $plugin = $this->plugin('demo', 'porta-nonexistent-tool', BinarySearch::PathOnly);
        $this->activate($plugin, $api);
        $plugin->callAcquire($api);

        $this->assertStringContainsString('demo not found (set its binary or install it)', $this->flashText($this->status));
    }

    public function testTheActiveFileGuard(): void
    {
        $api = $this->api();
        $plugin = $this->plugin();

        $this->assertNull($plugin->callActiveFile($api), 'no tab, no file');
        $this->assertStringContainsString('demo: open a PHP file first', $this->flashText($this->status));

        $this->buffers->open('/p/notes.txt', 'text');
        $this->assertNull($plugin->callActiveFile($api), 'wrong extension');

        $this->buffers->open('/p/a.php', "<?php\n");
        $this->assertSame('/p/a.php', $plugin->callActiveFile($api));
        $this->assertNull($plugin->callActiveFile($api, '.rs'), 'the extension is a parameter');
    }

    public function testAvailabilityPredicateFollowsTheBinary(): void
    {
        $project = new TempProject(['vendor/bin/demo' => "#!/bin/sh\n"]);
        \chmod($project->path('vendor/bin/demo'), permissions: 0o755);
        $this->project = $project;
        $api = $this->api();
        $found = $this->plugin();
        $this->activate($found, $api);
        $missing = $this->plugin('demo', 'porta-nonexistent-tool');

        $this->assertTrue($found->callAvailable($api)());
        $this->assertFalse($missing->callAvailable($api)());
    }

    public function testRegistrationHelpersBufferTheirRows(): void
    {
        $api = $this->api();
        $plugin = $this->plugin();

        $plugin->callRegisterBinarySetting($api, [['composer', 'vendor/demo']]);
        $api->registerSetting('autoRun', 'Run on save', 'bool', false);
        $plugin->callRegisterToggle($api, 'toggle-on-save', 'Demo: Toggle on Save', 'autoRun', 'demo on save');
        $this->manager->commit($api);

        $settings = $api->bufferedSettings();
        $this->assertSame('binary', $settings[0]['key']);
        $this->assertSame('demo binary', $settings[0]['label']);
        $this->assertSame('empty = vendor/bin, then PATH', $settings[0]['hint']);
        $this->assertSame([['tool' => 'demo', 'available' => $api->bufferedInstallers()[0]['available'], 'via' => [['composer', 'vendor/demo']], 'plugin' => 'demo']], [[
            'tool' => $api->bufferedInstallers()[0]['tool'],
            'available' => $api->bufferedInstallers()[0]['available'],
            'via' => $api->bufferedInstallers()[0]['via'],
            'plugin' => $api->bufferedInstallers()[0]['plugin'],
        ]]);

        $commands = $api->bufferedCommands();
        $this->assertSame('demo:toggle-on-save', $commands[0]['id']);
        $commands[0]['handler']();
        $this->assertTrue((bool) $api->setting('autoRun'), 'the toggle flipped the setting on');
        $this->assertStringContainsString('demo on save: on', $this->flashText($this->status));
        $commands[0]['handler']();
        $this->assertFalse((bool) $api->setting('autoRun'));
        $this->assertStringContainsString('demo on save: off', $this->flashText($this->status));
    }

    public function testPathOnlyHintNamesThePathBinary(): void
    {
        $api = $this->api();
        $this->plugin('demo', 'demo_lsp', BinarySearch::PathOnly)->callRegisterBinarySetting($api, [['brew', 'demo']]);

        $this->assertSame('empty = demo_lsp on PATH', $api->bufferedSettings()[0]['hint']);
        $this->assertSame('demo_lsp binary', $api->bufferedSettings()[0]['label']);
    }

    /**
     * A spawn that fails must release the single-flight slot and say so.
     *
     * The slot is taken by `acquire()` before `start()` and released only in the
     * exit callback, which a failed spawn never reaches — so one unspawnable run
     * left the tool answering "already running" for the whole session about a run
     * that never started. Format-on-save would simply stop, permanently, with
     * the wrong explanation.
     */
    public function testASpawnThatFailsReleasesTheSlotAndSaysSo(): void
    {
        $this->project = new TempProject(['vendor/bin/demo' => "#!/nonexistent/interpreter\n"]);
        \chmod($this->project->path('vendor/bin/demo'), permissions: 0o755);
        $api = $this->api();
        $plugin = $this->plugin();
        $plugin->activate($api);
        $this->manager->commit($api);

        $plugin->callRunAndApply($api, ['--version'], 'demo: done');

        $this->assertStringContainsString('demo could not be started', $this->flashText($this->status));
        // The slot is free: acquire() answers with the binary rather than
        // refusing, which is what "already running" would have done forever.
        $this->assertNotNull($plugin->callAcquire($api), 'the slot was handed back');
    }

    public function testTheExitAndCountReports(): void
    {
        $api = $this->api();
        $plugin = $this->plugin();

        $plugin->callFlashExit($api, 0, 'demo: fixed a.php');
        $this->assertStringContainsString('demo: fixed a.php', $this->flashText($this->status));
        $plugin->callFlashExit($api, 3, 'demo: fixed a.php');
        $this->assertStringContainsString('demo: exited with code 3', $this->flashText($this->status));

        $plugin->callFlashCount($api, 0, 'issue', '/p/a.php');
        $this->assertStringContainsString('demo: no issues in a.php', $this->flashText($this->status));
        $plugin->callFlashCount($api, 1, 'error', '/p/a.php');
        $this->assertStringContainsString('demo: 1 error in a.php', $this->flashText($this->status));
        $plugin->callFlashCount($api, 4, 'error', '/p/a.php');
        $this->assertStringContainsString('demo: 4 errors in a.php', $this->flashText($this->status));
    }

    /**
     * Both nouns reach both sentences, and that is load-bearing.
     *
     * Five of the six translations of the ZERO-count string use `%noun%` where
     * the English source uses `%nouns%` — "aucun problème" is singular, and
     * Japanese and Hebrew do not mark a plural here at all. Trimming the params
     * to the placeholders the English happens to name would render those five
     * with a literal `%noun%` on screen, and no gate would see it: the catalogs
     * are complete, the string is translated, and `en` looks perfect.
     */
    public function testTheZeroCountSentenceSubstitutesInALocaleThatUsesTheOtherNoun(): void
    {
        $api = $this->api(locale: 'fr');
        $plugin = $this->plugin();

        $plugin->callFlashCount($api, 0, 'issue', '/p/a.php');

        $flash = $this->flashText($this->status);
        $this->assertStringNotContainsString('%', $flash, 'no placeholder survived into the sentence');
        $this->assertStringContainsString('aucun issue', $flash, 'the French target names %noun%, not %nouns%');
    }

    public function testTheSharedPluginBaseHelpers(): void
    {
        $api = $this->api('viewer');
        $plugin = new class('viewer') extends AbstractPlugin {
            public function activate(PluginApi $api): void {}

            public function callClaimed(PluginApi $api): array
            {
                return $this->claimedLanguages($api);
            }
        };

        $api->registerSetting('languages', 'Languages', 'text', '');
        $this->manager->commit($api);

        $this->assertSame('viewer', $plugin->name());
        $this->assertSame([], $plugin->callClaimed($api), 'an unset claim list claims nothing');
        $api->changeSetting('languages', ' rust , go ,, zig ');
        $this->assertSame(['rust', 'go', 'zig'], $plugin->callClaimed($api), 'trimmed, blanks dropped');
    }

    public function testApplyingAFormattersOutputOnlyTouchesTheBufferItRanOn(): void
    {
        // Everything here is "the user moved while the subprocess worked". The
        // file on disk is correct in every case; the question is only whether
        // the BUFFER may be written, and the answer is no unless it is still
        // the one the run started from.
        $api = $this->api();
        $plugin = $this->plugin();
        $plugin->activate($api);
        $path = $this->project->path('a.php');
        \file_put_contents($path, data: "formatted\n");
        $buffer = $this->buffers->open($path, "before\n");
        $this->buffers->setActive($path);
        $this->editor->showDocument($path, $buffer->document); // documentText() reads the widget's

        // Not the active file when the run started: nothing to apply into.
        $plugin->callApplyFormatted($api, $path, before: null);
        $this->assertSame("before\n", $this->buffers->active()?->document->getText());

        // Started on another file, and that file is not the active one now.
        $other = $this->project->path('b.php');
        \file_put_contents($other, data: "formatted\n");
        $plugin->callApplyFormatted($api, $other, before: "before\n"); // started elsewhere
        $this->assertSame("before\n", $this->buffers->active()?->document->getText(), 'the active buffer is not the one that was formatted');

        // Unchanged output: no edit, so no undo entry to step through.
        $plugin->callApplyFormatted($api, $path, before: "formatted\n");
        $this->assertSame("before\n", $this->buffers->active()?->document->getText());

        // The real path.
        $plugin->callApplyFormatted($api, $path, before: "before\n");
        $this->assertSame("formatted\n", $this->buffers->active()?->document->getText());
    }

    public function testTextBeforeIsNullWhenTheRunIsNotOnTheActiveBuffer(): void
    {
        $api = $this->api();
        $plugin = $this->plugin();
        $path = $this->project->path('a.php');
        $buffer = $this->buffers->open($path, "text\n");
        $this->buffers->setActive($path);
        $this->editor->showDocument($path, $buffer->document);

        $this->assertNull($plugin->callTextBefore($api, $this->project->path('elsewhere.php')));
        $this->assertSame("text\n", $plugin->callTextBefore($api, $path));
    }
}
