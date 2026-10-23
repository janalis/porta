<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Lsp;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\Editor\Buffer;
use Porta\Editor\BufferManager;
use Porta\Editor\CompletionItem;
use Porta\Editor\DiagnosticSeverity;
use Porta\Editor\Document;
use Porta\Editor\FileOpener;
use Porta\Editor\JumpHistory;
use Porta\Editor\LineTextReader;
use Porta\Editor\OpenFocus;
use Porta\Editor\ProblemCards;
use Porta\Event\ActiveBufferChanged;
use Porta\Event\BufferSaved;
use Porta\I18n\Translator;
use Porta\Lsp\LspSessionPool;
use Porta\Lsp\NavigationController;
use Porta\Lsp\QuickFixProbe;
use Porta\Lsp\StructurePanelRefresher;
use Porta\Support\DocsUrlOpener;
use Porta\Support\ProjectPaths;
use Porta\Tests\Support\EditorState;
use Porta\Tests\Support\FakeLspClient;
use Porta\Tests\Support\FakeScheduler;
use Porta\Tests\Support\FixedActiveFile;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\TestBottomDock;
use Porta\Ui\Glyphs;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\DebugPanelWidget;
use Porta\Widget\ResultsPanelWidget;
use Porta\Widget\StatusBarWidget;
use Porta\Widget\TerminalPanelWidget;
use Porta\Workbench\Dock\BottomDock;
use Porta\Workbench\Dock\ResultsPanelMode;
use Porta\Workbench\Notifier;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

final class NavigationControllerTest extends TestCase
{
    /** Where the problem card's fix question waits for its loop turn. */
    private FakeScheduler $scheduler;

    /** @var list<string> */
    private array $opened = [];
    private CodeEditorWidget $editor;
    private ResultsPanelWidget $results;
    private StatusBarWidget $statusBar;
    private Tui $tui;
    private BufferManager $buffers;
    private FixedActiveFile $activeFile;
    private FakeLspClient $client;
    private BottomDock $dock;
    /**
     * What the server advertises. Call hierarchy is gated on it, so a test
     * that wants an answer must say the server can give one — as neither
     * bundled PHP server does.
     *
     * @var array<string, mixed>
     */
    private array $capabilities = ['callHierarchyProvider' => true];

    private function controller(): NavigationController
    {
        $this->opened = [];
        $this->editor = new CodeEditorWidget();
        $this->tui = new Tui(terminal: new VirtualTerminal(columns: 100, rows: 20));
        $this->statusBar = new StatusBarWidget();
        $notifier = new Notifier($this->statusBar, $this->tui);
        $translator = new Translator('en');
        $this->results = new ResultsPanelWidget();
        $this->dock = TestBottomDock::create($this->tui, new TerminalPanelWidget(), $this->results, new DebugPanelWidget());
        $dock = $this->dock;
        $this->buffers = new BufferManager();
        $opened = &$this->opened;
        $pool = new LspSessionPool(
            '/p',
            $this->activeFile = new FixedActiveFile(),
            $translator,
            fn (array $command, string $rootDir, \Closure $onLog): FakeLspClient => $this->client = new FakeLspClient($this->capabilities),
        );
        $pool->configure(
            ['php' => [['command' => ['php-ls'], 'name' => 'php-ls']]],
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
            new JumpHistory(),
            new LineTextReader($this->buffers),
            new ProblemCards($this->editor, new QuickFixProbe($pool, $this->editor, $this->scheduler = new FakeScheduler()), $translator),
        );
    }

    private function flashed(): string
    {
        return AnsiUtils::stripAnsiCodes(\implode('', $this->statusBar->render(new RenderContext(100, 1))));
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
        $this->assertSame(2, $this->editor->getDocument()->cursorLine, 'the cursor still jumps back');
    }

    public function testRequestHoverGuardsOnAnUnreadySession(): void
    {
        $controller = $this->controller();
        // No file open at all: ready() returns null before any LSP traffic.
        $controller->requestHover(0, 0);

        $this->assertSame([], $this->opened);
    }

    /** The rendered picker rows, plain. */
    private function pickerText(): string
    {
        return EditorState::pickerText($this->editor);
    }

    /** The editor as drawn, plain. */
    private function screen(int $rows = 20): string
    {
        return \implode("\n", \array_map(AnsiUtils::stripAnsiCodes(...), $this->editor->render(new RenderContext(100, $rows))));
    }

