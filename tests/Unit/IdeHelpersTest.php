<?php

declare(strict_types=1);

namespace Porta\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Porta\Config\CommandRegistry;
use Porta\Editor\BufferManager;
use Porta\Editor\BufferService;
use Porta\Editor\Document;
use Porta\Editor\OpenFocus;
use Porta\Git\GitInfo;
use Porta\History\LocalHistoryController;
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
use Porta\Support\ProjectPaths;
use Porta\Tests\Support\EditorState;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\PumpsLoop;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * Direct tests for Ide's private pure helpers (via reflection) and for
 * WorkspaceEdit application, which has no UI dependency.
 */
final class IdeHelpersTest extends TestCase
{
    use PumpsLoop;

    private TempProject $project;
    private Ide $ide;

    protected function setUp(): void
    {
        $this->project = new TempProject(['composer.json' => '{}']);
        new OnboardingState($this->project->dir)->markWelcomeSeen();
        $this->ide = new Ide($this->project->dir, null, new VirtualTerminal(columns: 80, rows: 24));
    }

    private function call(string $method, mixed ...$args): mixed
    {
        return Probe::invoke($this->ide, $method, ...$args);
    }

    public function testServicesSeamReturnsLocatorMembersAndSynthetics(): void
    {
        // A porta.ide locator member resolves through the locator branch...
        self::assertInstanceOf(GitInfo::class, Services::resolve($this->ide, GitInfo::class));
        // ...and a synthetic (Renderer) falls back to the container branch —
        // the empirical proof that PRIVATE synthetics stay get()-able once
        // Ide's constructor has set() them.
        self::assertInstanceOf(Renderer::class, Services::resolve($this->ide, Renderer::class));
        self::assertSame(Services::resolve($this->ide, Renderer::class), Services::resolve($this->ide, Renderer::class), 'the same shared instance every time');
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

    public function testLanguageIdMap(): void
    {
        $cases = [
            'x.php' => 'php',
            'x.phtml' => 'php',
            'x.js' => 'javascript',
            'x.mjs' => 'javascript',
            'x.ts' => 'typescript',
            'x.json' => 'json',
            'x.css' => 'css',
            'x.html' => 'html',
            'x.htm' => 'html',
            'x.md' => 'markdown',
            'x.yaml' => 'yaml',
            'x.yml' => 'yaml',
            'x.twig' => 'twig',
            'x.py' => 'python',
            'x.scss' => 'scss',
            'x.weird' => 'plaintext',
        ];
        foreach ($cases as $file => $expected) {
            $this->assertSame($expected, ProjectPaths::languageId($file), $file);
        }
    }

    public function testRelativePathOutsideRoot(): void
    {
        $this->assertSame('/elsewhere/file.php', ProjectPaths::relative($this->project->dir, '/elsewhere/file.php'));
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

    /** The local-history feature controller wired into the coordinator. */
    private function localHistory(): LocalHistoryController
    {
        $controller = Services::resolve($this->ide, LocalHistoryController::class);
        \assert($controller instanceof LocalHistoryController, description: 'the coordinator wires a LocalHistoryController');

        return $controller;
    }

    public function testShowLocalHistoryWithNoActiveFileIsNoOp(): void
    {
        // The bare Ide has no file open.
        $this->localHistory()->show();
        $this->assertFalse(Services::resolve($this->ide, Tui::class)->getById('results')->isOpen());
    }

    public function testRestoreHistorySnapshotWithBadIndexOrMissingFile(): void
    {
        // No snapshots listed and no active buffer -> guard returns.
        $this->localHistory()->restore(0);

        // Now point at a snapshot file that does not exist, with a buffer open.
        $buffers = Services::resolve($this->ide, BufferManager::class);
        $buffers->open($this->project->path('x.php'), "content\n");
        Probe::set($this->localHistory(), 'snapshots', ['/nonexistent/1.snap']);

        $this->localHistory()->restore(0);
        $this->assertStringContainsString('Could not read that snapshot', \Porta\Tests\Support\Toasts::last($this->ide));
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

    public function testProviderPriorityOrderMatchesTheContractualPaletteOrder(): void
    {
        $registry = Services::resolve($this->ide, CommandRegistry::class);
        \assert($registry instanceof \Porta\Config\CommandRegistry, description: 'the IDE exposes a CommandRegistry');
        $labels = array_column($registry->paletteCommands(), 'label');

        // The tagged providers register in #[AsTaggedItem] priority order:
        // registration order IS the palette display order — file commands
        // first, git commands last.
        $this->assertSame('Debug Current File', $labels[0], 'the debug group has always led the palette');
        $this->assertSame('Git: Toggle Blame Annotations', $labels[array_key_last($labels)]);
        $this->assertLessThan(array_search('Show Problems', $labels, strict: true), array_search('Save All', $labels, strict: true), 'file group precedes the search group');
        $this->assertLessThan(array_search('Git: Stage Current File', $labels, strict: true), array_search('Documentation', $labels, strict: true), 'view group precedes the git group');
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
        Probe::set($pool, 'sessions', ['injected' => $session]);
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
        Probe::set($pool, 'statuses', ['injected' => 'mock-lsp-server']);

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

        $help->saveAll(); // nothing dirty: a silent sweep
        $help->closeBuffer();
        $buffers = Services::resolve($this->ide, BufferManager::class);
        $this->assertNull($buffers->active(), 'the viewer close hook closed the only tab');

        $imageViewer = Services::resolve($this->ide, \Porta\Widget\ImageViewerWidget::class);
        $image = Probe::get($imageViewer, 'handler');
        \assert($image instanceof \Porta\Editor\BufferNavigator, description: 'the image viewer is handled by the navigator itself');
        $image->saveAll(); // same silent sweep through the shared navigator

        $this->addToAssertionCount(1);
    }
}
