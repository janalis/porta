<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Lsp;

use PHPUnit\Framework\TestCase;
use Porta\Editor\Buffer;
use Porta\Editor\BufferManager;
use Porta\I18n\Translator;
use Porta\Lsp\FormatOnSave;
use Porta\Lsp\LspSessionPool;
use Porta\Lsp\WorkspaceEditor;
use Porta\Tests\Support\FakeLspClient;
use Porta\Tests\Support\FakeScheduler;
use Porta\Tests\Support\FixedActiveFile;
use Porta\Tests\Support\TestTui;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\StatusBarWidget;
use Porta\Workbench\Notifier;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Style\StyleSheet;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

final class FormatOnSaveTest extends TestCase
{
    private FakeLspClient $client;
    private FakeScheduler $scheduler;
    private LspSessionPool $pool;
    private int $saves = 0;
    private StatusBarWidget $status;

    private function formatOnSave(): FormatOnSave
    {
        $this->client = new FakeLspClient();
        $this->scheduler = new FakeScheduler();
        $this->saves = 0;
        $editor = new CodeEditorWidget();
        $this->pool = new LspSessionPool(
            '/tmp/proj',
            new FixedActiveFile('/tmp/proj/a.php'),
            new Translator('en'),
            fn (array $command, string $rootDir, \Closure $onLog): FakeLspClient => $this->client,
        );
        $this->pool->configure(
            ['php' => ['command' => ['php-ls'], 'name' => 'php-ls']],
            static function (string $line): void {},
            static function (array $params): void {},
            static fn (array $edit): bool => false,
        );
        $formatOnSave = new FormatOnSave(
            $this->pool,
            new WorkspaceEditor(new BufferManager(), $editor, $this->pool),
            $editor,
            new Notifier(
                $this->status = new StatusBarWidget(),
                $tui = TestTui::create(80, 24),
            ),
            new Translator('en'),
            $this->scheduler,
            TestTui::create(80, 24),
        );
        $formatOnSave->enable();

        return $formatOnSave;
    }

    private function buffer(string $text = "<?php \$a=1;\n"): Buffer
    {
        $buffer = new BufferManager()->open('/tmp/proj/a.php', $text);
        // The pool routes by extension; opening starts the fake server.
        $this->pool->notifyDidOpen($buffer->path, $text, 'php', 1);

        return $buffer;
    }

    private function save(): \Closure
    {
        return function (): void {
            ++$this->saves;
        };
    }

    /** The last textDocument/formatting request's response callback. */
    private function respond(mixed $result, ?array $error = null): void
    {
        $request = null;
        foreach ($this->client->requests as $sent) {
            if ('textDocument/formatting' !== $sent['method']) {
                continue;
            }

            $request = $sent;
        }
        $this->assertNotNull($request, 'a formatting request was sent');
        $request['respond']($result, $error);
    }

    public function testTheEnabledFlagHolds(): void
    {
        $formatOnSave = $this->formatOnSave();
        $this->assertTrue($formatOnSave->isEnabled());
        $formatOnSave->disable();
        $this->assertFalse($formatOnSave->isEnabled());
    }

    public function testNoReadyServerSavesImmediately(): void
    {
        $formatOnSave = $this->formatOnSave();
        $buffer = new BufferManager()->open('/tmp/proj/readme.weird', "x\n"); // no server for the language

        $formatOnSave->formatThenSave($buffer, $this->save());

        $this->assertSame(1, $this->saves);
        $this->assertSame([], $this->scheduler->scheduled, 'no round started');
    }

    public function testEditsApplyThenExactlyOneSave(): void
    {
        $formatOnSave = $this->formatOnSave();
        $buffer = $this->buffer("<?php \$a=1;\n");

        $formatOnSave->formatThenSave($buffer, $this->save());
        $this->assertSame(0, $this->saves, 'the save waits for the response');

        $this->respond([[
            'range' => ['start' => ['line' => 0, 'character' => 0], 'end' => ['line' => 0, 'character' => 11]],
            'newText' => '<?php $a = 1;',
        ]]);

        $this->assertSame("<?php \$a = 1;\n", $buffer->document->getText(), 'the server edit applied');
        $this->assertSame(1, $this->saves);
        $this->assertSame([], $this->scheduler->scheduled, 'the timeout was cancelled');
    }

    public function testTimeoutSavesUnformattedAndDropsTheLateResponse(): void
    {
        $formatOnSave = $this->formatOnSave();
        $buffer = $this->buffer("<?php \$a=1;\n");

        $formatOnSave->formatThenSave($buffer, $this->save());
        $this->scheduler->fire(); // the 2 s guard

        $this->assertSame(1, $this->saves, 'the save proceeded unformatted');
        $flash = \Symfony\Component\Tui\Ansi\AnsiUtils::stripAnsiCodes(implode(' ', $this->status->render(new \Symfony\Component\Tui\Render\RenderContext(100, 1))));
        $this->assertStringContainsString('Formatting timed out — saved unformatted', $flash, 'the skipped format is visible');

        $this->respond([[
            'range' => ['start' => ['line' => 0, 'character' => 0], 'end' => ['line' => 0, 'character' => 11]],
            'newText' => 'LATE',
        ]]);
        $this->assertSame("<?php \$a=1;\n", $buffer->document->getText(), 'the late response was dropped');
        $this->assertSame(1, $this->saves, 'no second save');
    }

    public function testVersionDriftSkipsTheEditsButStillSaves(): void
    {
        $formatOnSave = $this->formatOnSave();
        $buffer = $this->buffer("<?php \$a=1;\n");

        $formatOnSave->formatThenSave($buffer, $this->save());
        $buffer->document->insert('x'); // the user typed mid-round-trip

        $this->respond([[
            'range' => ['start' => ['line' => 0, 'character' => 0], 'end' => ['line' => 0, 'character' => 11]],
            'newText' => 'STALE',
        ]]);

        $this->assertStringNotContainsString('STALE', $buffer->document->getText(), 'stale edits never apply');
        $this->assertSame(1, $this->saves);
    }

    public function testEmptyOrErrorResultSavesSilently(): void
    {
        $formatOnSave = $this->formatOnSave();
        $buffer = $this->buffer();

        $formatOnSave->formatThenSave($buffer, $this->save());
        $this->respond([]);
        $this->assertSame(1, $this->saves, 'an empty result just saves');

        $formatOnSave->formatThenSave($buffer, $this->save());
        $this->respond(null, ['code' => -32_601, 'message' => 'unsupported']);
        $this->assertSame(2, $this->saves, 'an error result just saves');
    }

    public function testAReentrantSaveSupersedesThePendingRound(): void
    {
        $formatOnSave = $this->formatOnSave();
        $buffer = $this->buffer();

        $formatOnSave->formatThenSave($buffer, $this->save());
        $formatOnSave->formatThenSave($buffer, $this->save()); // Ctrl+S again while pending

        // The first round's timeout is now stale: firing it must not save.
        $this->scheduler->fire('1');
        $this->assertSame(0, $this->saves, 'the superseded round is inert');

        // The second round completes normally.
        $this->respond([]);
        $this->assertSame(1, $this->saves);
    }
}
