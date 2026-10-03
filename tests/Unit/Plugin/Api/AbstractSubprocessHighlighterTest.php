<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Plugin\Api;

use PHPUnit\Framework\TestCase;
use Porta\Config\ConfigFiles;
use Porta\Config\ConfigScope;
use Porta\Editor\BufferManager;
use Porta\Editor\BufferSaver;
use Porta\I18n\Translator;
use Porta\Plugin\Api\AbstractSubprocessHighlighter;
use Porta\Plugin\Api\PluginApi;
use Porta\Plugin\Runtime\PluginManager;
use Porta\Plugin\Runtime\PluginRuntime;
use Porta\Plugin\Runtime\PluginSettings;
use Porta\Settings\SettingsScope;
use Porta\Support\Clipboard;
use Porta\Tests\Support\FakePluginLifecycle;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\PumpsLoop;
use Porta\Tests\Support\RecordingChangeMarkSink;
use Porta\Tests\Support\RecordingDiagnosticsSink;
use Porta\Tests\Support\RecordingFileOpener;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\TestDockTools;
use Porta\Tests\Support\TestLspQuery;
use Porta\Tests\Support\TestParticipants;
use Porta\Tests\Support\TestProjectSwitcher;
use Porta\Tests\Support\TestRuns;
use Porta\Tests\Support\TestTui;
use Porta\Tests\Support\TestWorkspaceEditor;
use Porta\Tree\FileStatuses;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\FileTreeWidget;
use Porta\Widget\StatusBarWidget;
use Porta\Workbench\Menu\MenuFileContext;
use Porta\Workbench\Notifier;
use Porta\Workbench\View\ViewRegistry;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Tempest\Highlight\Tokens\TokenTypeEnum;

/**
 * The subprocess-highlighter template: debounce, latest-wins, the concurrency
 * ceiling, the size guard and the token-stream conversion, driven through a
 * real {@see PluginApi} against real shim processes. This machinery used to
 * live in the chroma plugin file, where neither the coverage gate nor mago
 * could see it.
 */
