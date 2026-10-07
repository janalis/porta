<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Lsp;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\Config\EditorConfig\ResolvedEditorConfig;
use Porta\Editor\Buffer;
use Porta\Editor\BufferManager;
use Porta\Editor\DiagnosticMessageMode;
use Porta\Editor\DiagnosticSeverity;
use Porta\Editor\Document;
use Porta\Editor\SelectionMode;
use Porta\I18n\Translator;
use Porta\Lsp\LspSessionPool;
use Porta\Lsp\RefactorController;
use Porta\Lsp\RefactoringUndo;
use Porta\Lsp\Uri;
use Porta\Lsp\WorkspaceEditor;
use Porta\Support\ProjectPaths;
use Porta\Tests\Support\FakeLspClient;
use Porta\Tests\Support\FixedActiveFile;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\TestTui;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\StatusBarWidget;
use Porta\Workbench\Notifier;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Tui;

final class RefactorControllerTest extends TestCase
{
    private FakeLspClient $client;

    /** Every fake client the pool built, oldest first. @var list<FakeLspClient> */
    private array $clients = [];
    private CodeEditorWidget $editor;
    private BufferManager $buffers;
    private RefactoringUndo $undo;
    private StatusBarWidget $statusBar;
    private Tui $tui;

    private function flashed(): string
    {
        return AnsiUtils::stripAnsiCodes(\implode('', $this->statusBar->render(new RenderContext(100, 1))));
    }

    /** Clears the flag first: a caller checks whether ITS action requested a render, not a stale one. */
    private function requestedRenderSince(callable $action): bool
    {
        Probe::set($this->tui, 'renderRequested', false);
        $action();

        return (bool) Probe::get($this->tui, 'renderRequested');
    }

    /**
     * Wires a controller with a fresh pool/editor/buffers, no session started
     * yet (ready() is null until a caller calls notifyDidOpen).
     */
    private function controller(): RefactorController
    {
        $pool = new LspSessionPool(
            '/tmp/proj',
            new FixedActiveFile('/tmp/proj/a.php'),
            new Translator('en'),
            fn (array $command, string $rootDir, \Closure $onLog): FakeLspClient => $this->client = new FakeLspClient(),
        );
        $pool->configure(
            ['php' => [['command' => ['php-ls'], 'name' => 'php-ls']]],
            static function (string $line): void {},
            static function (array $params): void {},
            static fn (array $edit): bool => false,
        );
        $this->editor = new CodeEditorWidget();
        $tui = TestTui::create(80, 24);
        $this->tui = $tui;
        $this->statusBar = new StatusBarWidget();
        $this->buffers = new BufferManager();

        return new RefactorController(
            $pool,
            $this->editor,
            $tui,
            new Notifier($this->statusBar, $tui),
            new Translator('en'),
            $this->buffers,
            new WorkspaceEditor($this->buffers, $this->editor, $pool, new EventDispatcher(), $this->undo = new RefactoringUndo($this->buffers, new EventDispatcher())),
            $this->undo,
            new ProjectPaths('/tmp/proj'),
        );
    }

    /**
     * @param array<string, mixed> $capabilities what the fake server advertises
     *
     * @return array{0: RefactorController, 1: LspSessionPool} a controller with an initialized session on a.php
     */
    /**
     * A ready controller over one server.
     *
     * The default advertises code actions because only servers that DO are
     * asked for them — a second server on the language that does not must not
     * be sent a request it can only answer with an error.
     *
     * @param array<string, mixed> $capabilities
     * @param int                  $servers how many claim php — two is the case a merge exists for
     */
    private function ready(array $capabilities = ['codeActionProvider' => true], int $servers = 1): array
    {
        $pool = new LspSessionPool(
            '/tmp/proj',
            new FixedActiveFile('/tmp/proj/a.php'),
            new Translator('en'),
            function (array $command, string $rootDir, \Closure $onLog) use ($capabilities): FakeLspClient {
                $client = new FakeLspClient($capabilities);
                $this->clients[] = $client;

                return $this->client = $client;
            },
        );
        $pool->configure(
            [
                'php' => 2 === $servers
                    ? [['command' => ['php-ls'], 'name' => 'php-ls'], ['command' => ['sf-ls'], 'name' => 'sf-ls']]
                    : [['command' => ['php-ls'], 'name' => 'php-ls']],
            ],
            static function (string $line): void {},
            static function (array $params): void {},
            static fn (array $edit): bool => false,
        );
        $this->editor = new CodeEditorWidget();
        $tui = TestTui::create(80, 24);
        $this->tui = $tui;
        $this->statusBar = new StatusBarWidget();
        $this->buffers = new BufferManager();
        $controller = new RefactorController(
            $pool,
            $this->editor,
            $tui,
            new Notifier($this->statusBar, $tui),
            new Translator('en'),
            $this->buffers,
            new WorkspaceEditor($this->buffers, $this->editor, $pool, new EventDispatcher(), $this->undo = new RefactoringUndo($this->buffers, new EventDispatcher())),
            $this->undo,
            new ProjectPaths('/tmp/proj'),
        );
        $buffer = $this->buffers->open('/tmp/proj/a.php', "<?php\n\$x = 1;\n");
        $this->editor->showDocument('/tmp/proj/a.php', $buffer->document);
        $pool->notifyDidOpen('/tmp/proj/a.php', $buffer->document->getText(), 'php', 1);

        return [$controller, $pool];
    }

    // ---------------------------------------------------------------- requestCodeActions guard

    public function testCodeActionsWithoutAReadyServerSendsNothing(): void
    {
        $controller = $this->controller();
        $this->editor->showDocument('/tmp/proj/a.php', new Document("<?php\n"));
        // No notifyDidOpen: the session was never started, so ready() is null,
        // and no session/client is ever built — nothing to send a request to.

        $controller->requestCodeActions(0, 0);

        // @mago-expect lint:no-isset -- $client is a non-nullable typed property; isset() is the only way to test whether it was ever initialized (the factory closure never ran)
        $this->assertFalse(isset($this->client), 'the guard stopped before a session was ever started');
    }

    public function testCodeActionsWithAReadyServerSendsTheRequest(): void
    {
        [$controller] = $this->ready();

        $controller->requestCodeActions(1, 2);

        $this->assertSame(['textDocument/codeAction'], \array_column($this->client->requests, 'method'));
    }

    /**
     * The bug the round exists for: `refactor.extract` needs a real range, and a
     * caret point can never be one. The assertion is on the PARAMS, because a
     * selection that is silently dropped looks exactly like success from here.
     */
    public function testASelectionIsWhatTheRequestAsksAbout(): void
    {
        [$controller] = $this->ready();
        $doc = $this->editor->getDocument();
        $doc->setCursor(1, 0);
        $doc->beginSelect();
        $doc->setCursor(1, 7, selection: SelectionMode::Keep);

        $controller->requestCodeActions(1, 0);

        $params = \end($this->client->requests)['params'];
        $this->assertSame(['line' => 1, 'character' => 0], $params['range']['start']);
        $this->assertSame(['line' => 1, 'character' => 7], $params['range']['end'], 'the whole selection, not the caret');
    }