    /**
     * Documentation opens under the caret, in a popup that reads it in place
     * — markdown's marks gone — and scrolls to the end of it: nothing is cut
     * away, only out of view until the arrows or the wheel bring it in.
     */
    public function testDocumentationOpensAtTheCaretAndScrollsToItsEnd(): void
    {
        $controller = $this->controller();
        $this->readyOn($controller, '/p/a.php', "<?php\n\$x = 1;\n");
        $doc = "**Greets** the `name`.\n".\implode("\n", \array_map(static fn (int $n): string => "line{$n}", \range(start: 1, end: 30)));

        $caret = $this->editor->getDocument()->cursorLine;
        $controller->requestHover(1, 0);
        $this->client->requests[0]['respond'](['contents' => ['kind' => 'markdown', 'value' => $doc]], error: null);

        $screen = $this->screen();
        $this->assertStringContainsString('│ Greets the name.', $screen, 'read in place, the markup gone');
        $this->assertStringNotContainsString('line25', $screen, 'row 25 starts off screen');
        $this->assertStringContainsString('▼', $screen, 'and says there is more');

        $this->editor->movePopupSelection(24); // the wheel
        $this->assertStringContainsString('line25', $this->screen(), 'and scrolls into view');

        $this->editor->handleInput("\x1b[B");
        $this->editor->handleInput("\x1b[6~");
        $this->assertStringContainsString('line30', $this->screen(), 'to the end with the keys');
        $this->assertSame($caret, $this->editor->getDocument()->cursorLine, 'which the caret never saw');

        $this->editor->handleInput("\x1b");
        $this->assertStringNotContainsString('line30', $this->screen(), 'Esc closes it');
    }

    private function problemOn(int $line, int $col, int $endCol, string $message = 'Undefined variable $x'): void
    {
        $this->editor->decorations->setDiagnostics([$line => [['severity' => DiagnosticSeverity::Error, 'message' => $message, 'col' => $col, 'endCol' => $endCol, 'source' => 'phpstan']]]);
    }

    /** PhpStorm's Ctrl+F1: the problems at the caret, in full, with no server needed. */
    public function testErrorDescriptionShowsTheProblemAtTheCaret(): void
    {
        $controller = $this->controller();
        $this->editor->showDocument('/p/a.php', new Document("<?php\n\$x = 1;\n"));
        $this->problemOn(1, 0, 2);

        $controller->requestErrorDescription(1, 2);
        $this->assertSame([], $this->scheduler->scheduled, 'no server to ask for a fix');

        $screen = $this->screen();
        $this->assertStringContainsString('✗ Undefined variable $x', $screen);
        $this->assertStringContainsString('phpstan', $screen, 'where it came from');
    }

    public function testErrorDescriptionAwayFromAProblemSaysSo(): void
    {
        $controller = $this->controller();
        $this->editor->showDocument('/p/a.php', new Document("<?php\n\$x = 1;\n"));
        $this->problemOn(1, 0, 2);

        $controller->requestErrorDescription(1, 5);

        $this->assertStringContainsString('No problem at the caret', $this->flashed());
    }

    /** PhpStorm's combined popup: Shift+F1 on a problem puts it above the documentation, parted by a tee. */
    public function testDocumentationOnAProblemIsHeadedByIt(): void
    {
        $controller = $this->controller();
        $this->readyOn($controller, '/p/a.php', "<?php\n\$x = 1;\n");
        $this->problemOn(1, 0, 2);

        $controller->requestHover(1, 1);
        \array_last($this->client->requests)['respond'](['contents' => 'An integer.'], error: null);
        // The fix question gets its turn; this server offers no code actions,
        // so the popup's problems are said again as they were.
        $this->scheduler->fire();

        $rows = \explode("\n", $this->screen());
        $problem = \array_key_first(\array_filter($rows, static fn (string $row): bool => \str_contains($row, 'Undefined variable')));
        $docs = \array_key_first(\array_filter($rows, static fn (string $row): bool => \str_contains($row, 'An integer.')));
        $this->assertNotNull($problem);
        $this->assertSame($problem + 3, $docs, 'the problem, where it came from, the tee, then the docs');
        $this->assertStringContainsString('├', $rows[$problem + 2]);
    }

