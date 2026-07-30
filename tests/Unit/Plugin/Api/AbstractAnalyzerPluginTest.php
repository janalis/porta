<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Plugin\Api;

use PHPUnit\Framework\TestCase;
use Porta\Editor\BufferManager;
use Porta\I18n\Translator;
use Porta\Plugin\Api\AbstractAnalyzerPlugin;
use Porta\Plugin\Api\PluginApi;
use Porta\Plugin\Runtime\PluginEvents;
use Porta\Plugin\Runtime\PluginManager;
use Porta\Plugin\Runtime\PluginRuntime;
use Porta\Plugin\Runtime\PluginSettings;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\PumpsLoop;
use Porta\Tests\Support\RecordingDiagnosticsSink;
use Porta\Tests\Support\RecordingFileOpener;
use Porta\Tests\Support\StatusReads;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\TestTui;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\StatusBarWidget;
use Porta\Workbench\Notifier;

/**
 * The analyzer template, driven end to end through a real {@see PluginApi}
 * and a real subprocess: phpstan and psalm differ only in the three small
 * overrides below, so everything else — the registration block, the run, the
 * report handling — is tested once, here.
 */
final class AbstractAnalyzerPluginTest extends TestCase
{
    use PumpsLoop;
    use StatusReads;

    /** A shim that prints a report and exits with a chosen code. */
    private const string SHIM = "#!/bin/sh\nprintf '%%s' '%s'\nexit %d\n";

    private StatusBarWidget $status;
    private BufferManager $buffers;
    private TempProject $project;
    private PluginManager $manager;
    private RecordingDiagnosticsSink $sink;
    private AbstractAnalyzerPlugin $plugin;

    /**
     * A concrete analyzer: its report shape is a flat `rows` list, it accepts
     * exit code 0 only, and it deliberately does NOT override noun() — the
     * default wording is what the flashes below assert.
     */
    private function analyzer(string $binaryName = 'demo'): AbstractAnalyzerPlugin
    {
        return new class('demo', $binaryName) extends AbstractAnalyzerPlugin {
            public function activate(PluginApi $api): void
            {
                $this->registerBinarySetting($api, [['composer', 'demo/demo']]);
                $this->registerAnalysis($api, 'Demo', ['ctrl+alt+d']);
            }

            protected function command(string $binary, string $file): array
            {
                return [$binary, $file];
            }

            protected function acceptsExitCode(int $code): bool
            {
                return 0 === $code;
            }

            protected function diagnostics(array $report): array
            {
                return array_map(
                    static fn (array $row): array => [
                        'line' => (int) $row['line'],
                        'message' => (string) $row['message'],
                    ],
                    self::rowsAt($report, 'rows'),
                );
            }

            /** Reaches the shared JSON walkers, which are protected for the plugins that use them. */
            public function walk(mixed $data, string $key): array
            {
                return [self::arrayAt($data, $key), self::rowsAt($data, $key)];
            }
        };
    }

    /** A live api over a temp project, with the runtime wired so action methods work. */
    private function api(): PluginApi
    {
        $settings = new PluginSettings($this->project->dir, []);
        $this->manager = new PluginManager(new PluginEvents(), $settings);
        $this->status = new StatusBarWidget();
        $this->buffers = new BufferManager();
        $this->sink = new RecordingDiagnosticsSink();
        $tui = TestTui::create(100, 24);
        $this->manager->wireRuntime(new PluginRuntime(
            new Notifier($this->status, $tui),
            new Translator('en'),
            new CodeEditorWidget(),
            new RecordingFileOpener(),
            $tui,
            $this->sink,
            $this->buffers,
        ));

        return new PluginApi('demo', $this->manager, $this->project->dir, $settings);
    }

    /** An activated analyzer over a project whose vendor/bin shim prints $report and exits $code. */
    private function withShim(string $report, int $code): PluginApi
    {
        $this->project = new TempProject(['vendor/bin/demo' => \sprintf(self::SHIM, $report, $code)]);
        chmod($this->project->path('vendor/bin/demo'), permissions: 0o755);
        $api = $this->api();
        $plugin = $this->analyzer();
        $plugin->activate($api);
        $this->manager->commit($api);
        $this->plugin = $plugin;

        return $api;
    }

