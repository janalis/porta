<?php

declare(strict_types=1);

namespace Porta\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\Config\CommandRegistry;
use Porta\Config\ConfigScope;
use Porta\Editor\BufferManager;
use Porta\Editor\BufferService;
use Porta\Editor\Document;
use Porta\Editor\OpenFocus;
use Porta\History\SessionRestorer;
use Porta\Ide;
use Porta\Lsp\LspMapper;
use Porta\Lsp\LspSessionPool;
use Porta\Lsp\NavigationController;
use Porta\Lsp\RefactorController;
use Porta\Lsp\Uri;
use Porta\Lsp\WorkspaceEditor;
use Porta\Onboarding\OnboardingState;
use Porta\Search\SearchActions;
use Porta\Settings\SettingsScope;
use Porta\Support\ProjectPaths;
use Porta\Tests\Support\AwaitsGit;
use Porta\Tests\Support\EditorState;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\PumpsLoop;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Porta\Workbench\Notifier;
use Porta\Workbench\View\ViewRegistry;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * Direct tests for Ide's private pure helpers (via reflection) and for
 * WorkspaceEdit application, which has no UI dependency.
 */
final class IdeHelpersTest extends TestCase
{
    use AwaitsGit;
    use PumpsLoop;

    private TempProject $project;
    private Ide $ide;

    protected function setUp(): void
    {
        $this->project = new TempProject(['composer.json' => '{}']);
        new OnboardingState($this->project->dir)->markWelcomeSeen();
        $this->ide = new Ide($this->project->dir, null, new VirtualTerminal(columns: 80, rows: 24));
        // Palette commands below toggle real settings, and the session scope
        // defaults to Global — the suite's SHARED fixture home. Pin the writes
        // to this project, which goes away with its temp dir.
        Services::resolve($this->ide, SettingsScope::class)->set(ConfigScope::Project);
    }

    /**
     * These tests BUILD an Ide but never run() it, so the teardown in its
     * finally never happens — and the helpers they invoke arm the same timers
     * and subprocess watchers a real session would. This is that finally, in
     * the three parts a non-running IDE can still have armed.
     */
    protected function tearDown(): void
    {
        Services::resolve($this->ide, ViewRegistry::class)->closeAll();
        Services::resolve($this->ide, Notifier::class)->dismissAll();
    }

    private function call(string $method, mixed ...$args): mixed
    {
        return Probe::invoke($this->ide, $method, ...$args);
    }

    public function testServicesSeamReturnsLocatorMembersAndSynthetics(): void
    {
        // A porta.ide locator member resolves through the locator branch...
        // ...and a synthetic (Renderer) falls back to the container branch —
        // the empirical proof that PRIVATE synthetics stay get()-able once
        // Ide's constructor has set() them.
        self::assertInstanceOf(Renderer::class, Services::resolve($this->ide, Renderer::class));
        self::assertSame(Services::resolve($this->ide, Renderer::class), Services::resolve($this->ide, Renderer::class), 'the same shared instance every time');
    }

    public function testANonBlockingTerminalGetsThePendingBytesProbe(): void
    {
        $stream = fopen('php://memory', mode: 'w+');
        \assert(false !== $stream, description: 'php://memory always opens');
        $terminal = new \Porta\Ui\NonBlockingTerminal(new VirtualTerminal(columns: 80, rows: 24), $stream);
        $ide = new Ide($this->project->dir, null, $terminal);
        try {
            $perf = Services::resolve($ide, \Porta\Support\PerfCollector::class);
            self::assertNotNull(Probe::get($perf, 'pendingBytes'), 'the real-terminal boot wired the backlog probe');
        } finally {
            Services::resolve($ide, ViewRegistry::class)->closeAll();
            Services::resolve($ide, Notifier::class)->dismissAll();
        }
    }

    public function testThePerfCollectorIsWiredAsSinkAndInputListener(): void
    {
        $tui = Services::resolve($this->ide, Tui::class);
        $perf = Services::resolve($this->ide, \Porta\Support\PerfCollector::class);

        // The sink handed to BidiTui is the shared collector instance...
        self::assertSame($perf, Probe::get($tui, 'perf'), 'setPerfSink received the collector');
        self::assertNotNull(Probe::get($tui, 'clock'), 'Ide wired the frame pacing clock');
        self::assertNotNull(Probe::get($tui, 'scheduler'), 'Ide wired the frame pacing scheduler');

        // ...and the high-priority listener counts a wheel event even though
        // the input dispatcher stops propagation on the mouse path.
        $tui->handleInput("\x1b[<65;2;2M");
        self::assertSame(1, Probe::get($perf, 'wheels'), 'the wheel notch was counted before the dispatcher swallowed it');
        self::assertSame(1, Probe::get($perf, 'inputs'));
    }

    private function workspaceEditor(): WorkspaceEditor
    {
        return Services::resolve($this->ide, WorkspaceEditor::class);
    }

    private function navigation(): NavigationController
    {
        return Services::resolve($this->ide, NavigationController::class);
    }