    public function testOnAProblemWithoutDocumentationOrAServerTheProblemIsTheAnswer(): void
    {
        $controller = $this->controller();
        $this->readyOn($controller, '/p/a.php', "<?php\n\$x = 1;\n");
        $this->problemOn(1, 0, 2);

        $controller->requestHover(1, 1);
        \array_last($this->client->requests)['respond'](null, error: null);
        $this->assertStringContainsString('Undefined variable', $this->screen(), 'no documentation: the problem alone');
        $this->assertStringNotContainsString('No documentation found', $this->flashed());

        $offline = $this->controller();
        $this->editor->showDocument('/p/a.php', new Document("<?php\n\$x = 1;\n"));
        $this->problemOn(1, 0, 2, message: 'Offline problem');
        $offline->requestHover(1, 1);
        $this->assertStringContainsString('Offline problem', $this->screen(), 'no server: the problem alone, and nothing to explain');
        $this->assertSame('', \trim($this->flashed()));
    }

    /** Any other key closes it and does what it does; a click on it stays on it, one elsewhere closes it. */
    public function testTheDocPopupClosesOnAnyOtherKeyOrAClickAway(): void
    {
        $controller = $this->controller();
        $this->readyOn($controller, '/p/a.php', "<?php\n\$x = 1;\n");
        $controller->requestHover(1, 0);
        $this->client->requests[0]['respond'](['contents' => 'Greets.'], error: null);
        $this->screen();
        [$top, $col] = Probe::get($this->editor, 'caretPopups')->doc->drawn['rect'];

        $this->assertTrue($this->editor->handlePopupClick($top + 1, $col + 2), 'on the popup: kept');
        $this->assertStringContainsString('Greets.', $this->screen());
        $this->assertFalse($this->editor->handlePopupClick(15, 60), 'away from it: closed, and the click goes on');
        $this->assertStringNotContainsString('Greets.', $this->screen());

        // Esc is spent on the popup alone: a signature hint under it stays.
        $controller->requestHover(1, 0);
        $this->client->requests[1]['respond'](['contents' => 'Greets.'], error: null);
        $this->editor->setSignatureHint('greet(string $who)');
        $this->editor->handleInput("\x1b");
        $this->assertStringNotContainsString('Greets.', $this->screen());
        $this->assertStringContainsString('greet(string $who)', $this->screen());
        $this->editor->setSignatureHint(null);

        // Showing it takes the completion list's place; a buffer swap takes it away.
        $this->editor->showCompletions([new CompletionItem('greeting', 'greeting', 'var', '')]);
        $this->editor->showDocPopup('Greets.');
        $this->assertFalse($this->editor->isCompletionOpen());
        $this->editor->openFile('/p/b.php', "<?php\n");
        $this->assertStringNotContainsString('Greets.', $this->screen());
        $this->editor->openFile('/p/a.php', "<?php\n\$x = 1;\n");

        $controller->requestHover(1, 0);
        \end($this->client->requests)['respond'](['contents' => 'Greets.'], error: null);
        $this->editor->handleInput('z');
        $this->assertStringNotContainsString('Greets.', $this->screen());
        $this->assertStringContainsString('z<?php', $this->screen(), 'the key typed');
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

        $picker = EditorState::pickerText($this->editor);
        $this->assertStringContainsString('Peek: a.php:2', $picker);
        $this->assertStringContainsString('function f() {}', $picker);
    }

    public function testPeekShowsAWHOLESmallDeclarationNotSixLinesOfIt(): void
    {
        // Six lines cut most method bodies in half; the overlay pages through
        // twelve at a time and can scroll the rest.
        $body = "<?php\nfunction f(): void\n{\n".\implode("\n", \array_map(static fn (int $n): string => "    \$s{$n} = {$n};", \range(start: 1, end: 12)))."\n}\n";
        $controller = $this->controller();
        $this->readyOn($controller, '/p/a.php', $body);

        $controller->requestPeek(0, 0);
        $this->client->requests[0]['respond']([
            'uri' => 'file:///p/a.php',
            'range' => ['start' => ['line' => 1, 'character' => 0], 'end' => ['line' => 1, 'character' => 8]],
        ], error: null);

        Probe::object($this->editor, 'overlayHost')->moveListSelection(15);

        $this->assertStringContainsString('$s12 = 12;', $this->pickerText(), 'the end of the body is reachable');
    }

    /** @return list<array{label: string, hint: string, value: string}> */
    private function overlayItems(): array
    {
        /** @var array{items: list<array{label: string, hint: string, value: string}>}|null $overlay */
        $overlay = Probe::get(Probe::object($this->editor, 'overlayHost'), 'overlay');
        $this->assertNotNull($overlay);

        return $overlay['items'];
    }