    /** The buffered handler of a command, by its unprefixed id. */
    private function handler(PluginApi $api, string $id): \Closure
    {
        foreach ($api->bufferedCommands() as $command) {
            if ('demo:'.$id === $command['id']) {
                return $command['handler'];
            }
        }

        self::fail('no command '.$id);
    }

    public function testTheRegistrationBlockCoversCommandToggleMenuAndSaveHook(): void
    {
        $this->project = new TempProject();
        $api = $this->api();
        $plugin = $this->analyzer('porta-nonexistent-tool');
        $plugin->activate($api);
        $this->manager->commit($api);

        $settings = array_column($api->bufferedSettings(), 'key');
        $this->assertSame(['binary', 'autoLint'], $settings);

        $commands = $api->bufferedCommands();
        $this->assertSame('demo:analyse', $commands[0]['id']);
        $this->assertSame('Demo: Analyse Current File', $commands[0]['label']);
        $this->assertSame(['ctrl+alt+d'], $commands[0]['keys']);
        $this->assertSame('demo:toggle-on-save', $commands[1]['id']);
        $this->assertSame('Demo: Toggle Analyse on Save', $commands[1]['label']);

        $this->assertSame(['demo:analyse', 'demo:toggle-on-save'], array_column($api->bufferedMenuItems(), 'id'));
        $this->assertNotNull($api->bufferedMenuItems()[0]['when'], 'the analyse entry dims without a binary');
        $this->assertFalse($api->bufferedMenuItems()[0]['when']());
        $this->assertNull($api->bufferedMenuItems()[1]['when'], 'the toggle is always available');

        // No tab open, so the command stops at the active-file guard.
        $this->handler($api, 'analyse')();
        $this->assertStringContainsString('demo: open a PHP file first', $this->flashText($this->status));
    }

    public function testTheSaveHookRespectsTheSettingAndTheExtension(): void
    {
        $this->project = new TempProject();
        $api = $this->api();
        $plugin = $this->analyzer('porta-nonexistent-tool');
        $plugin->activate($api);
        $this->manager->commit($api);

        $hooks = $api->bufferedHooks();
        $this->assertSame('fileSaved', $hooks[0][0]);
        $saved = $hooks[0][1];

        // The analyzer's binary does not exist, so a run that STARTS flashes
        // that — which is how these three cases tell each other apart.
        $saved('/p/a.php');
        $this->assertSame('', $this->flashText($this->status), 'the setting is off');

        $api->changeSetting('autoLint', true);
        $saved('/p/notes.txt');
        $this->assertSame('', $this->flashText($this->status), 'not a PHP file');

        $saved('/p/a.php');
        $this->assertStringContainsString('demo not found', $this->flashText($this->status));
    }

    public function testTheSharedJsonWalkersDropWhateverTheToolDidNotPromise(): void
    {
        // A tool's JSON is mixed all the way down: the walkers answer "is this
        // an array" once, here, instead of in every plugin file.
        $analyzer = $this->analyzer();

        $this->assertSame([[], []], $analyzer->walk('not an array at all', 'files'), 'a report that is not an array holds nothing');
        $this->assertSame([[], []], $analyzer->walk(['other' => []], 'files'), 'a key that is not there');
        $this->assertSame([[], []], $analyzer->walk(['files' => 'a string'], 'files'), 'a key holding something else');
        $this->assertSame([['x' => 1], []], $analyzer->walk(['files' => ['x' => 1]], 'files'), 'an array of scalars is an array, but no rows');
        $this->assertSame(
            [[['line' => 2], 7], [['line' => 2]]],
            $analyzer->walk(['files' => [['line' => 2], 7]], 'files'),
            'a row of some other shape is dropped rather than guessed at',
        );
    }

