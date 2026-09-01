<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Lsp;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\Editor\Buffer;
use Porta\Editor\BufferManager;
use Porta\Editor\Document;
use Porta\Editor\FileOpener;
use Porta\Editor\OpenFocus;
use Porta\Event\ActiveBufferChanged;
use Porta\Event\BufferSaved;
use Porta\I18n\Translator;
use Porta\Lsp\LspSessionPool;
use Porta\Lsp\NavigationController;
use Porta\Lsp\StructurePanelRefresher;
use Porta\Support\DocsUrlOpener;
use Porta\Support\ProjectPaths;
use Porta\Tests\Support\FakeLspClient;
use Porta\Tests\Support\FixedActiveFile;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\TestBottomDock;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\DebugPanelWidget;
use Porta\Widget\ResultsPanelWidget;
use Porta\Widget\StatusBarWidget;
use Porta\Widget\TerminalPanelWidget;
use Porta\Workbench\Notifier;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

final class NavigationControllerTest extends TestCase
{
    /** @var list<string> */
    private array $opened = [];
    private CodeEditorWidget $editor;
    private ResultsPanelWidget $results;
    private StatusBarWidget $statusBar;
    private Tui $tui;
    private BufferManager $buffers;
    private FixedActiveFile $activeFile;
    private FakeLspClient $client;

    private function controller(): NavigationController
    {
        $this->opened = [];
        $this->editor = new CodeEditorWidget();
        $this->tui = new Tui(terminal: new VirtualTerminal(columns: 100, rows: 20));
        $this->statusBar = new StatusBarWidget();
        $notifier = new Notifier($this->statusBar, $this->tui);
        $translator = new Translator('en');
        $this->results = new ResultsPanelWidget();
        $dock = TestBottomDock::create($this->tui, new TerminalPanelWidget(), $this->results, new DebugPanelWidget());
        $this->buffers = new BufferManager();
        $opened = &$this->opened;
        $pool = new LspSessionPool(
            '/p',
            $this->activeFile = new FixedActiveFile(),
            $translator,
            fn (array $command, string $rootDir, \Closure $onLog): FakeLspClient => $this->client = new FakeLspClient(),
        );
        $pool->configure(
            ['php' => ['command' => ['php-ls'], 'name' => 'php-ls']],
            static function (string $line): void {},
            static function (array $params): void {},
            static fn (array $edit): bool => false,
        );

        return new NavigationController(
            $pool,
            $this->editor,
            $this->tui,
            $notifier,
            $translator,
            $this->buffers,
            $dock,
            new ProjectPaths('/p'),
            new class($opened) implements FileOpener {
                /** @param list<string> $opened */
                public function __construct(
                    private array &$opened,
                ) {}

                public function openFile(string $path, OpenFocus $focus = OpenFocus::Keep): void
                {
                    $this->opened[] = $path;
                }
            },
            new DocsUrlOpener($notifier, $translator, '/p'),
        );
    }

    private function flashed(): string
    {
        return AnsiUtils::stripAnsiCodes(implode('', $this->statusBar->render(new RenderContext(100, 1))));
    }

    /** Opens $path in the editor, the buffer manager and the fake LSP session, ready to answer requests. */
    private function readyOn(NavigationController $controller, string $path, string $text): void
    {
        $this->editor->showDocument($path, new Document($text));
        $this->buffers->open($path, $text);
        $this->activeFile->set($path);
        Probe::get($controller, 'lsp')->notifyDidOpen($path, $text, 'php', 1);
    }

    public function testPushCurrentLocationRecordsOnlyWhenAFileIsOpen(): void
    {
        $controller = $this->controller();

        // No file open: nothing is recorded — proven via TWO pushes then one
        // pop, which would only balance if both pushes were no-ops.
        $controller->pushCurrentLocation();
        $controller->pushCurrentLocation();
        $controller->navigateBack();
        $controller->navigateBack();
        $this->assertStringContainsString('Nowhere to go back to', $this->flashed(), 'both pushes with no file were no-ops');

        $this->editor->showDocument('/p/a.php', new Document("<?php\n\$a = 1;\n\$b = 2;\n"));
        $this->editor->getDocument()->setCursor(1, 2);
        $controller->pushCurrentLocation();

        $this->editor->showDocument('/p/b.php', new Document("<?php\n"));
        $controller->navigateBack();

        $this->assertSame(['/p/a.php'], $this->opened, 'navigating back opened the recorded file');
    }

    public function testNavigateBackWithNothingRecordedFlashes(): void
    {
        $controller = $this->controller();

        $controller->navigateBack();

        $this->assertStringContainsString('Nowhere to go back to', $this->flashed());
        $this->assertSame([], $this->opened, 'nothing to open');
    }