    /** With no selection the caret line's diagnostic still wins, so fix-this-problem keeps working. */
    public function testWithoutASelectionADiagnosticRangeIsUsed(): void
    {
        [$controller] = $this->ready();
        $this->buffers->active()->diagnostics = [1 => [['severity' => DiagnosticSeverity::Error, 'message' => 'boom', 'col' => 2, 'endCol' => 5]]];

        $controller->requestCodeActions(1, 0);

        $params = \end($this->client->requests)['params'];
        $this->assertSame(['line' => 1, 'character' => 2], $params['range']['start']);
        $this->assertSame(['line' => 1, 'character' => 5], $params['range']['end']);
    }

    /** And with neither, the caret itself. */
    public function testWithNeitherSelectionNorDiagnosticTheCaretIsTheRange(): void
    {
        [$controller] = $this->ready();

        $controller->requestCodeActions(1, 3);

        $params = \end($this->client->requests)['params'];
        $this->assertSame(['line' => 1, 'character' => 3], $params['range']['start']);
        $this->assertSame(['line' => 1, 'character' => 3], $params['range']['end']);
    }

    /**
     * The range was round 2's bug and is asserted three ways above — but a
     * request that names no DOCUMENT is just as silently useless, and nothing
     * covered it. Infection found this by deleting the key with every test
     * still green.
     */
    public function testTheRequestNamesTheDocumentItIsAbout(): void
    {
        [$controller] = $this->ready();

        $controller->requestCodeActions(1, 0);

        $params = \end($this->client->requests)['params'];
        $this->assertSame(['uri' => 'file:///tmp/proj/a.php'], $params['textDocument']);
    }

    /**
     * The three ways a code-action answer can be nothing, each of which must
     * flash rather than open an empty picker. They are separate arms of one
     * guard, so a test for only one of them leaves the others free to invert.
     */
    #[DataProvider('emptyAnswers')]
    public function testEveryShapeOfEmptyAnswerFlashesInsteadOfOpeningAPicker(mixed $result, ?array $error): void
    {
        [$controller] = $this->ready();
        $controller->requestCodeActions(1, 0);

        \end($this->client->requests)['respond']($result, error: $error);

        $this->assertStringContainsString('No quick fixes here', $this->flashed());
    }

    /** @return \Generator<string, array{mixed, array<string, mixed>|null}> */
    public static function emptyAnswers(): \Generator
    {
        yield 'a transport error' => [[['title' => 'Never shown']], ['code' => -32_603, 'message' => 'boom']];
        yield 'a non-array result' => [null, null];
        yield 'an empty list' => [[], null];
        yield 'only untitled actions' => [[['kind' => 'quickfix']], null];
    }

    /** @return \Generator<string, array{string, list<string>|null}> */
    public static function entryPoints(): \Generator
    {
        yield 'quick fixes ask for everything' => ['requestCodeActions', null];
        yield 'refactor this narrows to refactor' => ['requestRefactorings', ['refactor']];
        yield 'inline narrows to refactor.inline' => ['requestInline', ['refactor.inline']];
    }

    /** @param list<string>|null $only */
    #[DataProvider('entryPoints')]
    public function testEachEntryPointAsksForItsOwnKinds(string $method, ?array $only): void
    {
        [$controller] = $this->ready();

        $controller->{$method}(1, 0);

        $context = \end($this->client->requests)['params']['context'];
        $this->assertSame($only, $context['only'] ?? null);
        $this->assertSame(1, $context['triggerKind'], 'invoked by the user, not polled');
    }

    /** A server matching its own fix by code sees nothing to match if the code is dropped. */
    public function testTheDiagnosticContextCarriesItsCodeAndData(): void
    {
        [$controller] = $this->ready();
        $this->buffers->active()->diagnostics = [
            1 => [
                ['severity' => DiagnosticSeverity::Warning, 'message' => 'boom', 'col' => 0, 'endCol' => 1, 'code' => 'PHP0420', 'data' => ['fix' => 'import'], 'tags' => [1]],
            ],
        ];

        $controller->requestCodeActions(1, 0);

        $diagnostic = \end($this->client->requests)['params']['context']['diagnostics'][0];
        $this->assertSame('PHP0420', $diagnostic['code']);
        $this->assertSame(2, $diagnostic['severity'], 'the wire takes the LSP int back, and this one is a warning');
        $this->assertSame(['fix' => 'import'], $diagnostic['data']);
        // The context is meant to hand back the diagnostic the server
        // published, not a summary of it — and "remove this unused import" is
        // exactly the fix a server would offer against the tag.
        $this->assertSame([1], $diagnostic['tags']);
    }

    // ---------------------------------------------------------------- applyCodeAction (via the picker)

    /** Send a codeAction request, respond with $actions, then pick the $index'th item. */
    private function pickAction(array $actions, int $index = 0): void
    {
        [$controller] = $this->ready();
        $controller->requestCodeActions(0, 0);
        $request = \end($this->client->requests);
        $request['respond']($actions, error: null);

        for ($i = 0; $i < $index; ++$i) {
            $this->editor->handleInput("\x1b[B");
        }
        $this->editor->handleInput("\r");
    }

    /** @return array<string, mixed> a fix that renames the `$x` on line 1 to $name */
    private static function renameTo(string $name, string $kind = 'quickfix'): array
    {
        return [
            'title' => "Rename to {$name}",
            'kind' => $kind,
            'edit' => [
                'changes' => [
                    'file:///tmp/proj/a.php' => [
                        ['range' => ['start' => ['line' => 1, 'character' => 0], 'end' => ['line' => 1, 'character' => 2]], 'newText' => $name],
                    ],
                ],
            ],
        ];
    }

    private function problemOnX(): void
    {
        $buffer = $this->buffers->active();
        $this->assertInstanceOf(Buffer::class, $buffer);
        $buffer->diagnostics = [
            1 => [
                ['severity' => DiagnosticSeverity::Error, 'message' => 'Undefined variable', 'col' => 0, 'endCol' => 2, 'code' => 'E1'],
                ['severity' => DiagnosticSeverity::Hint, 'message' => 'Elsewhere', 'col' => 5, 'endCol' => 6],
            ],
        ];
    }