    private function callNav(string $method, mixed ...$args): mixed
    {
        $nav = $this->navigation();

        return Probe::invoke($nav, $method, ...$args);
    }

    private function refactor(): RefactorController
    {
        return Services::resolve($this->ide, RefactorController::class);
    }

    private function callRefactor(string $method, mixed ...$args): mixed
    {
        $refactor = $this->refactor();

        return Probe::invoke($refactor, $method, ...$args);
    }

    public function testFirstLocationVariants(): void
    {
        $this->assertNull(LspMapper::firstLocation(null));
        $this->assertNull(LspMapper::firstLocation([]));
        $this->assertNull(LspMapper::firstLocation(['what']), 'non-array candidate');
        $this->assertNull(LspMapper::firstLocation([['neither' => 'shape']]));

        $location = ['uri' => 'file:///x.php', 'range' => ['start' => ['line' => 1]]];
        $this->assertSame($location, LspMapper::firstLocation($location), 'bare Location');
        $this->assertSame($location, LspMapper::firstLocation([$location]), 'Location[]');

        $link = ['targetUri' => 'file:///y.php', 'targetSelectionRange' => ['start' => ['line' => 2]]];
        $this->assertSame(
            ['uri' => 'file:///y.php', 'range' => ['start' => ['line' => 2]]],
            LspMapper::firstLocation([$link]),
            'LocationLink[]',
        );

        $linkNoSelection = ['targetUri' => 'file:///z.php', 'targetRange' => ['start' => ['line' => 3]]];
        $this->assertSame(['start' => ['line' => 3]], LspMapper::firstLocation([$linkNoSelection])['range']);

        $this->assertSame(
            $location,
            LspMapper::firstLocation([['bogus' => 1], $location]),
            'a malformed leading element no longer masks a valid later one',
        );
    }

    public function testRangeContains(): void
    {
        // Single-line name range: cols 6..12 on line 3.
        $range = ['start' => ['line' => 3, 'character' => 6], 'end' => ['line' => 3, 'character' => 12]];
        $this->assertFalse(LspMapper::rangeContains($range, 3, 5), 'before the start column');
        $this->assertTrue(LspMapper::rangeContains($range, 3, 6), 'at the start column');
        $this->assertTrue(LspMapper::rangeContains($range, 3, 9), 'inside');
        $this->assertTrue(LspMapper::rangeContains($range, 3, 12), 'end column is inclusive (caret just past the identifier)');
        $this->assertFalse(LspMapper::rangeContains($range, 3, 13), 'past the end column');
        $this->assertFalse(LspMapper::rangeContains($range, 2, 9), 'line before');
        $this->assertFalse(LspMapper::rangeContains($range, 4, 9), 'line after');

        // Multi-line range: any column on a middle line is inside.
        $block = ['start' => ['line' => 1, 'character' => 8], 'end' => ['line' => 5, 'character' => 2]];
        $this->assertTrue(LspMapper::rangeContains($block, 3, 0), 'middle line, column irrelevant');
        $this->assertFalse(LspMapper::rangeContains($block, 1, 7), 'first line before the start column');
        $this->assertTrue(LspMapper::rangeContains($block, 5, 2), 'last line at the inclusive end');
        $this->assertFalse(LspMapper::rangeContains($block, 5, 3), 'last line past the end');

        // Missing/malformed parts default to 0: only 0:0 is contained.
        $this->assertTrue(LspMapper::rangeContains([], 0, 0));
        $this->assertFalse(LspMapper::rangeContains([], 0, 1));
        $this->assertFalse(LspMapper::rangeContains(['start' => 'junk', 'end' => 'junk'], 1, 0), 'non-array parts');
    }

    public function testHoverToTextVariants(): void
    {
        $this->assertNull(LspMapper::hoverToText(null));
        $this->assertNull(LspMapper::hoverToText('not an array'));
        $this->assertNull(LspMapper::hoverToText(['contents' => '']));

        $this->assertSame('plain string', LspMapper::hoverToText(['contents' => 'plain string']));
        $this->assertSame('markup value', LspMapper::hoverToText(['contents' => ['kind' => 'plaintext', 'value' => 'markup value']]));
        $this->assertSame(
            "one\ntwo",
            LspMapper::hoverToText(['contents' => ['one', ['value' => 'two']]]),
            'mixed MarkedString array',
        );

        $fenced = LspMapper::hoverToText(['contents' => "```php\ncode()\n```\n\n\n\ndocs"]);
        $this->assertStringNotContainsString('```', $fenced);
        $this->assertStringNotContainsString("\n\n\n", $fenced);
    }

