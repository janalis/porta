<?php

declare(strict_types=1);

namespace Porta\Todo\Tests;

use PHPUnit\Framework\TestCase;
use Porta\Config\ConfigFiles;
use Porta\Config\ConfigScope;
use Porta\Editor\BufferManager;
use Porta\Editor\BufferSaver;
use Porta\I18n\Translator;
use Porta\Plugin\Api\PluginApi;
use Porta\Plugin\Runtime\PluginManager;
use Porta\Plugin\Runtime\PluginRuntime;
use Porta\Plugin\Runtime\PluginSettings;
use Porta\Search\ProjectSearch;
use Porta\Settings\SettingsScope;
use Porta\Support\Clipboard;
use Porta\Support\ProjectPaths;
use Porta\Tests\Support\FakePluginLifecycle;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\PumpsLoop;
use Porta\Tests\Support\RecordingChangeMarkSink;
use Porta\Tests\Support\RecordingDiagnosticsSink;
use Porta\Tests\Support\RecordingFileOpener;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\TestBottomDock;
use Porta\Tests\Support\TestDockTools;
use Porta\Tests\Support\TestLspQuery;
use Porta\Tests\Support\TestParticipants;
use Porta\Tests\Support\TestProjectSwitcher;
use Porta\Tests\Support\TestRuns;
use Porta\Tests\Support\TestTui;
use Porta\Tests\Support\TestWorkspaceEditor;
use Porta\Todo\TodoScan;
use Porta\Todo\TodoTags;
use Porta\Tree\FileStatuses;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\DebugPanelWidget;
use Porta\Widget\FileTreeWidget;
use Porta\Widget\ResultsPanelWidget;
use Porta\Widget\StatusBarWidget;
use Porta\Widget\TerminalPanelWidget;
use Porta\Workbench\Dock\BottomDock;
use Porta\Workbench\Dock\DockToolController;
use Porta\Workbench\Menu\MenuFileContext;
use Porta\Workbench\Notifier;
use Porta\Workbench\View\ViewRegistry;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;

/**
 * The scan itself, driven through the real plugin API onto a real dock — the
 * seam it publishes over is the point, so faking it would test nothing.
 */
final class TodoScanTest extends TestCase
{
    use PreservesPath;
    use PumpsLoop;

    private BottomDock $dock;
    private ResultsPanelWidget $results;

    protected function setUp(): void
    {
        // No grep on PATH → the engine's PHP walk: one file system everywhere.
        \putenv('PATH=/nonexistent');
    }

    private function api(TempProject $project, string $tags = ''): PluginApi
    {
        // Pinned to Project: SettingsScope defaults to Global for a project
        // with no `settings` block, and Global is the suite's SHARED fixture
        // home — a setting persisted there leaks into every later test.
        $scope = new SettingsScope(ConfigFiles::for($project->dir));
        $scope->set(ConfigScope::Project);
        $settings = new PluginSettings($project->dir, [], null, $scope);
        $manager = new PluginManager(new EventDispatcher(), $settings);
        $tui = TestTui::create(120, 24);
        $this->results = new ResultsPanelWidget();
        $this->dock = TestBottomDock::create($tui, new TerminalPanelWidget(), $this->results, new DebugPanelWidget());
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
            new DockToolController($manager, $this->dock, new ProjectPaths($project->dir)),
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
            TestRuns::create(),
            TestWorkspaceEditor::create(),
            TestParticipants::create(),
            TestProjectSwitcher::create(),
        ));

        $api = $manager->api('todo', $project->dir, $settings);
        $api->registerSetting('tags', 'Tags', 'text', '' === $tags ? TodoTags::DEFAULT : $tags);
        $api->registerListTool(TodoScan::TOOL, 'TODO');
        $manager->commit($api);

        return $api;
    }

    /** Run a scan and wait it out: the walk answers between frames. */
    private function scan(PluginApi $api, TempProject $project): void
    {
        new TodoScan($api, new ProjectSearch($project->dir))->run();
        $this->await(fn (): bool => !\str_contains($this->results->statusText(), 'searching'), what: 'the scan to finish');
    }

    private function panel(): string
    {
        return AnsiUtils::stripAnsiCodes(\implode("\n", $this->results->render(new RenderContext(120, 12))));
    }

    public function testTheScanFillsTheTabWithEveryMarkerCaseSensitively(): void
    {
        $project = new TempProject([
            'src/A.php' => "<?php\n// TODO extract this\n",
            'src/B.py' => "# FIXME races\n# just a todo word in prose\n",
        ]);
        $api = $this->api($project);

        $this->scan($api, $project);

        $this->assertSame('TODO', $this->results->getTitle());
        $this->assertNull($this->dock->mode(), 'a contributed tool serves no core mode');
        $plain = $this->panel();
        $this->assertStringContainsString('TODO extract this', $plain);
        $this->assertStringContainsString('FIXME races', $plain);
        $this->assertStringNotContainsString('prose', $plain, 'lower-case "todo" prose is not a hit');
        $this->assertStringContainsString('2 results', $this->results->statusText());
    }

    public function testTheConfiguredTagsWidenAndNarrowTheScan(): void
    {
        $project = new TempProject(['src/A.php' => "<?php\n// HACK temporary\n// TODO later\n"]);
        $api = $this->api($project, tags: 'HACK');

        $this->scan($api, $project);

        $plain = $this->panel();
        $this->assertStringContainsString('HACK temporary', $plain, 'HACK is scanned when configured');
        $this->assertStringNotContainsString('TODO later', $plain, 'a tag outside the list is not a hit');

        // The setting is read per RUN, so an edit lands on the next scan with
        // nothing to invalidate — the live-apply path the core version needed.
        $api->changeSetting('tags', '  ,  ');
        $this->scan($api, $project);
        $this->assertStringContainsString('TODO later', $this->panel(), 'blank tags fall back to TODO|FIXME');
    }

    public function testARescanReusesTheSameTabRatherThanAppending(): void
    {
        $project = new TempProject(['a.php' => "<?php\n// TODO one\n"]);
        $api = $this->api($project);

        $this->scan($api, $project);
        $this->scan($api, $project);

        $this->assertCount(1, $this->dock->aliveTabs(), 'the tool id keys the tab; F5 is a refresh, not a new tab');
    }
}