    /** PhpStorm's Alt+Shift+Enter: the problem's first fix, without the list. */
    public function testApplyQuickFixAppliesTheFirstFixForTheProblemAtTheCaret(): void
    {
        [$controller] = $this->ready();
        $this->problemOnX();

        $controller->requestApplyQuickFix(1, 1);
        $request = \end($this->client->requests);
        /** @var array{context: array{diagnostics: list<array<string, mixed>>}} $params */
        $params = $request['params'];
        $this->assertSame(['E1'], \array_column($params['context']['diagnostics'], 'code'), 'about the worst problem at the caret, as the card asks');

        $request['respond']([self::renameTo('z', kind: 'refactor.rewrite'), self::renameTo('y')], error: null);

        $this->assertSame("<?php\ny = 1;\n", $this->buffers->get('/tmp/proj/a.php')?->document->getText(), 'the quick fix, not the refactoring before it');
    }

    public function testApplyQuickFixAppliesOnceWhateverAnswersLater(): void
    {
        [$controller] = $this->ready(servers: 2);
        $this->problemOnX();
        $controller->requestApplyQuickFix(1, 1);
        [$first, $second] = $this->clients;

        \end($first->requests)['respond']([self::renameTo('y')], error: null);
        \end($second->requests)['respond']([self::renameTo('w')], error: null);

        $this->assertSame("<?php\ny = 1;\n", $this->buffers->get('/tmp/proj/a.php')?->document->getText());
    }

    /** The card names a slower server's preferred fix; the key must apply that one, not the first to arrive. */
    public function testApplyQuickFixWaitsForAPreferredFixFromASlowerServer(): void
    {
        [$controller] = $this->ready(servers: 2);
        $this->problemOnX();
        $controller->requestApplyQuickFix(1, 1);
        [$first, $second] = $this->clients;

        \end($first->requests)['respond']([self::renameTo('y')], error: null);
        $this->assertSame("<?php\n\$x = 1;\n", $this->buffers->get('/tmp/proj/a.php')?->document->getText(), 'a plain fix waits for the other server');
        \end($second->requests)['respond']([['isPreferred' => true] + self::renameTo('w')], error: null);

        $this->assertSame("<?php\nw = 1;\n", $this->buffers->get('/tmp/proj/a.php')?->document->getText());
    }

    public function testApplyQuickFixWaitsForEveryServerBeforeSayingThereIsNone(): void
    {
        [$controller] = $this->ready(servers: 2);
        $this->problemOnX();
        $controller->requestApplyQuickFix(1, 1);
        [$first, $second] = $this->clients;

        \end($first->requests)['respond']([self::renameTo('z', kind: 'refactor')], error: null);
        $this->assertStringNotContainsString('No quick fix', $this->flashed(), 'the other server may still have one');
        \end($second->requests)['respond']([self::renameTo('v', kind: 'source')], error: null);

        $this->assertStringContainsString('No quick fix for this problem', $this->flashed());
        $this->assertSame("<?php\n\$x = 1;\n", $this->buffers->get('/tmp/proj/a.php')?->document->getText());
    }

    public function testApplyQuickFixAwayFromAProblemAsksNothing(): void
    {
        [$controller] = $this->ready();
        $this->problemOnX();

        $controller->requestApplyQuickFix(1, 4);

        $this->assertStringContainsString('No problem at the caret', $this->flashed());
        $this->assertSame([], \array_filter($this->client->requests, static fn (array $r): bool => 'textDocument/codeAction' === $r['method']));
    }

    public function testNoQuickFixesFlashesWhenTheResultIsEmptyOrErrored(): void
    {
        [$controller] = $this->ready();
        $controller->requestCodeActions(0, 0);
        $request = \end($this->client->requests);
        $request['respond']([], error: null);
        $this->assertStringContainsString('No quick fixes here', $this->flashed());

        [$controller] = $this->ready();
        $controller->requestCodeActions(0, 0);
        $request = \end($this->client->requests);
        $request['respond'](result: null, error: ['code' => -1, 'message' => 'nope']);
        $this->assertStringContainsString('No quick fixes here', $this->flashed());
    }

    public function testActionsWithNoTitleAreFilteredOut(): void
    {
        [$controller] = $this->ready();
        $controller->requestCodeActions(0, 0);
        $request = \end($this->client->requests);
        // A malformed entry (not an array) and a titleless action must both be
        // dropped, leaving only the real one — proving the filter runs, not
        // just that "no results" degrades gracefully.
        $request['respond'](['not-an-array', ['title' => ''], ['title' => 'Add import']], error: null);

        $screen = \implode("\n", \array_map(AnsiUtils::stripAnsiCodes(...), $this->editor->layoutPickers([0, 0, 20, 100])[0]['rows']));
        $this->assertStringContainsString('Add import', $screen);

        // Picking the only surviving row (index 0, since the malformed and
        // titleless entries were dropped, not just skipped in the display)
        // must resolve to the real action, not a stale/wrong index.
        $this->editor->handleInput("\r");
        $this->assertStringContainsString('Quick fix has nothing to apply', $this->flashed(), 'the surviving action ("Add import") has neither edit nor command');
    }