    public function testUriToPath(): void
    {
        $this->assertSame('/a/b c.php', Uri::toPath('file:///a/b%20c.php'));
        $this->assertNull(Uri::toPath('untitled:scratch'));
        $this->assertNull(Uri::toPath('file://'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function languageIdCases(): iterable
    {
        yield 'php' => ['x.php', 'php'];
        yield 'phtml' => ['x.phtml', 'php'];
        yield 'js' => ['x.js', 'javascript'];
        yield 'mjs' => ['x.mjs', 'javascript'];
        yield 'ts' => ['x.ts', 'typescript'];
        yield 'json' => ['x.json', 'json'];
        yield 'css' => ['x.css', 'css'];
        yield 'html' => ['x.html', 'html'];
        yield 'htm' => ['x.htm', 'html'];
        yield 'markdown' => ['x.md', 'markdown'];
        yield 'yaml' => ['x.yaml', 'yaml'];
        yield 'yml' => ['x.yml', 'yaml'];
        yield 'twig' => ['x.twig', 'twig'];
        yield 'python' => ['x.py', 'python'];
        yield 'scss' => ['x.scss', 'scss'];
        yield 'unknown' => ['x.weird', 'plaintext'];
    }

    #[DataProvider('languageIdCases')]
    public function testLanguageIdMap(string $file, string $expected): void
    {
        $this->assertSame($expected, ProjectPaths::languageId($file), $file);
    }

    public function testRelativePathOutsideRoot(): void
    {
        $this->assertSame('/elsewhere/file.php', new ProjectPaths($this->project->dir)->relative('/elsewhere/file.php'));
    }

    public function testRelativeMemoizesResolvedPathsButNeverUnresolvedOnes(): void
    {
        $paths = new ProjectPaths($this->project->dir);
        $missing = $this->project->dir.'/late.php';

        // Not on disk yet: realpath fails, the raw path comes back — and it
        // must NOT be cached, or the file's creation would never be seen.
        $this->assertSame($missing, $paths->relative($missing));

        file_put_contents($missing, data: '');
        $this->assertSame('late.php', $paths->relative($missing), 'once the file exists, the same call resolves relative');
        $this->assertSame('late.php', $paths->relative($missing), 'and the memoized answer matches');

        // At the cap the memo flushes wholesale instead of growing unbounded.
        $full = array_fill_keys(array_map(static fn (int $i): string => "/stale/{$i}", range(start: 1, end: 1024)), value: 'stale');
        Probe::set($paths, 'relativeMemo', $full);
        $this->assertSame('late.php', $paths->relative($missing), 'a full memo still answers correctly');
        $this->assertCount(1, (array) Probe::get($paths, 'relativeMemo'), 'the flush kept only the fresh entry');
    }

    public function testLineAtReadsFromDiskForUnopenedFiles(): void
    {
        $path = $this->project->write('src/OnDisk.php', "<?php\nsecond line\n");

        $this->assertSame('second line', $this->callNav('lineAt', $path, 1));
        $this->assertSame('', $this->callNav('lineAt', $path, 99));
        $this->assertSame('', $this->callNav('lineAt', $this->project->path('missing.php'), 0));
    }

    public function testApplyTextEditsSortsBottomUp(): void
    {
        $doc = new Document("alpha beta\ngamma delta");
        $this->workspaceEditor()->applyTextEdits($doc, [
            // Given top-down; must be applied bottom-up to keep offsets valid
            ['range' => ['start' => ['line' => 0, 'character' => 0], 'end' => ['line' => 0, 'character' => 5]], 'newText' => 'A'],
            ['range' => ['start' => ['line' => 1, 'character' => 6], 'end' => ['line' => 1, 'character' => 11]], 'newText' => 'D'],
        ]);

        $this->assertSame("A beta\ngamma D", $doc->getText());
    }

    public function testApplyWorkspaceEditOnDiskAndInvalidTargets(): void
    {
        $onDisk = $this->project->write('src/Disk.php', "<?php\nclass Disk {}\n");
        $uri = 'file://'.implode('/', array_map(rawurlencode(...), explode('/', $onDisk)));

        $count = $this->workspaceEditor()->applyWorkspaceEdit([
            'changes' => [
                $uri => [['range' => ['start' => ['line' => 1, 'character' => 6], 'end' => ['line' => 1, 'character' => 10]], 'newText' => 'Renamed']],
                'untitled:nope' => [['range' => ['start' => ['line' => 0, 'character' => 0], 'end' => ['line' => 0, 'character' => 1]], 'newText' => 'x']],
                'file:///does/not/exist.php' => [['range' => ['start' => ['line' => 0, 'character' => 0], 'end' => ['line' => 0, 'character' => 0]], 'newText' => 'x']],
            ],
        ]);

        $this->assertSame(1, $count);
        $this->assertStringContainsString('class Renamed', (string) file_get_contents($onDisk));
    }

    public function testApplyWorkspaceEditDocumentChangesFormat(): void
    {
        $onDisk = $this->project->write('src/DC.php', "abc\n");
        $uri = 'file://'.implode('/', array_map(rawurlencode(...), explode('/', $onDisk)));

        $count = $this->workspaceEditor()->applyWorkspaceEdit([
            'documentChanges' => [
                [
                    'textDocument' => ['uri' => $uri, 'version' => 1],
                    'edits' => [
                        ['range' => ['start' => ['line' => 0, 'character' => 0], 'end' => ['line' => 0, 'character' => 3]], 'newText' => 'xyz'],
                    ],
                ],
                ['noTextDocument' => true], // ignored shape
            ],
        ]);

        $this->assertSame(1, $count);
        $this->assertStringContainsString('xyz', (string) file_get_contents($onDisk));
    }

    public function testApplyWorkspaceEditWithEmptyEditListIsSkipped(): void
    {
        $onDisk = $this->project->write('src/Empty.php', "abc\n");
        $uri = 'file://'.implode('/', array_map(rawurlencode(...), explode('/', $onDisk)));

        $this->assertSame(0, $this->workspaceEditor()->applyWorkspaceEdit(['changes' => [$uri => []]]));
    }

    public function testApplyCodeActionWithNoEffect(): void
    {
        // No LSP is running (constructed with a bare project), so a bare
        // command cannot execute and there is no edit: the "nothing to
        // apply" branch runs without error.
        $this->callRefactor('applyCodeAction', ['title' => 'Inert action']);
        $this->addToAssertionCount(1);
    }

    public function testRequestCodeActionsWithoutLspIsNoOp(): void
    {
        // No file open and no LSP: the guard returns immediately.
        $this->refactor()->requestCodeActions(0, 0);
        $this->addToAssertionCount(1);
    }

    public function testRequestFileStructureWithoutLspIsNoOp(): void
    {
        $this->navigation()->requestFileStructure();
        $this->addToAssertionCount(1);
    }

    public function testRequestPeekWithoutLspIsNoOp(): void
    {
        $this->navigation()->requestPeek(0, 0);
        $this->addToAssertionCount(1);
    }

    public function testRestoreSessionSkipsMissingFiles(): void
    {
        // Write a session referencing one real and one missing file.
        $real = $this->project->write('src/Real.php', "hi\n");
        new \Porta\History\SessionStore($this->project->dir)->save([
            ['path' => '/does/not/exist.php', 'line' => 0, 'col' => 0],
            ['path' => $real, 'line' => 0, 'col' => 1],
        ], $real);

        $restorer = Services::resolve($this->ide, SessionRestorer::class);
        \assert($restorer instanceof \Porta\History\SessionRestorer, description: 'the sessionRestorer property holds a SessionRestorer');
        $this->assertTrue($restorer->restore(), 'restored at least the real file');
        $this->assertStringEndsWith('Real.php', (string) Services::resolve($this->ide, Tui::class)->getById('editor')->getFilePath());
    }

    public function testRestoreWithEveryBufferMissingRestoresNothing(): void
    {
        $tui = Services::resolve($this->ide, Tui::class);
        $focusBefore = $tui->getFocus();

        new \Porta\History\SessionStore($this->project->dir)->save([
            ['path' => '/does/not/exist-a.php', 'line' => 0, 'col' => 0],
            ['path' => '/does/not/exist-b.php', 'line' => 0, 'col' => 0],
        ], '/does/not/exist-a.php');

        $restorer = Services::resolve($this->ide, SessionRestorer::class);
        $this->assertFalse($restorer->restore(), 'nothing on disk was actually opened');
        $this->assertSame($focusBefore, $tui->getFocus(), 'focus never moved to the editor');
    }

    public function testRestoreSetsTheCursorFromTheRecordedPosition(): void
    {
        $real = $this->project->write('src/Cursor.php', "line0\nline1\nline2\nline3\n");
        new \Porta\History\SessionStore($this->project->dir)->save([
            ['path' => $real, 'line' => 3, 'col' => 5],
        ], null);

        Services::resolve($this->ide, SessionRestorer::class)->restore();

        $document = Services::resolve($this->ide, Tui::class)->getById('editor')->getDocument();
        $this->assertSame(3, $document->getCursorLine());
        $this->assertSame(5, $document->getCursorCol());
    }

    public function testRestoreWithNoRecordedActiveBufferDoesNotCrash(): void
    {
        // $activePath is null: a realpath(null) call here would be a
        // TypeError under strict_types — this is that guard's own test.
        $real = $this->project->write('src/NoActive.php', "hi\n");
        new \Porta\History\SessionStore($this->project->dir)->save([
            ['path' => $real, 'line' => 0, 'col' => 0],
        ], null);

        $this->assertTrue(Services::resolve($this->ide, SessionRestorer::class)->restore());
    }

    public function testRestoreReactivatesTheRecordedActiveBufferNotJustTheLastOpenedOne(): void
    {
        // Two buffers open — BufferManager's own open() would otherwise
        // leave the LAST one active by default. The session recorded the
        // FIRST one as active, and its path is spelled with a `./` segment
        // realpath() strips — only a resolved (not raw) key correctly
        // matches what BufferManager actually opened.
        $first = $this->project->write('src/First.php', "hi\n");
        $second = $this->project->write('src/Second.php', "hi\n");
        $messyActivePath = \dirname($first).'/./'.\basename($first);

        new \Porta\History\SessionStore($this->project->dir)->save([
            ['path' => $first, 'line' => 0, 'col' => 0],
            ['path' => $second, 'line' => 0, 'col' => 0],
        ], $messyActivePath);

        Services::resolve($this->ide, SessionRestorer::class)->restore();

        $tui = Services::resolve($this->ide, Tui::class);
        $this->assertStringEndsWith('First.php', (string) $tui->getById('editor')->getFilePath(), 'the recorded active buffer wins over the last-opened one');
        $this->assertSame(Services::resolve($this->ide, \Porta\Workbench\EditorArea::class)->widget(), $tui->getFocus(), 'focus lands on the editor area');
    }

    public function testProviderPriorityOrderMatchesTheContractualPaletteOrder(): void
    {
        $registry = Services::resolve($this->ide, CommandRegistry::class);
        \assert($registry instanceof \Porta\Config\CommandRegistry, description: 'the IDE exposes a CommandRegistry');
        $labels = array_column($registry->paletteCommands(), 'label');

        // The tagged providers register in #[AsTaggedItem] priority order:
        // registration order IS the palette display order — file commands
        // first, git commands last.
        // This project is not a repository, so the git group registers nothing
        // and the View group's last entry is the tail. The git half of the
        // contract needs a repo fixture and is asserted in IdeGitFlowsTest.
        $this->assertSame('Debug Current File', $labels[0], 'the debug group has always led the palette');
        $this->assertSame('Documentation', $labels[array_key_last($labels)], 'view is the last group that registers here');
        $this->assertLessThan(array_search('Show Problems', $labels, strict: true), array_search('Save All', $labels, strict: true), 'file group precedes the search group');
        $this->assertNotContains('Git: Stage Current File', $labels, 'and git registers nothing outside a repository');
    }

    public function testEveryPaletteCommandRuns(): void
    {
        $tui = Services::resolve($this->ide, Tui::class);
        $registry = Services::resolve($this->ide, CommandRegistry::class);
        $commands = $registry->paletteCommands();

        $byLabel = [];
        foreach ($commands as $command) {
            $byLabel[$command['label']] = $command['run'];
        }

        // Find in Project opens the centered modal (composited over the editor);
        // the other panel-opening commands focus their widget.
        $byLabel['Find in Project']();
        $this->assertTrue($tui->getById('editor')->isFindModalOpen(), 'find-in-project opens the modal');
        $tui->getById('editor')->findModal()->close();

        $byLabel['Go to Symbol in Project']();
        $this->assertTrue($tui->getById('editor')->isSearchOverlayOpen(), 'go-to-symbol opens the centered picker over the editor');
        $tui->getById('editor')->closeSearch();

        $byLabel['Go to File']();
        $this->assertTrue($tui->getById('editor')->isSearchOverlayOpen(), 'go-to-file opens the centered picker over the editor');
        $tui->getById('editor')->closeSearch();

        // Cycle line numbers (absolute → relative) and toggle a bool setting.
        $editor = $tui->getById('editor');
        $before = EditorState::display($editor)->lineNumbers();
        $byLabel['Cycle Line Numbers']();
        $this->assertNotSame($before, EditorState::display($editor)->lineNumbers());

        $byLabel['Toggle Inline Diagnostics']();
        // Toggling back and forth is a no-op-free round trip.
        $byLabel['Toggle Inline Diagnostics']();

        $autoBefore = EditorState::display($editor)->autoPairs();
        $byLabel['Toggle Auto-Pairs']();
        $this->assertNotSame($autoBefore, EditorState::display($editor)->autoPairs());

        // Save-family and reformat commands are safe no-ops without a file/LSP.
        $byLabel['Save File']();
        $byLabel['Save All']();
        $byLabel['Reformat File']();
        $byLabel['Show Problems']();

        // Tab-closing commands: with buffers open, Close Others keeps the
        // active one; Close All empties the strip; Close Others with nothing
        // active is a guarded no-op.
        $buffers = Services::resolve($this->ide, BufferManager::class);
        $service = Services::resolve($this->ide, BufferService::class);
        $service->openFile($this->project->write('one.txt', "a\n"));
        $service->openFile($this->project->write('two.txt', "b\n"));
        $byLabel['Close Other Tabs']();
        $this->assertCount(1, $buffers->all(), 'only the active tab survives');
        $byLabel['Close All Tabs']();
        $this->assertCount(0, $buffers->all());
        $byLabel['Close Other Tabs'](); // nothing active: guarded no-op
        $this->assertCount(0, $buffers->all());
    }

    public function testFlattenSymbolsWithMinimalShapes(): void
    {
        $items = [];
        LspMapper::flattenSymbols(
            [
                'not an array',
                ['name' => ''], // skipped
                ['name' => 'Bare'], // no range at all -> defaults to 0:0
                ['name' => 'WithLoc', 'kind' => 5, 'location' => ['range' => ['start' => ['line' => 7, 'character' => 2]]]],
            ],
            0,
            $items,
        );

        $this->assertCount(2, $items);
        $this->assertSame('0:0', $items[0]['value']);
        $this->assertSame('7:2', $items[1]['value']);
    }

    public function testApplyCodeActionBareCommandAndCommandError(): void
    {
        // Inject a started mock LSP so the command paths run for real.
        $client = new \Porta\Lsp\LspClient(
            [\PHP_BINARY, \dirname(__DIR__).'/Support/command-lsp-server.php'],
            $this->project->dir,
        );
        $ready = false;
        $client->start(static function () use (&$ready): void {
            $ready = true;
        });
        $this->await(static function () use (&$ready): bool {
            return $ready;
        });
        $this->injectLspClient($client);

        $results = [];
        $client->onNotification('mock/ran', static function (array $p) use (&$results): void {
            $results[] = $p['command'];
        });

        // Bare string command (normalized to a command array — line 980)
        $this->callRefactor('applyCodeAction', ['title' => 'Bare', 'command' => 'mock.ok']);
        // Command the server rejects (error branch — line 989)
        $this->callRefactor('applyCodeAction', ['title' => 'Boom', 'command' => ['command' => 'mock.fail']]);

        $this->await(static function () use (&$results): bool {
            return \count($results) >= 1;
        });
        $this->assertContains('mock.ok', $results);

        $client->stop();
    }

    /** Inject a live client into the Ide's session pool so feature code runs for real. */
    private function injectLspClient(\Porta\Lsp\LspClient $client): void
    {
        $session = new \Porta\Lsp\LspSession(sys_get_temp_dir());
        Probe::set($session, 'client', $client);

        $pool = Services::resolve($this->ide, LspSessionPool::class);
        Probe::set($pool, 'servers', ['*' => ['command' => ['injected'], 'name' => 'injected']]);
        // The key mirrors LspSessionPool::signature(): command + "\0" + json of the (absent) options.
        Probe::set($pool, 'sessions', ["injected\0null" => $session]);
        Probe::set($pool, 'configured', true);
    }

    public function testShowProblemsAggregatesAndSortsWithSeverityMarkers(): void
    {
        $buffers = Services::resolve($this->ide, BufferManager::class);
        $b = $buffers->open($this->project->path('src/B.php'), "<?php\n");
        $b->diagnostics = [5 => [['severity' => 2, 'message' => 'warn here', 'col' => 0]]];
        $a = $buffers->open($this->project->path('src/A.php'), "<?php\n");
        $a->diagnostics = [
            2 => [['severity' => 1, 'message' => 'error here', 'col' => 0]],
            0 => [['severity' => 1, 'message' => 'top error', 'col' => 0]],
        ];

        Services::resolve($this->ide, SearchActions::class)->showProblems();

        $panel = Services::resolve($this->ide, Tui::class)->getById('results');
        $this->assertTrue($panel->isOpen());
        $rendered = \Symfony\Component\Tui\Ansi\AnsiUtils::stripAnsiCodes(
            implode("\n", $panel->render(new \Symfony\Component\Tui\Render\RenderContext(120, 14))),
        );
        $this->assertSame('Problems', $panel->getTitle(), 'the dock tab carries the tool title');
        $this->assertStringContainsString('✗ top error', $rendered);
        $this->assertStringContainsString('▲ warn here', $rendered);
        // A.php sorts before B.php; within A.php line 0 before line 2
        $this->assertLessThan(strpos($rendered, needle: 'error here'), strpos($rendered, needle: 'top error'));
        $this->assertLessThan(strpos($rendered, needle: 'warn here'), strpos($rendered, needle: 'top error'));
    }

    public function testGoToTestWithNoFileOpenFlashesNoTestFound(): void
    {
        Services::resolve($this->ide, SearchActions::class)->goToTest();

        $this->assertStringContainsString('No test found', \Porta\Tests\Support\Toasts::last($this->ide));
    }

    public function testGoToTestFromATestFileWithNoSubjectFlashesTheTestVariant(): void
    {
        $buffers = Services::resolve($this->ide, BufferService::class);
        $buffers->openFile($this->project->write('tests/OrphanTest.php', "<?php\n"), focus: OpenFocus::Editor);

        Services::resolve($this->ide, SearchActions::class)->goToTest();

        $this->assertStringContainsString('No test subject found', \Porta\Tests\Support\Toasts::last($this->ide), 'from a test file, the message names the subject, not the test');
    }

    public function testGoToTestWithExactlyOneCandidateOpensItDirectly(): void
    {
        $buffers = Services::resolve($this->ide, BufferService::class);
        $this->project->write('tests/FooTest.php', "<?php\n");
        $buffers->openFile($this->project->write('src/Foo.php', "<?php\n"), focus: OpenFocus::Editor);

        Services::resolve($this->ide, SearchActions::class)->goToTest();

        $this->assertStringEndsWith('FooTest.php', (string) Services::resolve($this->ide, Tui::class)->getById('editor')->getFilePath());
    }

    public function testGoToTestWithMultipleCandidatesOpensAChooserList(): void
    {
        $buffers = Services::resolve($this->ide, BufferService::class);
        $this->project->write('tests/Unit/BarTest.php', "<?php\n");
        $this->project->write('tests/E2e/BarTest.php', "<?php\n");
        $buffers->openFile($this->project->write('src/Bar.php', "<?php\n"), focus: OpenFocus::Editor);

        Services::resolve($this->ide, SearchActions::class)->goToTest();

        $panel = Services::resolve($this->ide, Tui::class)->getById('results');
        $this->assertTrue($panel->isOpen());
        $this->assertSame('Choose target', $panel->getTitle());
        $rendered = \Symfony\Component\Tui\Ansi\AnsiUtils::stripAnsiCodes(implode("\n", $panel->render(new \Symfony\Component\Tui\Render\RenderContext(120, 14))));
        $this->assertStringContainsString('tests/E2e/BarTest.php', $rendered);
        $this->assertStringContainsString('tests/Unit/BarTest.php', $rendered);
    }

    public function testRequestReformatWithoutLspIsNoOp(): void
    {
        $this->refactor()->requestReformat();
        $this->addToAssertionCount(1);
    }

    public function testReformatUnsupportedFlashesNotSupported(): void
    {
        $client = new \Porta\Lsp\LspClient(
            [\PHP_BINARY, \dirname(__DIR__).'/Support/mock-lsp-server.php'],
            $this->project->dir,
        );
        $ready = false;
        $client->start(static function () use (&$ready): void {
            $ready = true;
        });
        $this->await(static function () use (&$ready): bool {
            return $ready;
        });
        $this->injectLspClient($client);
        $pool = Services::resolve($this->ide, LspSessionPool::class);
        Probe::set($pool, 'statuses', ["injected\0null" => 'mock-lsp-server']);

        // Open a "noformat" buffer and make it the active editor document.
        $buffers = Services::resolve($this->ide, BufferManager::class);
        $path = $this->project->write('src/noformat.php', "<?php\n\$b=2;\n");
        $buffer = $buffers->open($path, (string) file_get_contents($path));
        Services::resolve($this->ide, BufferService::class)->showBuffer($buffer);

        $this->refactor()->requestReformat();
        // The message is a toast now, so await the toast stack itself — polling
        // the status bar (where it no longer lands) made this timing-fragile.
        $this->await(fn () => str_contains(implode("\n", \Porta\Tests\Support\Toasts::all($this->ide)), 'Formatting not supported'), 2.0);

        $this->assertStringContainsString('Formatting not supported by mock-lsp-server', \Porta\Tests\Support\Toasts::last($this->ide));
        $client->stop();
    }

    public function testApplyCodeActionEditOnly(): void
    {
        $onDisk = $this->project->write('src/CA.php', "line0\nline1\n");
        $uri = 'file://'.implode('/', array_map(rawurlencode(...), explode('/', $onDisk)));

        $this->callRefactor('applyCodeAction', [
            'title' => 'Rewrite',
            'edit' => [
                'changes' => [
                    $uri => [[
                        'range' => ['start' => ['line' => 0, 'character' => 0], 'end' => ['line' => 0, 'character' => 5]],
                        'newText' => 'CHANGED',
                    ]],
                ],
            ],
        ]);

        $this->assertStringContainsString('CHANGED', (string) file_get_contents($onDisk));
    }

    public function testPushCurrentLocationWithoutAnOpenFileRecordsNothing(): void
    {
        $this->navigation()->pushCurrentLocation();
        $this->navigation()->navigateBack();
        $this->assertNull(Services::resolve($this->ide, Tui::class)->getById('editor')->getFilePath(), 'the empty push left no stack entry');
    }

    public function testOpenHelpShowsARenderedTabAndSharesThePathWithSourceBuffers(): void
    {
        $readme = (string) realpath($this->project->write('docs/README.md', "# Guides\n\n[Editor](editor.md)\n"));
        $this->project->write('docs/editor.md', "# Editor guide\n");

        /** @var \Porta\Editor\BufferService $buffers */
        $buffers = Services::resolve($this->ide, BufferService::class);
        /** @var \Porta\Widget\HelpViewerWidget $viewer */
        $viewer = Services::resolve($this->ide, \Porta\Widget\HelpViewerWidget::class);
        /** @var \Porta\Editor\BufferManager $manager */
        $manager = Services::resolve($this->ide, BufferManager::class);

        $buffers->openHelp($readme);
        $active = $manager->active();
        $this->assertNotNull($active);
        $this->assertTrue($active->paneId === 'help');
        $this->assertSame($readme, $viewer->path(), 'the viewer shows the page');
        $this->assertFalse($active->isDirty(), 'the backing document stays empty');
        $this->assertFalse($buffers->saveBuffer($active), 'help tabs never save');

        // Unreadable page → flash, no tab.
        $buffers->openHelp($this->project->path('docs/nope.md'));
        $this->assertSame($readme, $manager->active()?->path);

        // A page that is not text is REFUSED by the pane's loader rather than
        // rendered as mojibake — the same refusal every pane makes about a file
        // its claim already accepted.
        $binary = (string) realpath($this->project->write('docs/binary.md', "\xff\xfe\x00# not text"));
        $buffers->openHelp($binary);
        $this->assertSame($readme, $manager->active()?->path, 'the corrupt page opened no tab');
        $this->assertStringContainsString('Not a UTF-8 text file', \Porta\Tests\Support\Toasts::last($this->ide));

        // A page changed on disk reloads through the filesystem poller.
        file_put_contents($readme, data: "# Guides v2\n");
        clearstatcache();
        touch($readme, time() + 2);
        $buffers->pollFilesystem();
        $this->assertSame("# Guides v2\n", $manager->active()?->panePayload, 'the poller refreshed the page');

        // The same path already open as SOURCE re-shows the text buffer.
        $buffers->closeActiveBuffer();
        $buffers->openFile($readme, focus: OpenFocus::Editor);
        $buffers->openHelp($readme);
        $this->assertFalse($manager->active()?->paneId === 'help', 'the editable source tab wins');
    }

    public function testHelpNavigationStaysInOneTabAndSurvivesTabSwitches(): void
    {
        $readme = (string) realpath($this->project->write('docs/README.md', "# Guides\n\n[Editor](editor.md)\n"));
        $editor = (string) realpath($this->project->write('docs/editor.md', "# Editor guide\n"));
        $code = (string) realpath($this->project->write('a.php', "<?php\n"));

        /** @var \Porta\Editor\BufferService $buffers */
        $buffers = Services::resolve($this->ide, BufferService::class);
        /** @var \Porta\Widget\HelpViewerWidget $viewer */
        $viewer = Services::resolve($this->ide, \Porta\Widget\HelpViewerWidget::class);
        /** @var \Porta\Editor\BufferManager $manager */
        $manager = Services::resolve($this->ide, BufferManager::class);

        // In-place navigation: the viewer moves, no second help buffer appears.
        $buffers->openHelp($readme);
        $buffers->showHelpPage($editor);
        $this->assertSame($editor, $viewer->path(), 'the viewer navigated in place');
        $this->assertSame($readme, $manager->active()?->path, 'still the one help tab, keyed by the root page');
        $this->assertCount(1, $manager->all());

        // An unreadable page flashes and leaves the viewer where it was.
        $buffers->showHelpPage($this->project->path('docs/nope.md'));
        $this->assertSame($editor, $viewer->path());

        // A round trip through a code tab keeps the navigated page and history.
        $buffers->openFile($code, focus: OpenFocus::Editor);
        $this->assertSame($editor, $viewer->path(), 'switching tabs no longer clears the viewer');
        $buffers->openHelp($readme);
        $this->assertSame($editor, $viewer->path(), 're-activating the help tab keeps the navigated page');

        // The poller only re-shows the root page when the viewer is on it.
        file_put_contents($readme, data: "# Guides v2\n");
        clearstatcache();
        touch($readme, time() + 2);
        $buffers->pollFilesystem();
        $this->assertSame("# Guides v2\n", $manager->active()?->panePayload);
        $this->assertSame($editor, $viewer->path(), 'a background root reload does not yank the navigated page');

        // Closing the help tab is what drops the viewer state.
        $buffers->closeActiveBuffer();
        $this->assertNull($viewer->path(), 'the history dies with the tab');

        // closeTabsExcept clears it the same way.
        $buffers->openHelp($readme);
        $buffers->showHelpPage($editor);
        $buffers->closeTabsExcept($code);
        $this->assertNull($viewer->path(), 'closing the help tab via close-others clears the viewer');
        $this->assertSame([$code], array_map(static fn ($b): string => $b->path, $manager->all()));
    }

    public function testViewerHandlerHooksDelegateToTheBufferStack(): void
    {
        $tui = Services::resolve($this->ide, Tui::class);
        $flash = fn (): string => \Porta\Tests\Support\Toasts::last($this->ide);

        $file = $this->project->write('src/Hooked.php', "<?php\n");
        $helpViewer = Services::resolve($this->ide, \Porta\Widget\HelpViewerWidget::class);
        $help = Probe::get($helpViewer, 'handler');
        \assert($help instanceof \Porta\Help\HelpViewerActions, description: 'the help viewer exposes its actions handler');

        $help->openFile($file);
        $this->assertStringEndsWith('Hooked.php', (string) $tui->getById('editor')->getFilePath(), 'a plain-file link opens in the editor');

        $help->missingTarget('ghost.md');
        $this->assertStringContainsString('Cannot read ghost.md', $flash(), 'a dangling link target flashes');

        // The pane contract's fifth verb reaches the same navigator the two
        // navigable panes use, so a help pane that ever moves its view repaints
        // the bar through one path rather than a special case.
        $help->viewChanged();

        $help->saveAll(); // nothing dirty: a silent sweep
        $help->closeBuffer();
        $buffers = Services::resolve($this->ide, BufferManager::class);
        $this->assertNull($buffers->active(), 'the viewer close hook closed the only tab');

        // The other half of this test used to reach the image viewer the same
        // way; it is a PLUGIN pane now, and that its handler arrives at all is
        // pinned where the plugin is loaded (IdeImageViewerTest).
        $this->addToAssertionCount(1);
    }
}