    public function testNavigateBackSkipsReopeningTheSameFile(): void
    {
        $controller = $this->controller();
        $this->editor->showDocument('/p/a.php', new Document("<?php\n\$a = 1;\n\$b = 2;\n"));
        $this->editor->getDocument()->setCursor(2, 0);
        $controller->pushCurrentLocation();
        $this->editor->getDocument()->setCursor(0, 0); // still the same file, just moved

        $controller->navigateBack();

        $this->assertSame([], $this->opened, 'already on that file — no re-open needed');
        $this->assertSame(2, $this->editor->getDocument()->getCursorLine(), 'the cursor still jumps back');
    }

    public function testRequestHoverGuardsOnAnUnreadySession(): void
    {
        $controller = $this->controller();
        // No file open at all: ready() returns null before any LSP traffic.
        $controller->requestHover(0, 0);

        $this->assertSame([], $this->opened);
    }

    public function testRequestPeekOpensAContextOverlayAtTheDefinition(): void
    {
        $controller = $this->controller();
        $this->readyOn($controller, '/p/a.php', "<?php\nfunction f() {}\n\$x = f();\n");

        $controller->requestPeek(2, 5);
        $this->assertCount(1, $this->client->requests);
        $this->assertSame('textDocument/definition', $this->client->requests[0]['method']);

        $this->client->requests[0]['respond']([
            'uri' => 'file:///p/a.php',
            'range' => ['start' => ['line' => 1, 'character' => 0], 'end' => ['line' => 1, 'character' => 8]],
        ], error: null);

        $picker = implode("\n", array_map(AnsiUtils::stripAnsiCodes(...), $this->editor->layoutPickers([0, 0, 20, 100])[0]['rows']));
        $this->assertStringContainsString('Peek: a.php:2', $picker);
        $this->assertStringContainsString('function f() {}', $picker);
    }

    public function testRequestPeekFlashesWhenNothingIsFound(): void
    {
        $controller = $this->controller();
        $this->readyOn($controller, '/p/a.php', "<?php\n\$x = 1;\n");

        $controller->requestPeek(1, 0);
        $this->client->requests[0]['respond'](null, error: null);

        $this->assertStringContainsString('Nothing to peek', $this->flashed());
    }

    public function testRequestPeekFlashesWhenTheTargetIsOutsideTheFilesystem(): void
    {
        $controller = $this->controller();
        $this->readyOn($controller, '/p/a.php', "<?php\n\$x = 1;\n");

        $controller->requestPeek(1, 0);
        $this->client->requests[0]['respond']([
            'uri' => 'untitled:scratch',
            'range' => ['start' => ['line' => 0, 'character' => 0], 'end' => ['line' => 0, 'character' => 0]],
        ], error: null);

        $this->assertStringContainsString('Peek target outside the filesystem', $this->flashed());
    }

    public function testRequestFileStructureOpensAnOverlayThatJumpsOnPick(): void
    {
        $controller = $this->controller();
        $this->readyOn($controller, '/p/a.php', "<?php\nclass Foo {\n    public function bar(): void {}\n}\n");

        $controller->requestFileStructure();
        $this->assertCount(1, $this->client->requests);
        $this->assertSame('textDocument/documentSymbol', $this->client->requests[0]['method']);
        $this->assertSame('file:///p/a.php', $this->client->requests[0]['params']['textDocument']['uri']);

        $this->client->requests[0]['respond']([
            [
                'name' => 'Foo',
                'kind' => 5,
                'selectionRange' => ['start' => ['line' => 1, 'character' => 6]],
            ],
        ], error: null);

        $picker = implode("\n", array_map(AnsiUtils::stripAnsiCodes(...), $this->editor->layoutPickers([0, 0, 20, 100])[0]['rows']));
        $this->assertStringContainsString('Foo', $picker);

        $this->editor->handleInput("\r"); // pick the only row
        $this->assertSame(1, $this->editor->getDocument()->getCursorLine(), 'picking a row jumps to its position');
        $this->assertSame(6, $this->editor->getDocument()->getCursorCol());
    }

    public function testRequestFileStructureFlashesWhenTheFileHasNoSymbols(): void
    {
        $controller = $this->controller();
        $this->readyOn($controller, '/p/a.php', "<?php\n");

        $controller->requestFileStructure();
        $this->client->requests[0]['respond']([], error: null);

        $this->assertStringContainsString('No symbols in this file', $this->flashed());
    }