final class AbstractSubprocessHighlighterTest extends TestCase
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

    use PumpsLoop;

    private TempProject $project;
    private PluginManager $manager;
    private PluginApi $api;
    private AbstractSubprocessHighlighter $plugin;

    /** @var list<string> every markup the backend delivered */
    private array $delivered = [];

    /** The concrete backend: two mapped prefixes, longest first, and a lexer argv. */
    private function highlighter(string $binaryName = 'demo', int $debounceMs = 0): AbstractSubprocessHighlighter
    {
        return new class('demo', $binaryName, $debounceMs) extends AbstractSubprocessHighlighter {
            public function __construct(
                string $name,
                string $binaryName,
                private readonly int $debounceMs,
            ) {
                parent::__construct($name, $binaryName);
            }

            public function activate(PluginApi $api): void
            {
                $this->registerBinarySetting($api, [['brew', 'demo']]);
                $this->registerSubprocessHighlighter($api, 'Demo', 'rust,go', debounceMs: $this->debounceMs);
            }

            protected function lexerArgs(string $language): array
            {
                return ['-f', 'json', '--lexer', $language];
            }

            protected function tokenMap(): array
            {
                // Order matters: the longer prefix has to come first.
                return [
                    'KeywordType' => TokenTypeEnum::TYPE,
                    'Keyword' => TokenTypeEnum::KEYWORD,
                ];
            }
        };
    }

    /** An activated backend whose vendor/bin shim runs $body; returns its highlight handler. */
    private function backend(string $body, string $binaryName = 'demo', int $debounceMs = 0): \Closure
    {
        $this->project = new TempProject(['vendor/bin/demo' => '#!'.\PHP_BINARY." -n\n<?php\n".$body]);
        \chmod($this->project->path('vendor/bin/demo'), permissions: 0o755);

        $settings = new PluginSettings($this->project->dir, [], null, self::projectScope($this->project->dir));
        $this->manager = new PluginManager(new EventDispatcher(), $settings);
        $tui = TestTui::create(100, 24);
        $this->manager->wireRuntime(new PluginRuntime(
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
            TestLspQuery::create(),
            new BufferSaver(new BufferManager(), new EventDispatcher()),
            TestRuns::create(),
            TestWorkspaceEditor::create(),
            TestParticipants::create(),
            TestProjectSwitcher::create(),
        ));

        $api = new PluginApi('demo', $this->manager, $this->project->dir, $settings);
        $plugin = $this->highlighter($binaryName, $debounceMs);
        $plugin->activate($api);
        $this->manager->commit($api);
        $this->api = $api;
        $this->plugin = $plugin;

        $entry = $api->bufferedHighlighters()[0];
        $handler = $entry['highlight'];

        return function (string $text, string $language = 'rust') use ($handler): void {
            $handler($text, $language, function (string $markup): void {
                $this->delivered[] = $markup;
            });
        };
    }

    /** Await one delivery — by reference, so the async write is actually observed. */
    private function awaitDelivery(int $count = 1): void
    {
        $this->await(fn (): bool => \count($this->delivered) >= $count, what: 'the backend to deliver');
    }

    /**
     * Await the process out AND back, so render() has decided. Without this a
     * "nothing was delivered" assertion passes vacuously whenever the machine is
     * slow enough that the subprocess never finished — which is exactly what the
     * coverage run exposed.
     *
     * Settled is "the debounce timer has fired AND nothing is in flight": the
     * timer is armed the moment highlight() is called and its callback starts
     * the process synchronously, so the two are never both clear before the
     * exit callback has run. It used to await `running` reaching 1 and then 0 —
     * but a quick child starts and exits inside one loop turn, the 1 is never
     * observed, and the await ran out its whole budget (every Linux CI run).
     */
    private function awaitRunSettled(): void
    {
        $plugin = $this->plugin;
        $this->await(static fn (): bool => [] === Probe::get($plugin, 'timers') && 0 === Probe::get($plugin, 'running'), what: 'the run to finish');
    }

    public function testRegistrationDeclaresTheThreeRowsAndTheBackend(): void
    {
        $this->backend('echo "[]";');

        $rows = $this->api->bufferedSettings();
        $this->assertSame(['binary', 'languages', 'debounce', 'maxBytes'], \array_column($rows, 'key'));
        $this->assertSame('rust,go', $rows[1]['default']);
        $this->assertSame(0, $rows[2]['default']);
        $this->assertSame(262_144, $rows[3]['default']);

        $entry = $this->api->bufferedHighlighters()[0];
        $this->assertSame('Demo', $entry['label']);
        $this->assertSame('demo', $entry['id'], 'the id defaults to the plugin name');
        $this->assertSame(['rust', 'go'], $entry['languages']());
        $this->assertNotNull($entry['available']);
        $this->assertTrue($entry['available'](), 'the vendor/bin shim is discoverable');
    }

    public function testTheTokenStreamBecomesThemedAnsi(): void
    {
        $highlight = $this->backend(<<<'PHP'
            $src = (string) stream_get_contents(STDIN);
            echo json_encode([
                ['type' => 'Keyword', 'value' => 'fn'],
                ['type' => 'KeywordType', 'value' => ' u8'],
                ['type' => 'Text', 'value' => substr($src, 5)],
            ]);
            PHP);

        $highlight("fn u8 main()\n");
        $this->awaitDelivery();

        $markup = $this->delivered[0];
        $this->assertSame("fn u8 main()\n", AnsiUtils::stripAnsiCodes($markup), 'the source survives the round trip');
        $this->assertStringContainsString("\x1b[", $markup, 'mapped tokens carry the theme codes');
        $this->assertStringStartsWith("\x1b[", $markup, 'the first token is mapped');
        $this->assertStringEndsWith("main()\n", $markup, 'an unmapped type stays plain');
    }

    public function testNonStringTokenFieldsDegradeToPlain(): void
    {
        $highlight = $this->backend('echo json_encode([["type" => 7, "value" => 9]]);');

        $highlight("x\n");
        $this->awaitDelivery();

        $this->assertSame('', $this->delivered[0], 'no usable value and no usable type: an empty, uncoloured answer');
    }

    public function testAnOversizeDocumentIsSkipped(): void
    {
        $highlight = $this->backend('echo "[]";');
        $this->api->changeSetting('maxBytes', 4);

        $highlight('way beyond four bytes');
        $this->pump(0.2); // genuine negative: nothing was ever dispatched

        $this->assertSame([], $this->delivered);
    }

    public function testANewerRequestReplacesThePendingOne(): void
    {
        $highlight = $this->backend('echo json_encode([["type" => "Text", "value" => (string) stream_get_contents(STDIN)]]);', debounceMs: 60);

        $highlight('first');
        $highlight('second');
        $this->awaitDelivery();
        $this->await(fn (): bool => 0 === Probe::get($this->plugin, 'running'), what: 'the run to drain');

        $this->assertSame(['second'], $this->delivered, 'the pending run was replaced, not queued');
    }

    public function testASupersededAnswerIsDropped(): void
    {
        $highlight = $this->backend(<<<'PHP'
            usleep(150_000);
            echo json_encode([["type" => "Text", "value" => (string) stream_get_contents(STDIN)]]);
            PHP);

        $highlight('first');
        $this->pump(0.05); // let the timer fire, so the first process is really out
        $highlight('second');
        $this->awaitDelivery();
        $this->await(fn (): bool => 0 === Probe::get($this->plugin, 'running'), what: 'both processes to drain');

        $this->assertSame(['second'], $this->delivered, 'the in-flight answer for an older version is dropped');
    }

    /**
     * The ceiling holds at two — and the run it turns away still lands.
     *
     * This asserted "the third run never started" when it was written, which was
     * the implementation rather than the invariant: the ceiling exists so a
     * highlighter cannot become a fork bomb, not so that a keystroke can lose
     * its colours. The companion test below is what the old shape cost.
     */
    public function testTheCeilingHoldsAtTwoAndTheRunItTurnsAwayStillLands(): void
    {
        $highlight = $this->backend(<<<'PHP'
            usleep(250_000);
            echo json_encode([["type" => "Text", "value" => (string) stream_get_contents(STDIN)]]);
            PHP);

        // One per language, so debouncing cannot collapse them into one.
        $highlight('one', language: 'rust');
        $this->await(fn (): bool => 1 === Probe::get($this->plugin, 'running'), what: 'the first process to start');
        $highlight('two', language: 'go');
        $this->await(fn (): bool => 2 === Probe::get($this->plugin, 'running'), what: 'the second process to start');
        $highlight('three', language: 'zig');

        $peak = 0;
        $this->await(function () use (&$peak): bool {
            $running = Probe::get($this->plugin, 'running');
            $peak = \max($peak, \is_int($running) ? $running : 0);

            return 3 === \count($this->delivered);
        }, what: 'all three to land');

        $this->assertSame(2, $peak, 'never more than two processes at once');
        $delivered = $this->delivered;
        \sort($delivered);
        $this->assertSame(['one', 'three', 'two'], $delivered, 'and the third ran when a slot came free');
    }

    /**
     * A run the ceiling declines must not take the paint down with it.
     *
     * Two guards meet here and the meeting was never graded. Latest-wins drops
     * every answer but the newest dispatch's; the ceiling declines a dispatch
     * outright when two processes are already out. Do both to the same language
     * and the newest dispatch is the one that never ran, so its sequence
     * invalidates the two answers that DID arrive and nothing is ever painted —
     * the buffer keeps its plain text until the user types again.
     *
     * Not hypothetical: this is the shape behind a 1-in-10 E2e flake whose
     * reason was the chroma paint never landing at all.
     */
    public function testARunTheCeilingDeclinesStillGetsPainted(): void
    {
        $highlight = $this->backend(<<<'PHP'
            usleep(200_000);
            echo json_encode([["type" => "Text", "value" => (string) stream_get_contents(STDIN)]]);
            PHP);

        // Three dispatches of the SAME language, each past the one before it:
        // two go out, the third meets the ceiling.
        $highlight('one');
        $this->await(fn (): bool => 1 === Probe::get($this->plugin, 'running'), what: 'the first process to start');
        $highlight('two');
        $this->await(fn (): bool => 2 === Probe::get($this->plugin, 'running'), what: 'the second process to start');
        $highlight('three');

        $this->awaitDelivery(1);
        $this->assertSame(['three'], $this->delivered, 'the newest text is what the buffer shows');
    }

    /**
     * A spawn that FAILS must give the ceiling slot back.
     *
     * `AsyncProcess::start()` answers false when the child cannot be spawned at
     * all — the array form resolves the program itself, so an unusable
     * interpreter, a deleted binary or a system out of processes never reaches
     * the exit callback. The counter was raised before the call and lowered only
     * inside that callback, so every failed spawn leaked a slot: two of them and
     * `MAX_CONCURRENT` is reached forever, with syntax highlighting for that
     * language silently dead for the rest of the session and nothing anywhere
     * saying so.
     *
     * Found from the other end — a 1-in-10 E2e flake whose reason, once the
     * flake scan started keeping reasons, was the chroma paint never landing at
     * all under a suite's worth of concurrent subprocesses.
     */
    public function testASpawnThatFailsGivesTheCeilingSlotBack(): void
    {
        $highlight = $this->backend('echo "[]";');
        // Executable, resolvable, and unspawnable: posix_spawn cannot exec an
        // interpreter that is not there, which is what proc_open reports false
        // for. The same answer a fork-starved machine gives.
        \file_put_contents($this->project->path('vendor/bin/demo'), data: "#!/nonexistent/interpreter\n");
        \chmod($this->project->path('vendor/bin/demo'), permissions: 0o755);

        $highlight('one');
        $this->pump(0.1);

        $this->assertSame(0, Probe::get($this->plugin, 'running'), 'the slot was handed back');
        $this->assertSame([], $this->delivered, 'and nothing was painted from a run that never happened');
    }

    public function testTheHighlighterKeepsWorkingAfterAFailedSpawn(): void
    {
        // The consequence that matters: two failed spawns used to reach the
        // ceiling and stop the backend for good, so this asserts the recovery
        // rather than the counter.
        $highlight = $this->backend('echo json_encode([["type" => "Text", "value" => (string) stream_get_contents(STDIN)]]);');
        $working = (string) \file_get_contents($this->project->path('vendor/bin/demo'));
        \file_put_contents($this->project->path('vendor/bin/demo'), data: "#!/nonexistent/interpreter\n");
        \chmod($this->project->path('vendor/bin/demo'), permissions: 0o755);

        $highlight('one');
        $this->pump(0.1);
        $highlight('two');
        $this->pump(0.1);
        \file_put_contents($this->project->path('vendor/bin/demo'), data: $working);
        \chmod($this->project->path('vendor/bin/demo'), permissions: 0o755);
        $highlight('three');

        $this->awaitDelivery(1);
        $this->assertSame(['three'], $this->delivered, 'the backend recovered instead of staying at the ceiling');
    }

    public function testDeactivateKillsThePendingDebounceTimer(): void
    {
        // A live disable drops the highlighter from the registry, but a timer
        // already armed would still fire and spawn a subprocess for a plugin
        // the user just turned off. Deactivate must cancel it.
        $highlight = $this->backend('echo json_encode([["type" => "Text", "value" => "late"]]);', debounceMs: 60);

        $highlight('doomed');
        $this->plugin->deactivate();
        $this->pump(0.2); // well past the debounce: a surviving timer would have fired

        // The SPAWN is the observable, not the delivery: clearing $latest alone
        // would make a fired timer's answer read as superseded and get dropped,
        // so asserting "nothing was delivered" passes whether or not the timer
        // was cancelled — while a chroma process still ran for a plugin the
        // user just turned off, which is the whole point of the cancel.
        $this->assertSame(0, Probe::get($this->plugin, 'running'), 'no subprocess was started for the disabled plugin');
        $this->assertSame([], $this->delivered);
        $this->assertSame([], Probe::get($this->plugin, 'timers'));
        $this->assertSame([], Probe::get($this->plugin, 'latest'));
    }

    public function testAMissingBinaryDeclines(): void
    {
        $highlight = $this->backend('echo "[]";', binaryName: 'porta-nonexistent-tool');

        $highlight("x\n");
        $this->pump(0.2);

        $this->assertSame([], $this->delivered);
    }

    public function testANonZeroExitIsDropped(): void
    {
        $highlight = $this->backend('echo json_encode([["type" => "Text", "value" => "x"]]); exit(3);');

        $highlight("x\n");
        $this->awaitRunSettled();

        $this->assertSame([], $this->delivered, 'a failed run keeps the previous paint');
    }

    /**
     * Every malformed shape returns null, which keeps the previous paint rather
     * than blanking the buffer.
     */
    public function testUnusableOutputIsDropped(): void
    {
        foreach (['echo "not json at all";', 'echo "\"a string\"";', 'echo "[1, 2]";'] as $body) {
            $this->delivered = [];
            $highlight = $this->backend($body);

            $highlight("x\n");
            $this->awaitRunSettled();

            $this->assertSame([], $this->delivered, 'unusable output: '.$body);
        }
    }
}
