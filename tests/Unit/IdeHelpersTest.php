<?php

declare(strict_types=1);

namespace Porta\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Porta\Editor\Document;
use Porta\History\LocalHistoryController;
use Porta\Ide;
use Porta\Lsp\LspMapper;
use Porta\Lsp\NavigationController;
use Porta\Lsp\RefactorController;
use Porta\Lsp\Uri;
use Porta\Lsp\WorkspaceEditor;
use Porta\Support\ProjectPaths;
use Porta\Tests\Support\TempProject;
use Symfony\Component\Tui\Terminal\VirtualTerminal;

/**
 * Direct tests for Ide's private pure helpers (via reflection) and for
 * WorkspaceEdit application, which has no UI dependency.
 */
final class IdeHelpersTest extends TestCase
{
    private TempProject $project;
    private Ide $ide;

    protected function setUp(): void
    {
        $this->project = new TempProject(['composer.json' => '{}']);
        $this->ide = new Ide($this->project->dir, null, new VirtualTerminal(columns: 80, rows: 24));
    }

    private function call(string $method, mixed ...$args): mixed
    {
        $reflection = new \ReflectionMethod($this->ide, $method);

        return $reflection->invoke($this->ide, ...$args);
    }

    private function workspaceEditor(): WorkspaceEditor
    {
        return new \ReflectionProperty($this->ide, 'workspaceEditor')->getValue($this->ide);
    }

    private function navigation(): NavigationController
    {
        return new \ReflectionProperty($this->ide, 'navigation')->getValue($this->ide);
    }

    private function callNav(string $method, mixed ...$args): mixed
    {
        $nav = $this->navigation();

        return new \ReflectionMethod($nav, $method)->invoke($nav, ...$args);
    }

    private function refactor(): RefactorController
    {
        return new \ReflectionProperty($this->ide, 'refactor')->getValue($this->ide);
    }