    public function testLineAtPrefersTheLiveBufferOverDisk(): void
    {
        $project = sys_get_temp_dir().'/nav-line-at-'.bin2hex(random_bytes(4));
        mkdir($project);
        try {
            $path = $project.'/a.php';
            file_put_contents($path, data: "on-disk line 0\non-disk line 1\n");

            $controller = $this->controller();
            $this->buffers->open($path, "buffer line 0\nbuffer line 1\n");
            $this->assertSame('buffer line 1', Probe::invoke($controller, 'lineAt', $path, 1), 'the live (unsaved) buffer wins over the on-disk file');
        } finally {
            is_file($project.'/a.php') && unlink($project.'/a.php');
            is_dir($project) && rmdir($project);
        }
    }

    public function testLineAtFallsBackToDiskWhenNoBufferIsOpen(): void
    {
        $project = sys_get_temp_dir().'/nav-line-at-'.bin2hex(random_bytes(4));
        mkdir($project);
        try {
            $path = $project.'/a.php';
            file_put_contents($path, data: "on-disk line 0\non-disk line 1\n");

            $controller = $this->controller();
            $this->assertSame('on-disk line 1', Probe::invoke($controller, 'lineAt', $path, 1));
        } finally {
            is_file($project.'/a.php') && unlink($project.'/a.php');
            is_dir($project) && rmdir($project);
        }
    }

    public function testLineAtOnAMissingPathReturnsEmpty(): void
    {
        $controller = $this->controller();

        $this->assertSame('', Probe::invoke($controller, 'lineAt', '/p/does-not-exist.php', 0));
    }

    public function testLineAtWithAnOutOfRangeLineReturnsEmpty(): void
    {
        $project = sys_get_temp_dir().'/nav-line-at-'.bin2hex(random_bytes(4));
        mkdir($project);
        try {
            $path = $project.'/a.php';
            file_put_contents($path, data: "only line\n");

            $controller = $this->controller();
            $this->assertSame('', Probe::invoke($controller, 'lineAt', $path, 5));
        } finally {
            is_file($project.'/a.php') && unlink($project.'/a.php');
            is_dir($project) && rmdir($project);
        }
    }

    /** The docs URL the controller would open for the word at (line, col) of $content, or null = the LSP answers. */
    private function urlAt(string $file, string $content, int $line, int $col): ?string
    {
        $controller = $this->controller();
        $this->editor->openFile($file, $content);

        /** @var string|null $url */
        return Probe::invoke($controller, 'nativeDocsUrl', $line, $col);
    }

    public function testABackslashOnlyBindsWhenItIsTheLastCharacterBeforeTheWord(): void
    {
        // "App\Foo . " contains a mid-string backslash, but it is NOT the
        // character immediately before "count" — the anchored regex must not
        // fire just because a backslash appears earlier on the line.
        $this->assertSame(
            'https://www.php.net/manual/en/function.count.php',
            $this->urlAt('x.php', "<?php\nApp\\Foo . count(\$x);\n", 1, 12),
            'the backslash earlier on the line does not immediately precede "count"',
        );
    }

    public function testADeclarationKeywordOnlyBindsAtTheEndOfTheLeadingText(): void
    {
        // "function foo() { " contains the word "function", but not as the
        // text immediately preceding "strlen" — the anchored regex must not
        // fire just because the keyword appears earlier on the line.
        $this->assertSame(
            'https://www.php.net/manual/en/function.strlen.php',
            $this->urlAt('x.php', "<?php\nfunction foo() { strlen(\$x); }\n", 1, 20),
            'the earlier "function" keyword does not make "strlen" a declaration site',
        );
    }

    /** @return list<array<array-key, mixed>> a class with one method, as DocumentSymbol */
    private function symbolTree(): array
    {
        return [[
            'name' => 'Greeter',
            'kind' => 5,
            'selectionRange' => ['start' => ['line' => 2, 'character' => 6]],
            'children' => [[
                'name' => 'greet',
                'kind' => 6,
                'selectionRange' => ['start' => ['line' => 4, 'character' => 20]],
            ]],
        ]];
    }

    private function plainPanel(): string
    {
        return AnsiUtils::stripAnsiCodes(implode("\n", $this->results->render(new RenderContext(100, 12))));
    }

    public function testStructurePanelListsTheSymbolTreeIndentedByNesting(): void
    {
        $controller = $this->controller();
        $this->readyOn($controller, '/p/a.php', "<?php\n\n\n\n\n");

        $controller->requestStructurePanel();
        $this->client->requests[0]['respond']($this->symbolTree(), error: null);

        $panel = $this->plainPanel();
        $this->assertStringContainsString('Greeter', $panel);
        $this->assertStringContainsString('  greet', $panel, 'a child symbol is indented under its parent');
        $this->assertStringContainsString('class', $panel, 'the symbol kind rides the row');
    }

