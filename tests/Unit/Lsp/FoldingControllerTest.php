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
use Porta\Widget\CodeEditorWidget;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Style\StyleSheet;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

final class FoldingControllerTest extends TestCase
{
    private FakeLspClient $client;
    private FakeScheduler $scheduler;
    private LspSessionPool $pool;
    private CodeEditorWidget $editor;

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
            new Tui(terminal: new VirtualTerminal(columns: 80, rows: 24), keybindings: new Keybindings([]), renderer: new Renderer(new StyleSheet())),
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
        $foldModel = new \ReflectionProperty($this->editor, 'foldModel')->getValue($this->editor);
        \assert($foldModel instanceof \Porta\Editor\FoldModel, description: 'the editor exposes a FoldModel for fold queries');

        return $foldModel->foldEndAt($line);
    }

    public function testNoOpenFileIsANoOp(): void
    {
        $controller = $this->controller();
        $controller->foldAll();
        $this->assertSame([], $this->client->requests);
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
        $this->respond([
            ['startLine' => 0, 'endLine' => 2, 'kind' => 'region'],
            ['startLine' => 4, 'endLine' => 4], // single-line: dropped
            ['startLine' => 'x', 'endLine' => 2], // malformed: dropped
        ]);

        $this->assertSame(2, $this->foldEndAt(0), 'the LSP region folded');
        $this->assertNull($this->foldEndAt(1), 'the brace region was superseded');
        $this->assertSame([], $this->scheduler->scheduled, 'the timeout was cancelled');

        $controller->unfoldAll();
        $this->assertNull($this->foldEndAt(0));
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
        $firstTimer = (string) array_key_last($this->scheduler->scheduled);
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
