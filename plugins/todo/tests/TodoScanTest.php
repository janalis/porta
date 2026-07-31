<?php

declare(strict_types=1);

namespace Porta\Todo\Tests;

use PHPUnit\Framework\TestCase;
use Porta\Plugin\Api\PluginApi;
use Porta\Plugin\Runtime\PluginEvents;
use Porta\Plugin\Runtime\PluginManager;
use Porta\Plugin\Runtime\PluginRuntime;
use Porta\Plugin\Runtime\PluginSettings;
use Porta\Search\ProjectSearch;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\RecordingDiagnosticsSink;
use Porta\Tests\Support\RecordingFileOpener;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\TestBottomDock;
use Porta\Tests\Support\TestDockTools;
use Porta\Tests\Support\TestTui;
use Porta\Todo\TodoScan;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\DebugPanelWidget;
use Porta\Widget\ResultsPanelWidget;
use Porta\Widget\StatusBarWidget;
use Porta\Widget\TerminalPanelWidget;
use Porta\Workbench\Dock\BottomDock;
use Porta\Workbench\Dock\DockToolController;
use Porta\Workbench\Menu\MenuFileContext;
use Porta\Workbench\Notifier;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;

/**
 * The scan itself, driven through the real plugin API onto a real dock — the
 * seam it publishes over is the point, so faking it would test nothing.
 */
final class TodoScanTest extends TestCase
{
    use PreservesPath;

    private BottomDock $dock;
    private ResultsPanelWidget $results;

    protected function setUp(): void
    {
        // No grep on PATH → the engine's synchronous PHP fallback, so the scan
        // completes inside run() without pumping the event loop.
        putenv('PATH=/nonexistent');
    }

    private function api(TempProject $project, string $tags = ''): PluginApi
    {
        $settings = new PluginSettings($project->dir, []);
        $manager = new PluginManager(new PluginEvents(), $settings);
        $tui = TestTui::create(120, 24);
        $this->results = new ResultsPanelWidget();
        $this->dock = TestBottomDock::create($tui, new TerminalPanelWidget(), $this->results, new DebugPanelWidget());
        $manager->wireRuntime(new PluginRuntime(
            new Notifier(new StatusBarWidget(), $tui),
            new \Porta\I18n\Translator('en'),
            new CodeEditorWidget(),
            new RecordingFileOpener(),
            $tui,
            new RecordingDiagnosticsSink(),
            new \Porta\Editor\BufferManager(),
            new DockToolController($manager, $this->dock, $project->dir),
            TestDockTools::replacer(),
            new CodeEditorWidget(),
            new MenuFileContext(),
        ));

        $api = new PluginApi('todo', $manager, $project->dir, $settings);
        $api->registerSetting('tags', 'Tags', 'text', '' === $tags ? \Porta\Todo\TodoTags::DEFAULT : $tags);
        $api->registerListTool(TodoScan::TOOL, 'TODO');
        $manager->commit($api);

        return $api;
    }

    private function panel(): string
    {
        return AnsiUtils::stripAnsiCodes(implode("\n", $this->results->render(new RenderContext(120, 12))));
    }

    public function testTheScanFillsTheTabWithEveryMarkerCaseSensitively(): void
    {
        $project = new TempProject([
            'src/A.php' => "<?php\n// TODO extract this\n",
            'src/B.py' => "# FIXME races\n# just a todo word in prose\n",
        ]);
        $api = $this->api($project);

        new TodoScan($api, new ProjectSearch($project->dir))->run();

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

        new TodoScan($api, new ProjectSearch($project->dir))->run();

        $plain = $this->panel();
        $this->assertStringContainsString('HACK temporary', $plain, 'HACK is scanned when configured');
        $this->assertStringNotContainsString('TODO later', $plain, 'a tag outside the list is not a hit');

        // The setting is read per RUN, so an edit lands on the next scan with
        // nothing to invalidate — the live-apply path the core version needed.
        $api->changeSetting('tags', '  ,  ');
        new TodoScan($api, new ProjectSearch($project->dir))->run();
        $this->assertStringContainsString('TODO later', $this->panel(), 'blank tags fall back to TODO|FIXME');
    }

    public function testARescanReusesTheSameTabRatherThanAppending(): void
    {
        $project = new TempProject(['a.php' => "<?php\n// TODO one\n"]);
        $api = $this->api($project);

        new TodoScan($api, new ProjectSearch($project->dir))->run();
        new TodoScan($api, new ProjectSearch($project->dir))->run();

        $this->assertCount(1, $this->dock->aliveTabs(), 'the tool id keys the tab; F5 is a refresh, not a new tab');
    }
}