    public function testStructurePanelFlashesWhenTheServerHasNoSymbols(): void
    {
        $controller = $this->controller();
        $this->readyOn($controller, '/p/a.php', "<?php\n");

        $controller->requestStructurePanel();
        $this->client->requests[0]['respond']([], error: null);

        $this->assertStringContainsString('No symbols in this file', $this->flashed());
    }

    /**
     * The refresh path must not COST anything while the tab is closed: no
     * request is even sent, so switching buffers with no Structure tab open is
     * free.
     */
    public function testARefreshWithNoStructureTabOpenSendsNoRequest(): void
    {
        $controller = $this->controller();
        $this->readyOn($controller, '/p/a.php', "<?php\n");

        $controller->refreshStructurePanel();

        $this->assertSame([], $this->client->requests, 'a closed Structure tab must not cost a documentSymbol round trip');
    }

    public function testARefreshReplacesTheRowsOfAStandingTab(): void
    {
        $controller = $this->controller();
        $this->readyOn($controller, '/p/a.php', "<?php\n\n\n\n\n");

        $controller->requestStructurePanel();
        $this->client->requests[0]['respond']($this->symbolTree(), error: null);
        $this->assertStringContainsString('Greeter', $this->plainPanel());

        $controller->refreshStructurePanel();
        $this->client->requests[1]['respond']([[
            'name' => 'Farewell',
            'kind' => 5,
            'selectionRange' => ['start' => ['line' => 1, 'character' => 6]],
        ]], error: null);

        $panel = $this->plainPanel();
        $this->assertStringContainsString('Farewell', $panel);
        $this->assertStringNotContainsString('Greeter', $panel, 'the standing tab now shows the new file, not both');
    }

    /** A file the server has no symbols for still has to CLEAR the panel, not leave the last file's tree standing. */
    public function testARefreshOntoASymbolLessFileEmptiesTheStandingTab(): void
    {
        $controller = $this->controller();
        $this->readyOn($controller, '/p/a.php', "<?php\n\n\n\n\n");

        $controller->requestStructurePanel();
        $this->client->requests[0]['respond']($this->symbolTree(), error: null);

        $controller->refreshStructurePanel();
        $this->client->requests[1]['respond']([], error: null);

        $this->assertStringNotContainsString('Greeter', $this->plainPanel());
        $this->assertStringNotContainsString('No symbols in this file', $this->flashed(), 'a background refresh never flashes');
    }

    /**
     * Both facts the refresher listens to must go through the REFRESH verb.
     * Calling the reveal one would send a request (and open the dock) on every
     * buffer switch and every save, which is exactly what a background refresh
     * must never do — so "no request was sent" is the assertion that catches it.
     *
     * @return \Generator<string, array{\Closure(StructurePanelRefresher): void}>
     */
    public static function refresherFacts(): \Generator
    {
        yield 'the active buffer changed' => [static fn (StructurePanelRefresher $r) => $r->onActiveBufferChanged(new ActiveBufferChanged('/p/a.php'))];
        yield 'a buffer was saved' => [static fn (StructurePanelRefresher $r) => $r->onBufferSaved(new BufferSaved(new Buffer('/p/a.php', new Document("<?php\n"))))];
    }

    /** @param \Closure(StructurePanelRefresher): void $fire */
    #[DataProvider('refresherFacts')]
    public function testARefresherFactIsInertWhileNoStructureTabIsOpen(\Closure $fire): void
    {
        $controller = $this->controller();
        $this->readyOn($controller, '/p/a.php', "<?php\n");

        $fire(new StructurePanelRefresher($controller));

        $this->assertSame([], $this->client->requests, 'a background refresh must cost nothing while the tab is closed');
    }

    /** @param \Closure(StructurePanelRefresher): void $fire */
    #[DataProvider('refresherFacts')]
    public function testARefresherFactRepublishesIntoAStandingTab(\Closure $fire): void
    {
        $controller = $this->controller();
        $this->readyOn($controller, '/p/a.php', "<?php\n\n\n\n\n");
        $controller->requestStructurePanel();
        $this->client->requests[0]['respond']($this->symbolTree(), error: null);

        $fire(new StructurePanelRefresher($controller));
        $this->assertCount(2, $this->client->requests, 'the standing tab asked for fresh symbols');
        $this->client->requests[1]['respond']([[
            'name' => 'Farewell',
            'kind' => 5,
            'selectionRange' => ['start' => ['line' => 1, 'character' => 6]],
        ]], error: null);

        $this->assertStringContainsString('Farewell', $this->plainPanel());
    }
}
