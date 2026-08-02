<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Plugin\Api;

use PHPUnit\Framework\TestCase;
use Porta\Editor\BufferManager;
use Porta\I18n\Translator;
use Porta\Plugin\Api\AbstractSubprocessHighlighter;
use Porta\Plugin\Api\PluginApi;
use Porta\Plugin\Runtime\PluginManager;
use Porta\Plugin\Runtime\PluginRuntime;
use Porta\Plugin\Runtime\PluginSettings;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\PumpsLoop;
use Porta\Tests\Support\RecordingChangeMarkSink;
use Porta\Tests\Support\RecordingDiagnosticsSink;
use Porta\Tests\Support\RecordingFileOpener;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\TestDockTools;
use Porta\Tests\Support\TestTui;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\StatusBarWidget;
use Porta\Workbench\Menu\MenuFileContext;
use Porta\Workbench\Notifier;
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
        chmod($this->project->path('vendor/bin/demo'), permissions: 0o755);

        $settings = new PluginSettings($this->project->dir, []);
        $this->manager = new PluginManager(new EventDispatcher(), $settings);
        $tui = TestTui::create(100, 24);
        $this->manager->wireRuntime(new PluginRuntime(
            new Notifier(new StatusBarWidget(), $tui),
            new Translator('en'),
            new CodeEditorWidget(),
            new RecordingFileOpener(),
            $tui,
            new RecordingDiagnosticsSink(),
            new CodeEditorWidget(),
            new RecordingChangeMarkSink(),
            new BufferManager(),
            TestDockTools::controller(),
            TestDockTools::replacer(),
            new CodeEditorWidget(),
            new MenuFileContext(),
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
     * Await the process out AND back: the base's in-flight counter returning to
     * zero means the exit callback ran, so render() has decided. Without this a
     * "nothing was delivered" assertion passes vacuously whenever the machine is
     * slow enough that the subprocess never finished — which is exactly what the
     * coverage run exposed.
     */
    private function awaitRunSettled(): void
    {
        $plugin = $this->plugin;
        $this->await(static fn (): bool => 1 === Probe::get($plugin, 'running'), what: 'the process to start');
        $this->await(static fn (): bool => 0 === Probe::get($plugin, 'running'), what: 'the process to finish');
    }

    public function testRegistrationDeclaresTheThreeRowsAndTheBackend(): void
    {
        $this->backend('echo "[]";');

        $rows = $this->api->bufferedSettings();
        $this->assertSame(['binary', 'languages', 'debounce', 'maxBytes'], array_column($rows, 'key'));
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

    public function testTheConcurrencyCeilingDeclinesTheThirdRun(): void
    {
        $highlight = $this->backend(<<<'PHP'
            usleep(250_000);
            echo json_encode([["type" => "Text", "value" => (string) stream_get_contents(STDIN)]]);
            PHP);

        // One per language, so debouncing cannot collapse them into one.
        $highlight('one', 'rust');
        $this->pump(0.05);
        $highlight('two', 'go');
        $this->pump(0.05);
        $highlight('three', 'zig');

        $this->awaitDelivery(2);
        $this->await(fn (): bool => 0 === Probe::get($this->plugin, 'running'), what: 'both processes to drain');
        // Two concurrent processes finish in whichever order the OS gives them,
        // so the claim is the SET: 'three' was declined, the other two ran.
        $delivered = $this->delivered;
        sort($delivered);
        $this->assertSame(['one', 'two'], $delivered, 'the third run never started');
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