    private function peekAt(int $line, string $source): void
    {
        $controller = $this->controller();
        $this->readyOn($controller, '/p/a.php', $source);
        $controller->requestPeek($line, 0);
        $this->client->requests[0]['respond']([
            'uri' => 'file:///p/a.php',
            'range' => ['start' => ['line' => $line, 'character' => 0], 'end' => ['line' => $line, 'character' => 1]],
        ], error: null);
    }

    public function testPeekStopsAtAFIXEDWindowInAFileLongerThanIt(): void
    {
        // The window bound itself, which a short fixture cannot show: every
        // line exists, so nothing is trimmed and the last row IS the bound.
        $long = "<?php\n".\implode("\n", \array_map(static fn (int $n): string => "\$s{$n} = {$n};", \range(start: 1, end: 80)))."\n";
        $this->peekAt(1, $long);

        $items = $this->overlayItems();
        // One line before the target, then thirty after it.
        $this->assertSame('1', $items[0]['hint']);
        $this->assertSame((string) (1 + 30 + 1), \array_last($items)['hint']);
    }

    public function testTheWindowOpensExACTLYOneLineBeforeTheTarget(): void
    {
        // Asserted away from line 1, where `max(0, …)` clamps and hides an
        // off-by-one in the offset entirely.
        $long = "<?php\n".\implode("\n", \array_map(static fn (int $n): string => "\$s{$n} = {$n};", \range(start: 1, end: 40)))."\n";
        $this->peekAt(5, $long);

        // Target is line 5, so the window opens on line 4 — hint 5, one-based.
        $this->assertSame('5', $this->overlayItems()[0]['hint']);
    }

    public function testATargetOnTheFIRSTLineDoesNotOpenAboveTheFile(): void
    {
        // `max(0, …)` is the guard; without it the window starts at -1 and the
        // preview leads with a blank row for a line that does not exist.
        $this->peekAt(0, "<?php\n\$x = 1;\n\$y = 2;\n");

        $this->assertSame('1', $this->overlayItems()[0]['hint']);
    }

    public function testAWindowOfNothingButBlanksStopsAtTheMarkedRow(): void
    {
        // What stops the trailing-blank walk from emptying the list: the target
        // row carries the marker, so it is never blank however empty the file
        // around it is. An empty list would open no overlay at all.
        $this->peekAt(2, "\n\n\n\n");

        $items = $this->overlayItems();
        $this->assertSame(Glyphs::NAV_CURRENT, \trim(\array_last($items)['label']));
        $this->assertStringContainsString('Peek: a.php:3', $this->pickerText());
    }

    public function testTheTargetLineIsTheOneMarked(): void
    {
        $this->peekAt(1, "<?php\n\$x = 1;\n\$y = 2;\n");

        $items = $this->overlayItems();
        $this->assertStringStartsWith(Glyphs::NAV_CURRENT, $items[1]['label'], 'the definition line carries the marker');
        $this->assertStringStartsWith('  ', $items[0]['label'], 'and the context lines are indented past it');
    }

    public function testTrailingWhitespaceIsStrippedFromAPreviewRow(): void
    {
        // It would otherwise pad the popup out to the widest invisible line.
        $this->peekAt(1, "<?php\n\$x = 1;      \n\$y = 2;\n");

        $this->assertSame(Glyphs::NAV_CURRENT.' $x = 1;', $this->overlayItems()[1]['label']);
    }

    public function testPeekingAtABlankLineStillOpensTheOverlay(): void
    {
        // Every row in the window is blank, so the trim would empty the list
        // entirely — and an empty list opens nothing at all.
        $this->peekAt(1, "<?php\n\n\n");

        $this->assertStringContainsString('Peek: a.php:2', $this->pickerText());
    }

