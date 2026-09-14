<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Lsp;

use PHPUnit\Framework\TestCase;
use Porta\Editor\Document;
use Porta\I18n\Translator;
use Porta\Lsp\FoldingController;
use Porta\Lsp\LspSessionPool;
use Porta\Tests\Support\FakeLspClient;
use Porta\Tests\Support\FakeScheduler;
use Porta\Tests\Support\FixedActiveFile;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\TestTui;
use Porta\Widget\CodeEditorWidget;

final class FoldingControllerTest extends TestCase
{
    private FakeLspClient $client;
    private FakeScheduler $scheduler;
    private LspSessionPool $pool;
    private CodeEditorWidget $editor;
    private \Symfony\Component\Tui\Tui $tui;

    /** Three lines of a block: the LSP says fold 0-2, the brace scan says 1-3. */
    private const string TEXT = "head\nopen {\n    body;\n}\ntail\n";

    private function controller(): FoldingController
    {
        $this->client = new FakeLspClient();
        $this->scheduler = new FakeScheduler();
        $this->editor = new CodeEditorWidget();
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

        return new FoldingController(
            $this->pool,
            $this->editor,
            $this->scheduler,
            $this->tui = TestTui::create(80, 24),
        );
    }

    private function showDocument(string $path = '/tmp/proj/a.php'): Document
    {
        $doc = new Document(self::TEXT);
        $this->editor->showDocument($path, $doc);

        return $doc;
    }

    /** Respond to the last foldingRange request. */
    private function respond(mixed $result, ?array $error = null): void
    {
        $request = null;
        foreach ($this->client->requests as $sent) {
            if ('textDocument/foldingRange' !== $sent['method']) {
                continue;
            }

            $request = $sent;
        }
        $this->assertNotNull($request, 'a foldingRange request was sent');
        $request['respond']($result, $error);
    }

    private function foldEndAt(int $line): ?int
    {
        $foldModel = Probe::get($this->editor, 'foldModel');
        \assert($foldModel instanceof \Porta\Editor\FoldModel, description: 'the editor exposes a FoldModel for fold queries');

        return $foldModel->foldEndAt($line);
    }

    public function testNoOpenFileIsANoOp(): void
    {
        $controller = $this->controller();
        Probe::set($this->tui, 'renderRequested', false);

        $controller->foldAll();

        $this->assertSame([], $this->client->requests);
        $this->assertFalse((bool) Probe::get($this->tui, 'renderRequested'), 'no file open — not even the brace-scan fallback runs');
    }

    public function testNoReadyServerFoldsByBraceScan(): void
    {
        $controller = $this->controller();
        $this->showDocument('/tmp/proj/readme.weird'); // no server for the language

        $controller->foldAll();
        $this->assertSame(3, $this->foldEndAt(1), 'the brace region folded');
        $this->assertSame([], $this->scheduler->scheduled, 'no round started');
    }

    public function testLspRegionsFoldAndUnfoldAllClears(): void
    {
        $controller = $this->controller();
        $doc = $this->showDocument();
        $this->pool->notifyDidOpen('/tmp/proj/a.php', $doc->getText(), 'php', 1);

        $controller->foldAll();
        $this->assertSame(
            ['textDocument' => ['uri' => 'file:///tmp/proj/a.php']],
            $this->client->requests[0]['params'],
            'the request names the document being folded',
        );
        $this->respond([
            ['startLine' => 0, 'endLine' => 2, 'kind' => 'region'],
            ['startLine' => 4, 'endLine' => 4], // single-line: dropped
            ['startLine' => 'x', 'endLine' => 2], // malformed: dropped
        ]);

        $this->assertSame(2, $this->foldEndAt(0), 'the LSP region folded');
        $this->assertNull($this->foldEndAt(1), 'the brace region was superseded');
        $this->assertSame([], $this->scheduler->scheduled, 'the timeout was cancelled');

        Probe::set($this->tui, 'renderRequested', false);
        $controller->unfoldAll();
        $this->assertNull($this->foldEndAt(0));
        $this->assertTrue((bool) Probe::get($this->tui, 'renderRequested'), 'unfolding requests a render');
    }