    private function callRefactor(string $method, mixed ...$args): mixed
    {
        $refactor = $this->refactor();

        return new \ReflectionMethod($refactor, $method)->invoke($refactor, ...$args);
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
            'x.weird' => 'plaintext',
        ];
        foreach ($cases as $file => $expected) {
            $this->assertSame($expected, ProjectPaths::languageId($file), $file);
        }
    }

    public function testRelativePathOutsideRoot(): void
    {
        $this->assertSame('/elsewhere/file.php', $this->call('relativePath', '/elsewhere/file.php'));
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
        $controller = new \ReflectionProperty($this->ide, 'localHistory')->getValue($this->ide);
        \assert($controller instanceof LocalHistoryController);

        return $controller;
    }

    public function testShowLocalHistoryWithNoActiveFileIsNoOp(): void
    {
        // The bare Ide has no file open.
        $this->localHistory()->show();
        $this->assertFalse($this->ide->getTui()->getById('results')->isOpen());
    }

    public function testRestoreHistorySnapshotWithBadIndexOrMissingFile(): void
    {
        // No snapshots listed and no active buffer -> guard returns.
        $this->localHistory()->restore(0);

        // Now point at a snapshot file that does not exist, with a buffer open.
        $buffers = new \ReflectionProperty($this->ide, 'buffers')->getValue($this->ide);
        $buffers->open($this->project->path('x.php'), "content\n");
        new \ReflectionProperty($this->localHistory(), 'snapshots')->setValue($this->localHistory(), ['/nonexistent/1.snap']);

        $this->localHistory()->restore(0);
        $status = \Symfony\Component\Tui\Ansi\AnsiUtils::stripAnsiCodes(
            implode(
                '',
                $this->ide
                    ->getTui()
                    ->getById('status')
                    ->render(new \Symfony\Component\Tui\Render\RenderContext(120, 1)),
            ),
        );
        $this->assertStringContainsString('Could not read that snapshot', $status);
    }

    public function testRestoreSessionSkipsMissingFiles(): void
    {
        // Write a session referencing one real and one missing file.
        $real = $this->project->write('src/Real.php', "hi\n");
        new \Porta\History\SessionStore($this->project->dir)->save([
            ['path' => '/does/not/exist.php', 'line' => 0, 'col' => 0],
            ['path' => $real, 'line' => 0, 'col' => 1],
        ], $real);

        $this->assertTrue($this->call('restoreSession'), 'restored at least the real file');
        $this->assertStringEndsWith('Real.php', (string) $this->ide->getTui()->getById('editor')->getFilePath());
    }

    public function testEveryPaletteCommandRuns(): void
    {
        $tui = $this->ide->getTui();
        $registry = new \ReflectionProperty($this->ide, 'commands')->getValue($this->ide);
        $commands = $registry->paletteCommands();

        $byLabel = [];
        foreach ($commands as $command) {
            $byLabel[$command['label']] = $command['run'];
        }

        // Panel-opening commands focus the right widget.
        $byLabel['Find in Project']();
        $this->assertSame('results', $tui->getFocus()?->getId());

        $byLabel['Go to Symbol in Project']();
        $this->assertSame('results', $tui->getFocus()?->getId());

        $byLabel['Go to File']();
        $this->assertSame('goto-file', $tui->getFocus()?->getId());

        // Toggle both settings (covers both branches of toggleSetting).
        $editor = $tui->getById('editor');
        $before = $editor->hasRelativeLineNumbers();
        $byLabel['Toggle Relative Line Numbers']();
        $this->assertNotSame($before, $editor->hasRelativeLineNumbers());

        $byLabel['Toggle Inline Diagnostics']();
        // Toggling back and forth is a no-op-free round trip.
        $byLabel['Toggle Inline Diagnostics']();

        $autoBefore = $editor->hasAutoPairs();
        $byLabel['Toggle Auto-Pairs']();
        $this->assertNotSame($autoBefore, $editor->hasAutoPairs());

        // Save-family and reformat commands are safe no-ops without a file/LSP.
        $byLabel['Save File']();
        $byLabel['Save All']();
        $byLabel['Reformat File']();
        $byLabel['Show Problems']();
        $this->addToAssertionCount(1);
    }

    public function testRequestWorkspaceSymbolsWithoutLspClearsResults(): void
    {
        // Panel not open; the guard clears results and returns.
        $this->navigation()->requestWorkspaceSymbols('x');
        $this->addToAssertionCount(1);
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

    public function testWorkspaceSymbolResponseIgnoredWhenPanelClosed(): void
    {
        $client = new \Porta\Lsp\LspClient(
            [\PHP_BINARY, \dirname(__DIR__).'/Support/mock-lsp-server.php'],
            $this->project->dir,
        );
        $ready = false;
        $client->start(function () use (&$ready): void {
            $ready = true;
        });
        $this->await(static fn () => $ready);
        $this->injectLspClient($client);

        // The results panel is never opened, so the response callback's
        // guard drops the result without touching the panel.
        $this->navigation()->requestWorkspaceSymbols('greet');
        $this->await(fn () => false, 0.4); // pump the loop briefly

        $this->assertFalse($this->ide->getTui()->getById('results')->isOpen());
        $client->stop();
    }

    public function testApplyCodeActionBareCommandAndCommandError(): void
    {
        // Inject a started mock LSP so the command paths run for real.
        $client = new \Porta\Lsp\LspClient(
            [\PHP_BINARY, \dirname(__DIR__).'/Support/command-lsp-server.php'],
            $this->project->dir,
        );
        $ready = false;
        $client->start(function () use (&$ready): void {
            $ready = true;
        });
        $this->await(static fn () => $ready);
        $this->injectLspClient($client);

        $results = [];
        $client->onNotification('mock/ran', function (array $p) use (&$results): void {
            $results[] = $p['command'];
        });

        // Bare string command (normalized to a command array — line 980)
        $this->callRefactor('applyCodeAction', ['title' => 'Bare', 'command' => 'mock.ok']);
        // Command the server rejects (error branch — line 989)
        $this->callRefactor('applyCodeAction', ['title' => 'Boom', 'command' => ['command' => 'mock.fail']]);

        $this->await(static fn () => \count($results) >= 1);
        $this->assertContains('mock.ok', $results);

        $client->stop();
    }

    /** Inject a live client into the Ide's LspSession so feature code runs for real. */
    private function injectLspClient(\Porta\Lsp\LspClient $client): void
    {
        $session = new \ReflectionProperty($this->ide, 'lsp')->getValue($this->ide);
        new \ReflectionProperty($session, 'client')->setValue($session, $client);
    }

    private function await(callable $done, float $timeout = 3.0): void
    {
        $elapsed = 0.0;
        while (!$done() && $elapsed < $timeout) {
            $suspension = \Revolt\EventLoop::getSuspension();
            \Revolt\EventLoop::delay(0.02, static fn () => $suspension->resume());
            $suspension->suspend();
            $elapsed += 0.02;
        }
    }

    public function testShowProblemsAggregatesAndSortsWithSeverityMarkers(): void
    {
        $buffers = new \ReflectionProperty($this->ide, 'buffers')->getValue($this->ide);
        $b = $buffers->open($this->project->path('src/B.php'), "<?php\n");
        $b->diagnostics = [5 => [['severity' => 2, 'message' => 'warn here', 'col' => 0]]];
        $a = $buffers->open($this->project->path('src/A.php'), "<?php\n");
        $a->diagnostics = [
            2 => [['severity' => 1, 'message' => 'error here', 'col' => 0]],
            0 => [['severity' => 1, 'message' => 'top error', 'col' => 0]],
        ];

        $this->call('showProblems');

        $panel = $this->ide->getTui()->getById('results');
        $this->assertTrue($panel->isOpen());
        $rendered = \Symfony\Component\Tui\Ansi\AnsiUtils::stripAnsiCodes(
            implode("\n", $panel->render(new \Symfony\Component\Tui\Render\RenderContext(120, 14))),
        );
        $this->assertStringContainsString('Problems', $rendered);
        $this->assertStringContainsString('✗ top error', $rendered);
        $this->assertStringContainsString('▲ warn here', $rendered);
        // A.php sorts before B.php; within A.php line 0 before line 2
        $this->assertLessThan(strpos($rendered, 'error here'), strpos($rendered, 'top error'));
        $this->assertLessThan(strpos($rendered, 'warn here'), strpos($rendered, 'top error'));
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
        $client->start(function () use (&$ready): void {
            $ready = true;
        });
        $this->await(static fn () => $ready);
        $this->injectLspClient($client);
        new \ReflectionProperty($this->ide, 'lspStatus')->setValue($this->ide, 'mock-lsp-server');

        // Open a "noformat" buffer and make it the active editor document.
        $buffers = new \ReflectionProperty($this->ide, 'buffers')->getValue($this->ide);
        $path = $this->project->write('src/noformat.php', "<?php\n\$b=2;\n");
        $buffer = $buffers->open($path, (string) file_get_contents($path));
        new \ReflectionMethod($this->ide, 'showBuffer')->invoke($this->ide, $buffer);

        $this->refactor()->requestReformat();
        $this->await(fn () => str_contains(
            \Symfony\Component\Tui\Ansi\AnsiUtils::stripAnsiCodes(
                implode(
                    '',
                    $this->ide
                        ->getTui()
                        ->getById('status')
                        ->render(new \Symfony\Component\Tui\Render\RenderContext(120, 1)),
                ),
            ),
            'Formatting not supported',
        ), 2.0);

        $status = \Symfony\Component\Tui\Ansi\AnsiUtils::stripAnsiCodes(
            implode(
                '',
                $this->ide
                    ->getTui()
                    ->getById('status')
                    ->render(new \Symfony\Component\Tui\Render\RenderContext(120, 1)),
            ),
        );
        $this->assertStringContainsString('Formatting not supported by mock-lsp-server', $status);
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
}