    public function testPeekStopsAtTheEndOfTheFileRatherThanPaddingWithBlanks(): void
    {
        // The reader answers '' both for a blank line and for one past the end,
        // so the trailing run is dropped — otherwise a two-line file would peek
        // thirty rows of nothing.
        $controller = $this->controller();
        $this->readyOn($controller, '/p/a.php', "<?php\n\$x = 1;\n");

        $controller->requestPeek(1, 0);
        $this->client->requests[0]['respond']([
            'uri' => 'file:///p/a.php',
            'range' => ['start' => ['line' => 1, 'character' => 0], 'end' => ['line' => 1, 'character' => 2]],
        ], error: null);

        $rows = Probe::object($this->editor, 'overlayHost');
        /** @var array{items: list<array{label: string, hint: string, value: string}>}|null $overlay */
        $overlay = Probe::get($rows, 'overlay');
        $this->assertNotNull($overlay);
        $this->assertSame(['<?php', '$x = 1;'], \array_map(static fn (array $i): string => \trim(\mb_substr($i['label'], start: 2)), $overlay['items']));
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

        $picker = EditorState::pickerText($this->editor);
        $this->assertStringContainsString('Foo', $picker);

        $this->editor->handleInput("\r"); // pick the only row
        $this->assertSame(1, $this->editor->getDocument()->cursorLine, 'picking a row jumps to its position');
        $this->assertSame(6, $this->editor->getDocument()->cursorCol);
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
        $project = \sys_get_temp_dir().'/nav-line-at-'.\bin2hex(\random_bytes(4));
        \mkdir($project);
        try {
            $path = $project.'/a.php';
            \file_put_contents($path, data: "on-disk line 0\non-disk line 1\n");

            $controller = $this->controller();
            $this->buffers->open($path, "buffer line 0\nbuffer line 1\n");
            $this->assertSame('buffer line 1', Probe::invoke($controller, 'lineAt', $path, 1), 'the live (unsaved) buffer wins over the on-disk file');
        } finally {
            if (\is_file($project.'/a.php')) {
                \unlink($project.'/a.php');
            }
            if (\is_dir($project)) {
                \rmdir($project);
            }
        }
    }

    public function testLineAtFallsBackToDiskWhenNoBufferIsOpen(): void
    {
        $project = \sys_get_temp_dir().'/nav-line-at-'.\bin2hex(\random_bytes(4));
        \mkdir($project);
        try {
            $path = $project.'/a.php';
            \file_put_contents($path, data: "on-disk line 0\non-disk line 1\n");

            $controller = $this->controller();
            $this->assertSame('on-disk line 1', Probe::invoke($controller, 'lineAt', $path, 1));
        } finally {
            if (\is_file($project.'/a.php')) {
                \unlink($project.'/a.php');
            }
            if (\is_dir($project)) {
                \rmdir($project);
            }
        }
    }

    public function testLineAtOnAMissingPathReturnsEmpty(): void
    {
        $controller = $this->controller();

        $this->assertSame('', Probe::invoke($controller, 'lineAt', '/p/does-not-exist.php', 0));
    }