    public function testApplyingAnEditedActionAppliesItAndFlashesTheCountAndTitle(): void
    {
        $this->pickAction([
            [
                'title' => 'Fix it',
                'edit' => [
                    'changes' => [
                        'file:///tmp/proj/a.php' => [
                            ['range' => ['start' => ['line' => 1, 'character' => 0], 'end' => ['line' => 1, 'character' => 2]], 'newText' => 'y'],
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertStringContainsString('Applied "Fix it" to 1 file', $this->flashed());
        $this->assertSame("<?php\ny = 1;\n", $this->buffers->get('/tmp/proj/a.php')->document->getText(), 'the edit was actually applied');
    }

    public function testCommandGivenAsABareStringIsNormalizedAndSent(): void
    {
        $this->pickAction([
            ['title' => 'Run fixer', 'command' => 'porta.fix'],
        ]);

        $this->assertSame(
            ['command' => 'porta.fix', 'arguments' => []],
            \end($this->client->requests)['params'],
        );
    }

    public function testCommandGivenAsABareStringStillCarriesTheActionsOwnArguments(): void
    {
        $this->pickAction([
            ['title' => 'Run fixer', 'command' => 'porta.fix', 'arguments' => ['a', 'b']],
        ]);

        $this->assertSame(
            ['command' => 'porta.fix', 'arguments' => ['a', 'b']],
            \end($this->client->requests)['params'],
        );
    }

    public function testCommandGivenAsAStructuredArrayForwardsItsArguments(): void
    {
        $this->pickAction([
            ['title' => 'Run fixer', 'command' => ['command' => 'porta.fix', 'arguments' => ['a', 'b']]],
        ]);

        $this->assertSame(
            ['command' => 'porta.fix', 'arguments' => ['a', 'b']],
            \end($this->client->requests)['params'],
        );
    }

    public function testActionWithNeitherEditNorCommandFlashesNothingToApply(): void
    {
        [$controller] = $this->ready();
        $controller->requestCodeActions(0, 0);
        $request = \end($this->client->requests);
        $request['respond']([['title' => 'Do nothing']], error: null);

        $requested = $this->requestedRenderSince(function (): void {
            $this->editor->handleInput("\r");
        });

        $this->assertStringContainsString('Quick fix has nothing to apply', $this->flashed());
        $this->assertSame(['textDocument/codeAction'], \array_column($this->client->requests, 'method'), 'nothing was sent beyond the codeAction request itself');
        $this->assertTrue($requested, 'applyCodeAction always requests a render, even when nothing was applied');
    }

    public function testAnArrayCommandWithoutACommandKeySendsNothing(): void
    {
        $this->pickAction([
            ['title' => 'Malformed', 'command' => ['arguments' => ['a']]], // array, but no 'command' key
        ]);

        $this->assertStringContainsString('Quick fix has nothing to apply', $this->flashed());
        $this->assertSame(['textDocument/codeAction'], \array_column($this->client->requests, 'method'));
    }

    public function testAnEmptyStringCommandIsStillTreatedAsPresent(): void
    {
        // The check is presence (!== null), not truthiness — an empty-string
        // command name (falsy in PHP, but a real "no-op named command" LSP
        // servers can legitimately send) must still be forwarded, not dropped
        // as if the action had no command at all.
        $this->pickAction([
            ['title' => 'Weird but valid', 'command' => ['command' => '']],
        ]);

        $this->assertSame(['command' => '', 'arguments' => []], \end($this->client->requests)['params']);
    }

    public function testCommandTitleFallsBackThroughActionThenCommandThenLiteral(): void
    {
        // action.title present: wins over command.title.
        $this->pickAction([
            ['title' => 'Action Title', 'command' => ['command' => 'x', 'title' => 'Command Title']],
        ]);
        $request = \end($this->client->requests);
        $request['respond'](result: null, error: null);
        $this->assertStringContainsString('Ran "Action Title"', $this->flashed());

        // action.title empty (filtered from the picker), so instead exercise
        // command.title winning over the literal 'command' fallback by giving
        // the picker row its OWN distinct title while the command carries a
        // different one used only in the executeCommand flash.
        $this->pickAction([
            ['title' => 'Row Label', 'command' => ['command' => 'y']], // no command.title: falls to literal 'command'
        ]);
        $request = \end($this->client->requests);
        $request['respond'](result: null, error: null);
        $this->assertStringContainsString('Ran "Row Label"', $this->flashed());
    }

    public function testCommandSuccessAndFailureFlashDifferentMessages(): void
    {
        $this->pickAction([
            ['title' => 'Run fixer', 'command' => 'porta.fix'],
        ]);
        $request = \end($this->client->requests);
        $request['respond'](result: null, error: null);
        $this->assertStringContainsString('Ran "Run fixer"', $this->flashed());

        $this->pickAction([
            ['title' => 'Run fixer', 'command' => 'porta.fix'],
        ]);
        $request = \end($this->client->requests);
        $request['respond'](result: null, error: ['message' => 'boom']);
        $this->assertStringContainsString('"Run fixer" failed: boom', $this->flashed());
    }

    public function testCommandFailureWithNoMessageFallsBackToUnknownError(): void
    {
        $this->pickAction([
            ['title' => 'Run fixer', 'command' => 'porta.fix'],
        ]);
        $request = \end($this->client->requests);
        $request['respond'](result: null, error: []);
        $this->assertStringContainsString('"Run fixer" failed: unknown error', $this->flashed());
    }

    // ---------------------------------------------------------------- requestReformat

    public function testReformatWithoutAReadyServerSendsNothing(): void
    {
        $controller = $this->controller();
        $this->editor->showDocument('/tmp/proj/a.php', new Document("<?php\n"));

        $controller->requestReformat();

        // @mago-expect lint:no-isset -- $client is a non-nullable typed property; isset() is the only way to test whether it was ever initialized (the factory closure never ran)
        $this->assertFalse(isset($this->client), 'the guard stopped before a session was ever started');
    }

    public function testReformatWholeFileUsesSpacesOptionsByDefault(): void
    {
        [$controller] = $this->ready();

        $controller->requestReformat();

        $request = \end($this->client->requests);
        $this->assertSame('textDocument/formatting', $request['method']);
        $this->assertSame(
            ['textDocument' => ['uri' => 'file:///tmp/proj/a.php'], 'options' => $request['params']['options']],
            $request['params'],
        );
        $this->assertTrue($request['params']['options']['insertSpaces'], 'spaces by default');
    }

    public function testReformatOptionsUseTabWidthWhenTabbedAndIndentSizeWhenNot(): void
    {
        [$controller] = $this->ready();
        $doc = $this->buffers->get('/tmp/proj/a.php')->document;
        $doc->applyEditorConfig(ResolvedEditorConfig::fromProps(['indent_style' => 'space', 'indent_size' => '3', 'tab_width' => '9']));

        $controller->requestReformat();
        $request = \end($this->client->requests);
        $this->assertSame(3, $request['params']['options']['tabSize'], 'space-indented: uses indentSize');
        $this->assertTrue($request['params']['options']['insertSpaces']);

        [$controller] = $this->ready();
        $doc = $this->buffers->get('/tmp/proj/a.php')->document;
        $doc->applyEditorConfig(ResolvedEditorConfig::fromProps(['indent_style' => 'tab', 'indent_size' => '3', 'tab_width' => '9']));

        $controller->requestReformat();
        $request = \end($this->client->requests);
        $this->assertSame(9, $request['params']['options']['tabSize'], 'tab-indented: uses tabWidth');
        $this->assertFalse($request['params']['options']['insertSpaces']);
    }

    public function testReformatWithASelectionUsesRangeFormatting(): void
    {
        [$controller] = $this->ready();
        $doc = $this->buffers->get('/tmp/proj/a.php')->document;
        $doc->setSelectionRange(0, 1, 1, 2);

        $controller->requestReformat();

        $request = \end($this->client->requests);
        $this->assertSame('textDocument/rangeFormatting', $request['method']);
        $this->assertSame(
            [
                'textDocument' => ['uri' => 'file:///tmp/proj/a.php'],
                'range' => [
                    'start' => ['line' => 0, 'character' => 1],
                    'end' => ['line' => 1, 'character' => 2],
                ],
                'options' => $request['params']['options'],
            ],
            $request['params'],
        );
    }

    public function testReformatNotSupportedFlashesTheServerName(): void
    {
        [$controller] = $this->ready();

        $controller->requestReformat();
        $request = \end($this->client->requests);
        $request['respond']([], error: null);

        $this->assertStringContainsString('Formatting not supported by php-ls', $this->flashed());
    }

    public function testReformatErrorAlsoFlashesNotSupported(): void
    {
        [$controller] = $this->ready();

        $controller->requestReformat();
        $request = \end($this->client->requests);
        $request['respond'](result: null, error: ['message' => 'boom']);

        $this->assertStringContainsString('Formatting not supported by php-ls', $this->flashed());
    }

    public function testReformatErrorTakesPriorityEvenWithAWellFormedResult(): void
    {
        // An error alongside a well-formed, non-empty $result must still be
        // treated as a failure — the error check is not short-circuited by a
        // "the result looks fine" check.
        [$controller] = $this->ready();

        $controller->requestReformat();
        $request = \end($this->client->requests);
        $request['respond'](
            [['range' => ['start' => ['line' => 1, 'character' => 0], 'end' => ['line' => 1, 'character' => 2]], 'newText' => 'y']],
            ['message' => 'boom'],
        );

        $this->assertStringContainsString('Formatting not supported by php-ls', $this->flashed());
        $this->assertSame("<?php\n\$x = 1;\n", $this->buffers->get('/tmp/proj/a.php')->document->getText(), 'no edits applied despite a well-formed result');
    }

    public function testReformatAppliesEditsNotifiesAndFlashesTheRelativePath(): void
    {
        [$controller] = $this->ready();

        $controller->requestReformat();
        $request = \end($this->client->requests);
        $notificationsBefore = \count($this->client->notifications);
        $request['respond']([
            ['range' => ['start' => ['line' => 1, 'character' => 0], 'end' => ['line' => 1, 'character' => 2]], 'newText' => 'y'],
        ], error: null);

        $this->assertSame("<?php\ny = 1;\n", $this->buffers->get('/tmp/proj/a.php')->document->getText());
        $this->assertStringContainsString('Reformatted a.php', $this->flashed());
        $this->assertGreaterThan($notificationsBefore, \count($this->client->notifications), 'the reformat notifies a fresh full sync');
        $this->assertSame('textDocument/didChange', \end($this->client->notifications)['method']);
    }

    /**
     * A formatter that sends overlapping edits gets nothing applied, and the
     * flash says so rather than claiming a reformat that did not happen.
     *
     * Applying them turned `alpha beta` into `X` in the measurement that
     * opened this — a file neither the server nor the user asked for, reported
     * as a success.
     */
    /**
     * The user-facing half of the cross-file undo: it says which of the three
     * things happened, and never claims one that did not.
     */
    public function testUndoLastRefactoringSaysWhatHappened(): void
    {
        [$controller] = $this->ready();

        $controller->undoLastRefactoring();
        $this->assertStringContainsString('No refactoring to undo', $this->flashed(), 'nothing recorded yet');

        $buffer = $this->buffers->get('/tmp/proj/a.php');
        $this->undo->recordBuffer($buffer->path, $buffer->document->version);
        $this->assertTrue(
            $this->requestedRenderSince($controller->undoLastRefactoring(...)),
            'a frame is asked for — through the flash, which already does it',
        );
        $this->assertStringContainsString('Refactoring undone', $this->flashed());

        // And with the buffer moved on since, it refuses and names the file
        // RELATIVE to the project, like every other path this UI shows.
        $this->undo->recordBuffer($buffer->path, $buffer->document->version + 1);
        $controller->undoLastRefactoring();
        $this->assertStringContainsString('a.php changed since', $this->flashed());
    }

    public function testReformatWithOverlappingEditsChangesNothingAndSaysSo(): void
    {
        [$controller] = $this->ready();
        $before = $this->buffers->get('/tmp/proj/a.php')->document->getText();

        $controller->requestReformat();
        $request = \end($this->client->requests);
        $request['respond']([
            ['range' => ['start' => ['line' => 1, 'character' => 0], 'end' => ['line' => 1, 'character' => 5]], 'newText' => 'X'],
            ['range' => ['start' => ['line' => 1, 'character' => 2], 'end' => ['line' => 1, 'character' => 6]], 'newText' => 'Y'],
        ], error: null);

        $this->assertSame($before, $this->buffers->get('/tmp/proj/a.php')->document->getText(), 'untouched');
        $this->assertStringContainsString('overlapping edits', $this->flashed());
        $this->assertStringNotContainsString('Reformatted', $this->flashed(), 'and it does not claim otherwise');
    }

    public function testReformatForANonActiveBufferDoesNotInvalidateTheEditor(): void
    {
        [$controller, $pool] = $this->ready();
        // A second buffer becomes active; the first (being reformatted) is not,
        // by the time the response lands.
        $controller->requestReformat();
        $request = \end($this->client->requests);
        $second = $this->buffers->open('/tmp/proj/b.php', "<?php\n");
        $this->editor->showDocument('/tmp/proj/b.php', $second->document);
        $pool->notifyDidOpen('/tmp/proj/b.php', $second->document->getText(), 'php', 1);

        $request['respond']([
            ['range' => ['start' => ['line' => 1, 'character' => 0], 'end' => ['line' => 1, 'character' => 2]], 'newText' => 'y'],
        ], error: null);

        $this->assertSame("<?php\ny = 1;\n", $this->buffers->get('/tmp/proj/a.php')->document->getText(), 'the edit still applied to the target buffer');
        $this->assertSame('/tmp/proj/b.php', $this->editor->getFilePath(), 'the editor was not force-switched back to the reformatted (inactive) buffer');
    }

    /**
     * phpantom answers extract and inline with the title alone and the edit
     * behind codeAction/resolve. Applying such an action without resolving it
     * changes nothing at all — which is what "has nothing to apply" used to mean
     * for the two refactorings people most want.
     */
    public function testAnActionWithNoEditIsResolvedBeforeItIsApplied(): void
    {
        [$controller] = $this->ready(['codeActionProvider' => ['resolveProvider' => true]]);
        $controller->requestCodeActions(0, 0);
        \end($this->client->requests)['respond']([
            ['title' => 'Extract variable', 'kind' => 'refactor.extract', 'data' => ['handle' => 'x']],
        ], error: null);
        $this->editor->handleInput("\r");

        $resolve = \end($this->client->requests);
        $this->assertSame('codeAction/resolve', $resolve['method']);
        $this->assertSame(['handle' => 'x'], $resolve['params']['data'], 'the action goes back verbatim');

        $resolve['respond']([
            'title' => 'Extract variable',
            'edit' => [
                'changes' => [
                    Uri::fromPath('/tmp/proj/a.php') => [[
                        'range' => ['start' => ['line' => 1, 'character' => 0], 'end' => ['line' => 1, 'character' => 7]],
                        'newText' => '$y = 2;',
                    ]],
                ],
            ],
        ], error: null);

        $this->assertStringContainsString('$y = 2;', $this->buffers->get('/tmp/proj/a.php')->document->getText());
    }

    /** A resolve that fails must say so rather than pretending something happened. */
    public function testAFailedResolveStillReportsNothingToApply(): void
    {
        [$controller] = $this->ready(['codeActionProvider' => ['resolveProvider' => true]]);
        $controller->requestCodeActions(0, 0);
        \end($this->client->requests)['respond']([['title' => 'Extract variable', 'data' => ['handle' => 'x']]], error: null);
        $this->editor->handleInput("\r");
        \end($this->client->requests)['respond'](null, error: ['message' => 'nope']);

        $this->assertStringContainsString('nothing to apply', $this->flashed());
    }

    /**
     * The server's own preference goes first. Both actions are applicable, so a
     * test that only checked "something was applied" would pass either way.
     */
    public function testThePreferredActionIsOfferedFirst(): void
    {
        [$controller] = $this->ready();
        $controller->requestCodeActions(0, 0);
        $uri = Uri::fromPath('/tmp/proj/a.php');
        $edit = static fn (string $text): array => [
            'changes' => [
                $uri => [[
                    'range' => ['start' => ['line' => 1, 'character' => 0], 'end' => ['line' => 1, 'character' => 7]],
                    'newText' => $text,
                ]],
            ],
        ];
        \end($this->client->requests)['respond']([
            ['title' => 'Second', 'edit' => $edit('SECOND')],
            ['title' => 'First', 'isPreferred' => true, 'edit' => $edit('FIRST')],
        ], error: null);

        $this->editor->handleInput("\r");

        $this->assertStringContainsString('FIRST', $this->buffers->get('/tmp/proj/a.php')->document->getText());
    }

    /**
     * The server's own pick leads with ★; an action it cannot run here is
     * greyed with its reason for a hint, and picking it runs nothing and
     * leaves the list open to choose again.
     */
    public function testThePreferredFixIsStarredAndADisabledOneSaysWhy(): void
    {
        [$controller] = $this->ready();
        $controller->requestCodeActions(0, 0);
        \end($this->client->requests)['respond']([
            ['title' => 'Blocked', 'disabled' => ['reason' => 'Select an expression first'], 'edit' => $this->editOf('BLOCKED')],
            ['title' => 'Fix it', 'isPreferred' => true, 'kind' => 'quickfix', 'edit' => $this->editOf('FIXED')],
        ], error: null);

        $items = Probe::get(Probe::object($this->editor, 'overlayHost'), 'overlay')['items'];
        $this->assertSame(['★ Fix it', 'Blocked'], \array_column($items, 'label'), 'the preferred one first, and starred');
        $this->assertSame(['quickfix', 'Select an expression first'], \array_column($items, 'hint'));
        $this->assertSame([false, true], \array_column($items, 'disabled'));

        // The selection never rests on it, as a menu's never rests on a greyed item.
        $host = Probe::object($this->editor, 'overlayHost');
        $this->editor->handleInput("\x1b[B");
        $this->assertSame(0, Probe::get($host, 'overlay')['selected'], 'Down skips it, wrapping back to the one that can run');
        // A click on it runs nothing and leaves the list open.
        $host->layout([0, 0, 24, 100]);
        $box = Probe::get($host, 'overlayBox');
        $host->clickAt($box->startRow + $box->headerRows + 1, $box->col + 4);
        $this->assertStringNotContainsString('BLOCKED', $this->text(), 'nothing ran');
        $this->assertNotNull(Probe::get($host, 'overlay'), 'and the list is still there');
    }

    /** Refactor This offers its answers through the picker like quick fixes do. */
    public function testRefactorThisOffersItsAnswersThroughThePicker(): void
    {
        [$controller] = $this->ready();
        $controller->requestRefactorings(0, 0);
        \end($this->client->requests)['respond']([
            ['title' => 'Extract method', 'kind' => 'refactor.extract', 'edit' => $this->editOf('EXTRACTED')],
        ], error: null);
        $this->assertStringNotContainsString('EXTRACTED', $this->text(), 'nothing happens until you choose');

        $this->editor->handleInput("\r");

        $this->assertStringContainsString('EXTRACTED', $this->text());
    }

    /**
     * Inline is the one kind narrow enough to run without asking — but only when
     * the server returns exactly one. Two candidates is a question, and
     * answering it for the user would apply a refactoring they never picked.
     */
    public function testInlineAppliesASingleAnswerWithoutAsking(): void
    {
        [$controller] = $this->ready();
        $controller->requestInline(0, 0);

        \end($this->client->requests)['respond']([
            ['title' => 'Inline variable', 'kind' => 'refactor.inline', 'edit' => $this->editOf('INLINED')],
        ], error: null);

        $this->assertStringContainsString('INLINED', $this->text(), 'one answer needs no picker');
    }

    public function testInlineWithSeveralAnswersAsksWhichOne(): void
    {
        [$controller] = $this->ready();
        $controller->requestInline(0, 0);
        \end($this->client->requests)['respond']([
            ['title' => 'Inline variable', 'kind' => 'refactor.inline', 'edit' => $this->editOf('FIRST')],
            ['title' => 'Inline method', 'kind' => 'refactor.inline', 'edit' => $this->editOf('SECOND')],
        ], error: null);
        $this->assertStringNotContainsString('FIRST', $this->text(), 'two answers must not auto-apply');

        $this->editor->handleInput("\r");

        $this->assertStringContainsString('FIRST', $this->text());
    }

    /** A result of nothing but untitled entries is no result at all. */
    public function testActionsThatAreAllUntitledFlashNoQuickFixes(): void
    {
        [$controller] = $this->ready();
        $controller->requestCodeActions(0, 0);

        \end($this->client->requests)['respond']([['kind' => 'quickfix'], ['title' => '']], error: null);

        $this->assertStringContainsString('No quick fixes here', $this->flashed());
    }

    /** A refused edit says so — the count alone would read as success. */
    public function testARefusedEditIsReportedAsRefused(): void
    {
        $project = new TempProject(['taken.php' => "x\n"]);
        [$controller] = $this->ready();
        $controller->requestCodeActions(0, 0);
        \end($this->client->requests)['respond']([[
            'title' => 'Extract to class',
            'edit' => ['documentChanges' => [['kind' => 'create', 'uri' => Uri::fromPath($project->path('taken.php'))]]],
        ]], error: null);

        $this->editor->handleInput("\r");

        $this->assertStringContainsString('nothing was changed', $this->flashed());
    }

    /** And a partial one names how much did not land. */
    public function testAnEditWithUnwritableFilesReportsHowManyWereSkipped(): void
    {
        [$controller] = $this->ready();
        $controller->requestCodeActions(0, 0);
        \end($this->client->requests)['respond']([[
            'title' => 'Rename everywhere',
            'edit' => [
                'changes' => [
                    Uri::fromPath('/tmp/proj/a.php') => [self::edit(1, 0, 1, 7, 'ok')],
                    'file:///does/not/exist.php' => [self::edit(0, 0, 0, 0, 'x')],
                ],
            ],
        ]], error: null);

        $this->editor->handleInput("\r");

        $this->assertStringContainsString('could not be written', $this->flashed());
    }

    /** @return array{range: array{start: array{line: int, character: int}, end: array{line: int, character: int}}, newText: string} */
    private static function edit(int $sl, int $sc, int $el, int $ec, string $text): array
    {
        return ['range' => ['start' => ['line' => $sl, 'character' => $sc], 'end' => ['line' => $el, 'character' => $ec]], 'newText' => $text];
    }

    /** The open buffer's text — what an applied refactoring actually changes. */
    private function text(): string
    {
        return $this->buffers->get('/tmp/proj/a.php')?->document->getText() ?? '';
    }

    /** @return array<string, mixed> a WorkspaceEdit replacing line 1 with $text */
    private function editOf(string $text): array
    {
        return ['changes' => [Uri::fromPath('/tmp/proj/a.php') => [self::edit(1, 0, 1, 7, $text)]]];
    }

    /** The request asks for the one kind, so a server that filters sends back only that. */
    public function testOrganizeImportsAsksForItsOwnKindAlone(): void
    {
        [$controller] = $this->ready();

        $controller->requestOrganizeImports(1, 0);

        $params = \end($this->client->requests)['params'];
        $this->assertSame(['source.organizeImports'], $params['context']['only']);
    }

    /**
     * Organising imports is a whole-file operation, not a choice between
     * alternatives, so one answer applies without a picker — the same rule
     * inline follows.
     */
    public function testASingleOrganizeAnswerAppliesWithoutAsking(): void
    {
        [$controller] = $this->ready();
        $controller->requestOrganizeImports(1, 0);

        \end($this->client->requests)['respond']([
            ['title' => 'Organize imports', 'kind' => 'source.organizeImports', 'edit' => $this->editOf('ORGANIZED')],
        ], error: null);

        $this->assertStringContainsString('ORGANIZED', $this->text(), 'applied straight away');
    }

    /** More than one, and it is a choice again. */
    public function testSeveralOrganizeAnswersOfferAPicker(): void
    {
        [$controller] = $this->ready();
        $controller->requestOrganizeImports(1, 0);

        \end($this->client->requests)['respond']([
            ['title' => 'Organize imports', 'kind' => 'source.organizeImports', 'edit' => $this->editOf('FIRST')],
            ['title' => 'Organize and sort', 'kind' => 'source.organizeImports', 'edit' => $this->editOf('SECOND')],
        ], error: null);

        $this->assertStringNotContainsString('FIRST', $this->text(), 'nothing happens until you choose');
        $this->editor->handleInput("\r");
        $this->assertStringContainsString('FIRST', $this->text());
    }

    /**
     * Nothing to organise is a normal outcome and gets its own words —
     * "no quick fixes here" would answer a question nobody asked.
     */
    public function testAnEmptyOrganizeAnswerSaysThereWasNothingToOrganize(): void
    {
        [$controller] = $this->ready();
        $controller->requestOrganizeImports(1, 0);

        \end($this->client->requests)['respond']([], error: null);

        $flashed = $this->flashed();
        $this->assertStringContainsString('Nothing to organize', $flashed);
        $this->assertStringNotContainsString('No quick fixes here', $flashed);
    }

    /**
     * Quick fixes from every server land in ONE list, in answer order.
     *
     * Read by choosing each position and seeing whose edit lands, because the
     * list is what the user picks from — a Symfony server's fix sitting under
     * a PHP server's is the whole point.
     */
    public function testActionsFromEveryServerAreMergedIntoOneList(): void
    {
        foreach ([0 => 'FIRST', 1 => 'SECOND'] as $index => $expected) {
            $this->clients = [];
            [$controller] = $this->ready(servers: 2);
            $controller->requestCodeActions(0, 0);
            $this->clients[0]->requests[0]['respond']([['title' => 'Import class', 'edit' => $this->editOf('FIRST')]], error: null);
            $this->clients[1]->requests[0]['respond']([['title' => 'Add route attribute', 'edit' => $this->editOf('SECOND')]], error: null);

            for ($i = 0; $i < $index; ++$i) {
                $this->editor->handleInput("\x1b[B");
            }
            $this->editor->handleInput("\r");

            $this->assertStringContainsString($expected, $this->text(), "position {$index} is the answer from server {$index}");
        }
    }

    /**
     * One server having nothing does not blank what the other offered.
     *
     * The "no quick fixes" flash is raised per ANSWER, so the empty one has to
     * look at what is already in hand rather than at its own reply.
     */
    public function testAnEmptyAnswerDoesNotBlankAnotherServersActions(): void
    {
        $this->clients = [];
        [$controller] = $this->ready(servers: 2);

        $controller->requestCodeActions(0, 0);
        $this->clients[0]->requests[0]['respond']([['title' => 'Import class', 'edit' => $this->editOf('KEPT')]], error: null);
        $this->clients[1]->requests[0]['respond']([], error: null);

        // Asserted HERE, before anything else flashes over it: applying below
        // raises its own message, and reading the bar afterwards would pass
        // whatever this answer had said.
        $this->assertStringNotContainsString('No quick fixes', $this->flashed(), 'another server had something, so there ARE quick fixes here');

        $this->editor->handleInput("\r");
        $this->assertStringContainsString('KEPT', $this->text(), 'the empty answer left the picker alone');
    }

    // ---------------------------------------------------------------- diagnostic messages in the menu

    /** @return list<string> the open quick-fix list's rows, plain */
    private function pickerRows(): array
    {
        $pickers = $this->editor->layoutPickers([0, 0, 20, 100]);

        return [] === $pickers ? [] : \array_values(\array_filter(\array_map(static fn (string $r): string => \trim(AnsiUtils::stripAnsiCodes($r)), $pickers[0]['rows'])));
    }

    private function twoProblemsOnLineOne(): void
    {
        $this->editor->display->setDiagnosticMessages(DiagnosticMessageMode::Menu);
        $this->buffers->active()->diagnostics = [
            1 => [
                ['severity' => DiagnosticSeverity::Warning, 'message' => "unused\n  variable", 'col' => 0, 'endCol' => 2],
                ['severity' => DiagnosticSeverity::Error, 'message' => 'boom', 'col' => 0, 'endCol' => 2, 'source' => 'phpstan'],
            ],
        ];
    }

    /**
     * PhpStorm's shape: the problems on the line, worst first, above what can
     * be done about them — and the list still runs the action picked, not the
     * row that sits at that index now that problems are above it.
     */
    public function testInMenuModeTheLinesProblemsHeadTheQuickFixes(): void
    {
        [$controller] = $this->ready();
        $this->twoProblemsOnLineOne();

        $controller->requestCodeActions(1, 0);
        \end($this->client->requests)['respond']([['title' => 'First fix', 'edit' => $this->editOf('FIRST')], ['title' => 'Second fix', 'edit' => $this->editOf('SECOND')]], error: null);

        $screen = \implode("\n", $this->pickerRows());
        $order = \array_map(static fn (string $needle): int|false => \strpos($screen, $needle), ['boom', 'unused variable', 'First fix', 'Second fix']);
        $this->assertNotContains(false, $order, 'every problem and every fix is listed: '.$screen);
        $sorted = $order;
        \sort($sorted);
        $this->assertSame($sorted, $order, 'the error, then the warning, then the fixes in the server\'s order');
        $this->assertStringContainsString('phpstan', $screen, 'a problem says where it came from');

        $this->editor->handleInput("\r");
        $this->assertStringContainsString('FIRST', $this->text(), 'Enter runs the first FIX: a problem row is there to be read');
    }

    public function testInMenuModeAProblemWithNoFixStillOpensTheList(): void
    {
        [$controller] = $this->ready();
        $this->twoProblemsOnLineOne();

        $controller->requestCodeActions(1, 0);
        \end($this->client->requests)['respond']([], error: null);

        $this->assertStringContainsString('boom', \implode("\n", $this->pickerRows()), 'the problems are what there is to show');
        $this->assertStringNotContainsString('No quick fixes', $this->flashed());
    }

    /** A tool plugin's findings, or a server not up yet: the problems need no server to be read. */
    public function testInMenuModeProblemsShowWithNoServerAtAll(): void
    {
        $controller = $this->controller();
        $buffer = $this->buffers->open('/tmp/proj/a.php', "<?php\n\$x = 1;\n");
        $this->editor->showDocument('/tmp/proj/a.php', $buffer->document);
        $this->twoProblemsOnLineOne();

        $controller->requestCodeActions(1, 0);

        $this->assertStringContainsString('boom', \implode("\n", $this->pickerRows()));
        $this->assertStringNotContainsString('No language server', $this->flashed(), 'nothing to explain: the list answered');
        // @mago-expect lint:no-isset -- $client is a non-nullable typed property; isset() is the only way to test whether it was ever initialized
        $this->assertFalse(isset($this->client), 'and no server was started to ask');
    }

    public function testWithMessagesInlineTheMenuHoldsOnlyTheFixes(): void
    {
        [$controller] = $this->ready();
        $this->twoProblemsOnLineOne();
        $this->editor->display->setDiagnosticMessages(DiagnosticMessageMode::Inline);

        $controller->requestCodeActions(1, 0);
        \end($this->client->requests)['respond']([['title' => 'First fix', 'edit' => $this->editOf('FIRST')]], error: null);

        $screen = \implode("\n", $this->pickerRows());
        $this->assertStringContainsString('First fix', $screen);
        $this->assertStringNotContainsString('boom', $screen, 'the message is after the code, not here');
    }

    /** Each server's answer re-presents the list; the problems are listed once however many answer. */
    public function testTwoAnsweringServersListTheProblemsOnce(): void
    {
        $this->clients = [];
        [$controller] = $this->ready(servers: 2);
        $this->twoProblemsOnLineOne();

        $controller->requestCodeActions(1, 0);
        $this->clients[0]->requests[0]['respond']([['title' => 'First fix', 'edit' => $this->editOf('FIRST')]], error: null);
        $this->clients[1]->requests[0]['respond']([['title' => 'Second fix', 'edit' => $this->editOf('SECOND')]], error: null);

        $screen = \implode("\n", $this->pickerRows());
        $this->assertSame(1, \substr_count($screen, needle: 'boom'));
        $this->assertStringContainsString('Second fix', $screen);
    }

    /** Applying an action goes back to the server that OFFERED it. */
    public function testApplyingAnActionGoesBackToTheOfferingServer(): void
    {
        $this->clients = [];
        [$controller] = $this->ready(['codeActionProvider' => ['resolveProvider' => true]], servers: 2);

        $controller->requestCodeActions(0, 0);
        $this->clients[0]->requests[0]['respond']([['title' => 'Mine', 'data' => ['h' => 1]]], error: null);
        $this->clients[1]->requests[0]['respond']([['title' => 'Theirs', 'data' => ['h' => 2]]], error: null);

        $this->editor->handleInput("\x1b[B"); // down to the second
        $this->editor->handleInput("\r");

        $this->assertSame(['textDocument/codeAction'], \array_column($this->clients[0]->requests, 'method'), 'the other server was not asked');
        $this->assertSame(['textDocument/codeAction', 'codeAction/resolve'], \array_column($this->clients[1]->requests, 'method'));
        $this->assertSame(['title' => 'Theirs', 'data' => ['h' => 2]], $this->clients[1]->requests[1]['params'], 'the bookkeeping key does not travel back onto the wire');
    }

    /**
     * A late answer to a superseded request does not open its picker.
     *
     * Several servers answer one Ctrl+. at their own speeds while the caret
     * moves on. Without a generation the slow answer to the request before
     * last would put up a list of fixes for a position the user has left.
     */
    public function testALateAnswerToASupersededRequestIsIgnored(): void
    {
        $this->clients = [];
        [$controller] = $this->ready();

        $controller->requestCodeActions(0, 0);
        $stale = $this->clients[0]->requests[0]['respond'];
        $controller->requestCodeActions(1, 0); // the caret moved

        $stale([['title' => 'For where the caret WAS', 'edit' => $this->editOf('STALE')]], error: null);
        $this->editor->handleInput("\r");

        $this->assertStringNotContainsString('STALE', $this->text(), 'no picker was open to accept the Enter');
    }

    /**
     * An action that already carries a COMMAND is run, not resolved — even on
     * a server that resolves.
     *
     * Resolve exists to fetch what an action is missing. One that arrived with
     * its verb is missing nothing, and a round trip for it is a round trip
     * the user waits through for no answer.
     */
    public function testACommandOnlyActionIsRunWithoutAResolveRoundTrip(): void
    {
        $this->clients = [];
        [$controller] = $this->ready(['codeActionProvider' => ['resolveProvider' => true]]);

        $controller->requestCodeActions(0, 0);
        $this->clients[0]->requests[0]['respond']([
            ['title' => 'Run the fixer', 'command' => ['command' => 'porta.fix', 'arguments' => []]],
        ], error: null);
        $this->editor->handleInput("\r");

        $this->assertSame(
            ['textDocument/codeAction', 'workspace/executeCommand'],
            \array_column($this->clients[0]->requests, 'method'),
            'straight to the verb it came with',
        );
    }
}