    public function testAValidReportBecomesDiagnosticsAndACount(): void
    {
        $api = $this->withShim('{"rows":[{"line":3,"message":"boom"},{"line":9,"message":"bang"}]}', code: 0);
        $this->buffers->open($this->project->path('a.php'), "<?php\n");
        $this->handler($api, 'analyse')();

        $this->await(fn (): bool => [] !== $this->sink->published, what: 'the analysis to land');
        $published = $this->sink->published[0];
        $this->assertStringEndsWith('/a.php', $published['uri']);
        $this->assertSame([3, 9], array_keys($published['byLine']));
        $this->assertSame('boom', $published['byLine'][3][0]['message']);
        $this->assertStringContainsString('demo: 2 issues in a.php', $this->flashText($this->status));
    }

    public function testAnEmptyReportClearsTheDiagnostics(): void
    {
        $api = $this->withShim('{"rows":[]}', code: 0);
        $this->buffers->open($this->project->path('a.php'), "<?php\n");
        $this->handler($api, 'analyse')();

        $this->await(fn (): bool => [] !== $this->sink->published, what: 'the analysis to land');
        $this->assertSame([], $this->sink->published[0]['byLine']);
        $this->assertStringContainsString('demo: no issues in a.php', $this->flashText($this->status));
    }

    public function testARejectedExitCodeReportsAFailureAndKeepsTheOldDiagnostics(): void
    {
        $api = $this->withShim('{"rows":[{"line":1,"message":"never read"}]}', code: 7);
        $this->buffers->open($this->project->path('a.php'), "<?php\n");
        $this->handler($api, 'analyse')();

        $this->await(fn (): bool => str_contains($this->flashText($this->status), 'code 7'), what: 'the failure report');
        $this->assertSame([], $this->sink->published, 'a failed run publishes nothing');
    }

    public function testAnUnparseableReportIsAFailureEvenOnAnAcceptedExitCode(): void
    {
        $api = $this->withShim('not json at all', code: 0);
        $this->buffers->open($this->project->path('a.php'), "<?php\n");
        $this->handler($api, 'analyse')();

        $this->await(fn (): bool => str_contains($this->flashText($this->status), 'code 0'), what: 'the failure report');
        $this->assertSame([], $this->sink->published);
        $this->assertStringContainsString('demo: exited with code 0', $this->flashText($this->status));
    }

    /**
     * The seam a chained tool needs: mago formats under `acquire()` and then
     * lints through `analyseWith()`, so one user action is one slot. It must run
     * with the slot already held, and release it when the process exits.
     */
    public function testAChainedRunUsesTheSlotItAlreadyHolds(): void
    {
        $api = $this->withShim('{"rows":[{"line":4,"message":"chained"}]}', code: 0);
        $file = $this->project->path('a.php');
        $this->buffers->open($file, "<?php\n");

        $binary = Probe::invoke($this->plugin, 'acquire', $api);
        $this->assertNotNull($binary, 'the chaining plugin holds the slot');
        Probe::invoke($this->plugin, 'analyseWith', $api, $binary, $file);

        $this->await(fn (): bool => [] !== $this->sink->published, what: 'the chained analysis to land');
        $this->assertSame('chained', $this->sink->published[0]['byLine'][4][0]['message']);
        $this->assertFalse(Probe::get($this->plugin, 'running'), 'the chain released the slot at its end');
    }

    public function testASecondRunIsRefusedWhileOneIsInFlight(): void
    {
        $api = $this->withShim('{"rows":[]}', code: 0);
        $this->buffers->open($this->project->path('a.php'), "<?php\n");
        $analyse = $this->handler($api, 'analyse');

        $analyse();
        $analyse();
        $this->assertStringContainsString('demo: already running', $this->flashText($this->status));

        // And the slot is freed when the run finishes, so the next one starts.
        $this->await(fn (): bool => [] !== $this->sink->published, what: 'the first run');
        $analyse();
        $this->await(fn (): bool => 2 === \count($this->sink->published), what: 'the second run');
    }
}