    public function testLineAtWithAnOutOfRangeLineReturnsEmpty(): void
    {
        $project = \sys_get_temp_dir().'/nav-line-at-'.\bin2hex(\random_bytes(4));
        \mkdir($project);
        try {
            $path = $project.'/a.php';
            \file_put_contents($path, data: "only line\n");

            $controller = $this->controller();
            $this->assertSame('', Probe::invoke($controller, 'lineAt', $path, 5));
        } finally {
            if (\is_file($project.'/a.php')) {
                \unlink($project.'/a.php');
            }
            if (\is_dir($project)) {
                \rmdir($project);
            }
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
        return AnsiUtils::stripAnsiCodes(\implode("\n", $this->results->render(new RenderContext(100, 12))));
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

    /** @return \Generator<string, array{string, string, string}> */
    public static function callDirections(): \Generator
    {
        yield 'incoming' => ['requestCallHierarchy', 'callHierarchy/incomingCalls', 'from'];
        yield 'outgoing' => ['requestOutgoingCalls', 'callHierarchy/outgoingCalls', 'to'];
    }

    /**
     * The two directions differ only in the request sent and which END of each
     * call carries the symbol — `from` for a caller, `to` for a callee. Reading
     * the wrong side yields an empty list and the not-found flash, so the row
     * content is what discriminates.
     */
    #[DataProvider('callDirections')]
    public function testBothCallDirectionsReadTheirOwnEndOfEachCall(string $method, string $request, string $side): void
    {
        $controller = $this->controller();
        $this->readyOn($controller, '/p/a.php', "<?php\nfunction greet() {}\n");

        $controller->{$method}(1, 9);
        $this->client->requests[0]['respond']([['name' => 'greet', 'kind' => 12, 'uri' => 'file:///p/a.php']], error: null);

        $this->assertSame($request, $this->client->requests[1]['method']);
        $this->client->requests[1]['respond']([[
            $side => [
                'name' => 'other',
                'uri' => 'file:///p/a.php',
                'selectionRange' => ['start' => ['line' => 1, 'character' => 9], 'end' => ['line' => 1, 'character' => 14]],
            ],
        ]], error: null);

        // Grouped now, like the Find and Problems panels: the FILE is the
        // header and the row carries the line under it, so the old flat
        // `a.php:2` spelling is gone by design.
        $panel = $this->panel();
        $this->assertStringContainsString('a.php', $panel, 'the file heads its group');
        $this->assertStringContainsString('2', $panel, 'and the call landed as a jumpable row under it');
        $this->assertMatchesRegularExpression('/^[╭┏] Filter [─━]/mu', $panel, 'a hierarchy runs long enough to need narrowing: the filter box is drawn');
    }

    /**
     * The case BOTH bundled PHP servers are in: probed 2026-09-02, neither
     * phpantom nor phpactor advertises callHierarchyProvider. Ungated, the
     * request went out and the empty answer flashed "No callers found" — which
     * reads as "this symbol has none" rather than "nothing was asked".
     */
    #[DataProvider('callDirections')]
    public function testAServerWithoutCallHierarchySaysSoInsteadOfClaimingNoCallers(string $method, string $request, string $side): void
    {
        $this->capabilities = []; // no callHierarchyProvider
        $controller = $this->controller();
        $this->readyOn($controller, '/p/a.php', "<?php\nfunction greet() {}\n");

        $controller->{$method}(1, 9);

        $this->assertSame([], $this->client->requests, \sprintf('nothing was asked of a server that cannot answer %s (%s)', $request, $side));
        $flashed = $this->flashed();
        $this->assertStringContainsString('no call hierarchy', $flashed);
        $this->assertStringNotContainsString('No callers found', $flashed, 'and it does not claim the symbol has none');
    }

    /** An answer whose calls carry the other end is no answer at all. */
    public function testOutgoingCallsReadingAnIncomingShapedAnswerFlashesNotFound(): void
    {
        $controller = $this->controller();
        $this->readyOn($controller, '/p/a.php', "<?php\nfunction greet() {}\n");

        $controller->requestOutgoingCalls(1, 9);
        $this->client->requests[0]['respond']([['name' => 'greet', 'kind' => 12, 'uri' => 'file:///p/a.php']], error: null);
        // `from`, not `to`: the incoming shape.
        $this->client->requests[1]['respond']([['from' => ['name' => 'x', 'uri' => 'file:///p/a.php', 'selectionRange' => []]]], error: null);

        $this->assertStringNotContainsString('a.php:', $this->panel(), 'nothing is listed from the end it was not asked about');
    }

    /** The results panel's rendered rows. */
    private function panel(): string
    {
        return AnsiUtils::stripAnsiCodes(\implode("\n", $this->results->render(new RenderContext(100, 12))));
    }

    /**
     * The panel assertions above read RENDERED text, which cannot see the row
     * shape underneath: the path each row carries, the indent depth the flatten
     * starts at, or which side of the label the kind hint sits on. All three
     * could be changed with those assertions still green.
     */
    public function testStructureRowsCarryTheirPathDepthAndHintInOrder(): void
    {
        $controller = $this->controller();
        $this->readyOn($controller, '/p/a.php', "<?php\n\n\n\n\n");

        $controller->requestStructurePanel();
        $this->client->requests[0]['respond']($this->symbolTree(), error: null);

        $rows = $this->dock->displayedRows(ResultsPanelMode::Structure);
        $this->assertSame(
            [
                ['path' => 'a.php', 'line' => 2, 'text' => 'Greeter  class'],
                ['path' => 'a.php', 'line' => 4, 'text' => '  greet  method'],
            ],
            $rows,
            'the file it is about, the top level at depth 0, and the hint after the label',
        );
    }

    /**
     * The call-hierarchy title names the symbol. Dropping the interpolation
     * leaves the raw "Callers of %symbol%" on the tab, which the row
     * assertions never look at.
     */
    #[DataProvider('callDirections')]
    public function testTheCallHierarchyTitleNamesTheSymbol(string $method, string $request, string $side): void
    {
        $controller = $this->controller();
        $this->readyOn($controller, '/p/a.php', "<?php\nfunction greet() {}\n");

        $controller->{$method}(1, 9);
        $this->client->requests[0]['respond']([['name' => 'greet', 'kind' => 12, 'uri' => 'file:///p/a.php']], error: null);
        $this->client->requests[1]['respond']([[
            $side => ['name' => 'other', 'uri' => 'file:///p/a.php', 'selectionRange' => ['start' => ['line' => 1, 'character' => 9], 'end' => ['line' => 1, 'character' => 14]]],
        ]], error: null);

        $title = (string) $this->dock->activeTool()?->title;
        $this->assertStringContainsString('greet', $title, $request.' names the symbol it is about');
        $this->assertStringNotContainsString('%symbol%', $title);
    }
}