    public function testMalformedRangesAreDroppedWithoutAbortingTheOnesAfterThem(): void
    {
        $controller = $this->controller();
        $doc = $this->showDocument();
        $this->pool->notifyDidOpen('/tmp/proj/a.php', $doc->getText(), 'php', 1);

        $controller->foldAll();
        $this->respond([
            // Numeric-STRING lines (not int): is_int() rejects them, but a
            // naive fix that only half-guards would let PHP8's numeric-string
            // comparison downstream (`4 > '2'`) accept them anyway — so a
            // non-int start/end must never survive to become a fold.
            ['startLine' => '2', 'endLine' => 4], // bad start
            ['startLine' => 6, 'endLine' => '9'], // bad end
            ['startLine' => 'x', 'endLine' => 1], // bad start, non-numeric too
            ['startLine' => 2, 'endLine' => 2], // single-line — dropped (endLine must be strictly greater)
            ['startLine' => 0, 'endLine' => 1],
            ['startLine' => 3, 'endLine' => 4],
        ]);

        $this->assertSame(1, $this->foldEndAt(0), 'the first valid region folded, despite the malformed entries before it');
        $this->assertSame(4, $this->foldEndAt(3), 'the second valid region folded too — the result list is not truncated to one');
        $this->assertNull($this->foldEndAt(2), 'a single-line range never folds');
        $this->assertNull($this->foldEndAt(6), 'a non-int end line never folds, numeric-looking or not');
    }

    public function testErrorResponseFallsBackToBraces(): void
    {
        $controller = $this->controller();
        $doc = $this->showDocument();
        $this->pool->notifyDidOpen('/tmp/proj/a.php', $doc->getText(), 'php', 1);

        $controller->foldAll();
        $this->respond(null, ['code' => -32_601, 'message' => 'unsupported']);
        $this->assertSame(3, $this->foldEndAt(1), 'fell back to the brace scan');
    }

    public function testTimeoutFoldsByBracesAndDropsTheLateResponse(): void
    {
        $controller = $this->controller();
        $doc = $this->showDocument();
        $this->pool->notifyDidOpen('/tmp/proj/a.php', $doc->getText(), 'php', 1);

        $controller->foldAll();
        $this->scheduler->fire();
        $this->assertSame(3, $this->foldEndAt(1), 'the timeout folded by brace scan');

        $controller->unfoldAll();
        $this->respond([['startLine' => 0, 'endLine' => 2]]); // late — dropped
        $this->assertNull($this->foldEndAt(0), 'the late response did not fold anything');
    }

    public function testASupersededRoundsTimeoutIsInert(): void
    {
        $controller = $this->controller();
        $doc = $this->showDocument();
        $this->pool->notifyDidOpen('/tmp/proj/a.php', $doc->getText(), 'php', 1);

        $controller->foldAll();
        $firstTimer = (string) \array_key_last($this->scheduler->scheduled);
        $controller->foldAll(); // a newer round supersedes the first
        $this->scheduler->fire($firstTimer);
        $this->assertNull($this->foldEndAt(1), 'the stale timeout folded nothing');
    }

    public function testAnEditBetweenRequestAndResponseDropsTheRegions(): void
    {
        $controller = $this->controller();
        $doc = $this->showDocument();
        $this->pool->notifyDidOpen('/tmp/proj/a.php', $doc->getText(), 'php', 1);

        $controller->foldAll();
        $doc->insert('x'); // version moves on
        $this->respond([['startLine' => 0, 'endLine' => 2]]);
        $this->assertNull($this->foldEndAt(0), 'stale regions were not applied');
        $this->assertSame(3, $this->foldEndAt(1), 'the brace scan folded instead');
    }
}
