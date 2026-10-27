<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Lsp;

use PHPUnit\Framework\TestCase;
use Porta\Editor\Buffer;
use Porta\Editor\BufferManager;
use Porta\Editor\EditorInvalidator;
use Porta\I18n\Translator;
use Porta\Lsp\FormatOnSave;
use Porta\Lsp\LspSessionPool;
use Porta\Tests\Support\FakeLspClient;
use Porta\Tests\Support\FakeLspPool;
use Porta\Tests\Support\FakeScheduler;
use Porta\Tests\Support\FixedActiveFile;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\TestTui;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\StatusBarWidget;
use Porta\Workbench\Notifier;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Tui;

final class FormatOnSaveTest extends TestCase
{
    private FakeLspClient $client;
    private FakeScheduler $scheduler;
    private LspSessionPool $pool;
    private CodeEditorWidget $editor;
    private Tui $tui;
    private int $saves = 0;
    private StatusBarWidget $status;
    private int $invalidations = 0;

    private function formatOnSave(): FormatOnSave
    {
        $this->client = new FakeLspClient();
        $this->scheduler = new FakeScheduler();
        $this->saves = 0;
        $this->editor = new CodeEditorWidget();
        $this->pool = FakeLspPool::create('/tmp/proj', new FixedActiveFile('/tmp/proj/a.php'), fn (): FakeLspClient => $this->client);
        $this->invalidations = 0;
        $counting = new class($this->invalidations) implements EditorInvalidator {
            public function __construct(
                private int &$count,
            ) {}

            #[\Override]
            public function invalidate(): void
            {
                ++$this->count;
            }
        };
        $formatOnSave = new FormatOnSave(
            $this->pool,
            $counting,
            new Notifier(
                $this->status = new StatusBarWidget(),
                TestTui::create(80, 24),
            ),
            new Translator('en'),
            $this->scheduler,
            $this->tui = TestTui::create(80, 24),
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
        $this->assertSame(
            ['textDocument' => ['uri' => 'file:///tmp/proj/a.php'], 'options' => ['tabSize' => 4, 'insertSpaces' => true]],
            $this->client->requests[0]['params'],
            'the request carries the document, tab size and space-vs-tab option',
        );

        $this->respond([[
            'range' => ['start' => ['line' => 0, 'character' => 0], 'end' => ['line' => 0, 'character' => 11]],
            'newText' => '<?php $a = 1;',
        ]]);

        $this->assertSame("<?php \$a = 1;\n", $buffer->document->getText(), 'the server edit applied');
        $this->assertSame(1, $this->saves);
        $this->assertSame([], $this->scheduler->scheduled, 'the timeout was cancelled');
    }

    public function testAppliedEditsNotifyThePoolInvalidateTheEditorAndRequestARender(): void
    {
        $formatOnSave = $this->formatOnSave();
        $buffer = $this->buffer("<?php \$a=1;\n");
        $this->editor->showDocument($buffer->path, $buffer->document);

        $formatOnSave->formatThenSave($buffer, $this->save());
        Probe::set($this->tui, 'renderRequested', false);
        $notificationsBefore = \count($this->client->notifications);

        $this->respond([[
            'range' => ['start' => ['line' => 0, 'character' => 0], 'end' => ['line' => 0, 'character' => 11]],
            'newText' => '<?php $a = 1;',
        ]]);

        $this->assertGreaterThan($notificationsBefore, \count($this->client->notifications), 'the pool was told about the full change');
        $this->assertSame('textDocument/didChange', \end($this->client->notifications)['method']);
        $this->assertTrue((bool) Probe::get($this->tui, 'renderRequested'), 'a render was requested');
        // COUNTED, not rendered. The editor composes its rows from the document
        // either way, so a screen assertion passes whether or not the memoised
        // lines were dropped — this one did, for as long as it existed.
        $this->assertSame(1, $this->invalidations, 'the editor was invalidated exactly once');
        $screen = \implode("\n", \array_map(AnsiUtils::stripAnsiCodes(...), $this->editor->render(new RenderContext(80, 8))));
        $this->assertStringContainsString('<?php $a = 1;', $screen, 'and it shows the new text');
    }

    public function testTimeoutSavesUnformattedAndDropsTheLateResponse(): void
    {
        $formatOnSave = $this->formatOnSave();
        $buffer = $this->buffer("<?php \$a=1;\n");

        $formatOnSave->formatThenSave($buffer, $this->save());
        $this->scheduler->fire(); // the 2 s guard

        $this->assertSame(1, $this->saves, 'the save proceeded unformatted');
        $flash = AnsiUtils::stripAnsiCodes(\implode(' ', $this->status->render(new RenderContext(100, 1))));
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

    public function testAnErrorAlongsideAWellFormedResultStillSkipsTheEdits(): void
    {
        // A malformed adapter can send both a non-null result and an error;
        // the error must win — both conditions are required, not either.
        $formatOnSave = $this->formatOnSave();
        $buffer = $this->buffer("<?php \$a=1;\n");

        $formatOnSave->formatThenSave($buffer, $this->save());
        $this->respond([[
            'range' => ['start' => ['line' => 0, 'character' => 0], 'end' => ['line' => 0, 'character' => 11]],
            'newText' => 'SHOULD_NOT_APPLY',
        ]], ['code' => -32_000, 'message' => 'partial failure']);

        $this->assertSame("<?php \$a=1;\n", $buffer->document->getText(), 'edits alongside an error never apply');
        $this->assertSame(1, $this->saves);
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
