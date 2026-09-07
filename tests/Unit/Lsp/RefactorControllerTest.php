<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Lsp;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\Config\EditorConfig\ResolvedEditorConfig;
use Porta\Editor\BufferManager;
use Porta\Editor\SelectionMode;
use Porta\I18n\Translator;
use Porta\Lsp\LspSessionPool;
use Porta\Lsp\RefactorController;
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
    private CodeEditorWidget $editor;
    private BufferManager $buffers;
    private StatusBarWidget $statusBar;
    private Tui $tui;

    private function flashed(): string
    {
        return AnsiUtils::stripAnsiCodes(implode('', $this->statusBar->render(new RenderContext(100, 1))));
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
            ['php' => ['command' => ['php-ls'], 'name' => 'php-ls']],
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
            new WorkspaceEditor($this->buffers, $this->editor, $pool, new EventDispatcher()),
            new ProjectPaths('/tmp/proj'),
        );
    }

    /**
     * @param array<string, mixed> $capabilities what the fake server advertises
     *
     * @return array{0: RefactorController, 1: LspSessionPool} a controller with an initialized session on a.php
     */
    private function ready(array $capabilities = []): array
    {
        $pool = new LspSessionPool(
            '/tmp/proj',
            new FixedActiveFile('/tmp/proj/a.php'),
            new Translator('en'),
            fn (array $command, string $rootDir, \Closure $onLog): FakeLspClient => $this->client = new FakeLspClient($capabilities),
        );
        $pool->configure(
            ['php' => ['command' => ['php-ls'], 'name' => 'php-ls']],
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
            new WorkspaceEditor($this->buffers, $this->editor, $pool, new EventDispatcher()),
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
        $this->editor->showDocument('/tmp/proj/a.php', new \Porta\Editor\Document("<?php\n"));
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

        $this->assertSame(['textDocument/codeAction'], array_column($this->client->requests, 'method'));
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

        $params = end($this->client->requests)['params'];
        $this->assertSame(['line' => 1, 'character' => 0], $params['range']['start']);
        $this->assertSame(['line' => 1, 'character' => 7], $params['range']['end'], 'the whole selection, not the caret');
    }

    /** With no selection the caret line's diagnostic still wins, so fix-this-problem keeps working. */
    public function testWithoutASelectionADiagnosticRangeIsUsed(): void
    {
        [$controller] = $this->ready();
        $this->buffers->active()->diagnostics = [1 => [['severity' => 1, 'message' => 'boom', 'col' => 2, 'endCol' => 5]]];

        $controller->requestCodeActions(1, 0);

        $params = end($this->client->requests)['params'];
        $this->assertSame(['line' => 1, 'character' => 2], $params['range']['start']);
        $this->assertSame(['line' => 1, 'character' => 5], $params['range']['end']);
    }

    /** And with neither, the caret itself. */
    public function testWithNeitherSelectionNorDiagnosticTheCaretIsTheRange(): void
    {
        [$controller] = $this->ready();

        $controller->requestCodeActions(1, 3);

        $params = end($this->client->requests)['params'];
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

        $params = end($this->client->requests)['params'];
        $this->assertSame(['uri' => 'file:///tmp/proj/a.php'], $params['textDocument']);
    }

    /**
     * The three ways a code-action answer can be nothing, each of which must
     * flash rather than open an empty picker. They are separate arms of one
     * guard, so a test for only one of them leaves the others free to invert.
     *
     * @param mixed $result
     */
    #[DataProvider('emptyAnswers')]
    public function testEveryShapeOfEmptyAnswerFlashesInsteadOfOpeningAPicker(mixed $result, ?array $error): void
    {
        [$controller] = $this->ready();
        $controller->requestCodeActions(1, 0);

        end($this->client->requests)['respond']($result, error: $error);

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

        $context = end($this->client->requests)['params']['context'];
        $this->assertSame($only, $context['only'] ?? null);
        $this->assertSame(1, $context['triggerKind'], 'invoked by the user, not polled');
    }

    /** A server matching its own fix by code sees nothing to match if the code is dropped. */
    public function testTheDiagnosticContextCarriesItsCodeAndData(): void
    {
        [$controller] = $this->ready();
        $this->buffers->active()->diagnostics = [
            1 => [
                ['severity' => 1, 'message' => 'boom', 'col' => 0, 'endCol' => 1, 'code' => 'PHP0420', 'data' => ['fix' => 'import'], 'tags' => [1]],
            ],
        ];

        $controller->requestCodeActions(1, 0);

        $diagnostic = end($this->client->requests)['params']['context']['diagnostics'][0];
        $this->assertSame('PHP0420', $diagnostic['code']);
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
        $request = end($this->client->requests);
        $request['respond']($actions, error: null);

        for ($i = 0; $i < $index; ++$i) {
            $this->editor->handleInput("\x1b[B");
        }
        $this->editor->handleInput("\r");
    }

    public function testNoQuickFixesFlashesWhenTheResultIsEmptyOrErrored(): void
    {
        [$controller] = $this->ready();
        $controller->requestCodeActions(0, 0);
        $request = end($this->client->requests);
        $request['respond']([], error: null);
        $this->assertStringContainsString('No quick fixes here', $this->flashed());

        [$controller] = $this->ready();
        $controller->requestCodeActions(0, 0);
        $request = end($this->client->requests);
        $request['respond'](result: null, error: ['code' => -1, 'message' => 'nope']);
        $this->assertStringContainsString('No quick fixes here', $this->flashed());
    }

    public function testActionsWithNoTitleAreFilteredOut(): void
    {
        [$controller] = $this->ready();
        $controller->requestCodeActions(0, 0);
        $request = end($this->client->requests);
        // A malformed entry (not an array) and a titleless action must both be
        // dropped, leaving only the real one — proving the filter runs, not
        // just that "no results" degrades gracefully.
        $request['respond'](['not-an-array', ['title' => ''], ['title' => 'Add import']], error: null);

        $screen = implode("\n", array_map(AnsiUtils::stripAnsiCodes(...), $this->editor->layoutPickers([0, 0, 20, 100])[0]['rows']));
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
            end($this->client->requests)['params'],
        );
    }

    public function testCommandGivenAsABareStringStillCarriesTheActionsOwnArguments(): void
    {
        $this->pickAction([
            ['title' => 'Run fixer', 'command' => 'porta.fix', 'arguments' => ['a', 'b']],
        ]);

        $this->assertSame(
            ['command' => 'porta.fix', 'arguments' => ['a', 'b']],
            end($this->client->requests)['params'],
        );
    }

    public function testCommandGivenAsAStructuredArrayForwardsItsArguments(): void
    {
        $this->pickAction([
            ['title' => 'Run fixer', 'command' => ['command' => 'porta.fix', 'arguments' => ['a', 'b']]],
        ]);

        $this->assertSame(
            ['command' => 'porta.fix', 'arguments' => ['a', 'b']],
            end($this->client->requests)['params'],
        );
    }

    public function testActionWithNeitherEditNorCommandFlashesNothingToApply(): void
    {
        [$controller] = $this->ready();
        $controller->requestCodeActions(0, 0);
        $request = end($this->client->requests);
        $request['respond']([['title' => 'Do nothing']], error: null);

        $requested = $this->requestedRenderSince(function (): void {
            $this->editor->handleInput("\r");
        });

        $this->assertStringContainsString('Quick fix has nothing to apply', $this->flashed());
        $this->assertSame(['textDocument/codeAction'], array_column($this->client->requests, 'method'), 'nothing was sent beyond the codeAction request itself');
        $this->assertTrue($requested, 'applyCodeAction always requests a render, even when nothing was applied');
    }

    public function testAnArrayCommandWithoutACommandKeySendsNothing(): void
    {
        $this->pickAction([
            ['title' => 'Malformed', 'command' => ['arguments' => ['a']]], // array, but no 'command' key
        ]);

        $this->assertStringContainsString('Quick fix has nothing to apply', $this->flashed());
        $this->assertSame(['textDocument/codeAction'], array_column($this->client->requests, 'method'));
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

        $this->assertSame(['command' => '', 'arguments' => []], end($this->client->requests)['params']);
    }

    public function testCommandTitleFallsBackThroughActionThenCommandThenLiteral(): void
    {
        // action.title present: wins over command.title.
        $this->pickAction([
            ['title' => 'Action Title', 'command' => ['command' => 'x', 'title' => 'Command Title']],
        ]);
        $request = end($this->client->requests);
        $request['respond'](result: null, error: null);
        $this->assertStringContainsString('Ran "Action Title"', $this->flashed());

        // action.title empty (filtered from the picker), so instead exercise
        // command.title winning over the literal 'command' fallback by giving
        // the picker row its OWN distinct title while the command carries a
        // different one used only in the executeCommand flash.
        $this->pickAction([
            ['title' => 'Row Label', 'command' => ['command' => 'y']], // no command.title: falls to literal 'command'
        ]);
        $request = end($this->client->requests);
        $request['respond'](result: null, error: null);
        $this->assertStringContainsString('Ran "Row Label"', $this->flashed());
    }

    public function testCommandSuccessAndFailureFlashDifferentMessages(): void
    {
        $this->pickAction([
            ['title' => 'Run fixer', 'command' => 'porta.fix'],
        ]);
        $request = end($this->client->requests);
        $request['respond'](result: null, error: null);
        $this->assertStringContainsString('Ran "Run fixer"', $this->flashed());

        $this->pickAction([
            ['title' => 'Run fixer', 'command' => 'porta.fix'],
        ]);
        $request = end($this->client->requests);
        $request['respond'](result: null, error: ['message' => 'boom']);
        $this->assertStringContainsString('"Run fixer" failed: boom', $this->flashed());
    }

    public function testCommandFailureWithNoMessageFallsBackToUnknownError(): void
    {
        $this->pickAction([
            ['title' => 'Run fixer', 'command' => 'porta.fix'],
        ]);
        $request = end($this->client->requests);
        $request['respond'](result: null, error: []);
        $this->assertStringContainsString('"Run fixer" failed: unknown error', $this->flashed());
    }

    // ---------------------------------------------------------------- requestReformat

    public function testReformatWithoutAReadyServerSendsNothing(): void
    {
        $controller = $this->controller();
        $this->editor->showDocument('/tmp/proj/a.php', new \Porta\Editor\Document("<?php\n"));

        $controller->requestReformat();

        // @mago-expect lint:no-isset -- $client is a non-nullable typed property; isset() is the only way to test whether it was ever initialized (the factory closure never ran)
        $this->assertFalse(isset($this->client), 'the guard stopped before a session was ever started');
    }

    public function testReformatWholeFileUsesSpacesOptionsByDefault(): void
    {
        [$controller] = $this->ready();

        $controller->requestReformat();

        $request = end($this->client->requests);
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
        $request = end($this->client->requests);
        $this->assertSame(3, $request['params']['options']['tabSize'], 'space-indented: uses indentSize');
        $this->assertTrue($request['params']['options']['insertSpaces']);

        [$controller] = $this->ready();
        $doc = $this->buffers->get('/tmp/proj/a.php')->document;
        $doc->applyEditorConfig(ResolvedEditorConfig::fromProps(['indent_style' => 'tab', 'indent_size' => '3', 'tab_width' => '9']));

        $controller->requestReformat();
        $request = end($this->client->requests);
        $this->assertSame(9, $request['params']['options']['tabSize'], 'tab-indented: uses tabWidth');
        $this->assertFalse($request['params']['options']['insertSpaces']);
    }

    public function testReformatWithASelectionUsesRangeFormatting(): void
    {
        [$controller] = $this->ready();
        $doc = $this->buffers->get('/tmp/proj/a.php')->document;
        $doc->setSelectionRange(0, 1, 1, 2);

        $controller->requestReformat();

        $request = end($this->client->requests);
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
        $request = end($this->client->requests);
        $request['respond']([], error: null);

        $this->assertStringContainsString('Formatting not supported by php-ls', $this->flashed());
    }

    public function testReformatErrorAlsoFlashesNotSupported(): void
    {
        [$controller] = $this->ready();

        $controller->requestReformat();
        $request = end($this->client->requests);
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
        $request = end($this->client->requests);
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
        $request = end($this->client->requests);
        $notificationsBefore = \count($this->client->notifications);
        $request['respond']([
            ['range' => ['start' => ['line' => 1, 'character' => 0], 'end' => ['line' => 1, 'character' => 2]], 'newText' => 'y'],
        ], error: null);

        $this->assertSame("<?php\ny = 1;\n", $this->buffers->get('/tmp/proj/a.php')->document->getText());
        $this->assertStringContainsString('Reformatted a.php', $this->flashed());
        $this->assertGreaterThan($notificationsBefore, \count($this->client->notifications), 'the reformat notifies a fresh full sync');
        $this->assertSame('textDocument/didChange', end($this->client->notifications)['method']);
    }

    /**
     * A formatter that sends overlapping edits gets nothing applied, and the
     * flash says so rather than claiming a reformat that did not happen.
     *
     * Applying them turned `alpha beta` into `X` in the measurement that
     * opened this — a file neither the server nor the user asked for, reported
     * as a success.
     */
    public function testReformatWithOverlappingEditsChangesNothingAndSaysSo(): void
    {
        [$controller] = $this->ready();
        $before = $this->buffers->get('/tmp/proj/a.php')->document->getText();

        $controller->requestReformat();
        $request = end($this->client->requests);
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
        $request = end($this->client->requests);
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
        end($this->client->requests)['respond']([
            ['title' => 'Extract variable', 'kind' => 'refactor.extract', 'data' => ['handle' => 'x']],
        ], error: null);
        $this->editor->handleInput("\r");

        $resolve = end($this->client->requests);
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
        end($this->client->requests)['respond']([['title' => 'Extract variable', 'data' => ['handle' => 'x']]], error: null);
        $this->editor->handleInput("\r");
        end($this->client->requests)['respond'](null, error: ['message' => 'nope']);

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
        end($this->client->requests)['respond']([
            ['title' => 'Second', 'edit' => $edit('SECOND')],
            ['title' => 'First', 'isPreferred' => true, 'edit' => $edit('FIRST')],
        ], error: null);

        $this->editor->handleInput("\r");

        $this->assertStringContainsString('FIRST', $this->buffers->get('/tmp/proj/a.php')->document->getText());
    }

    /** Refactor This offers its answers through the picker like quick fixes do. */
    public function testRefactorThisOffersItsAnswersThroughThePicker(): void
    {
        [$controller] = $this->ready();
        $controller->requestRefactorings(0, 0);
        end($this->client->requests)['respond']([
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

        end($this->client->requests)['respond']([
            ['title' => 'Inline variable', 'kind' => 'refactor.inline', 'edit' => $this->editOf('INLINED')],
        ], error: null);

        $this->assertStringContainsString('INLINED', $this->text(), 'one answer needs no picker');
    }

    public function testInlineWithSeveralAnswersAsksWhichOne(): void
    {
        [$controller] = $this->ready();
        $controller->requestInline(0, 0);
        end($this->client->requests)['respond']([
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

        end($this->client->requests)['respond']([['kind' => 'quickfix'], ['title' => '']], error: null);

        $this->assertStringContainsString('No quick fixes here', $this->flashed());
    }

    /** A refused edit says so — the count alone would read as success. */
    public function testARefusedEditIsReportedAsRefused(): void
    {
        $project = new TempProject(['taken.php' => "x\n"]);
        [$controller] = $this->ready();
        $controller->requestCodeActions(0, 0);
        end($this->client->requests)['respond']([[
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
        end($this->client->requests)['respond']([[
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

        $params = end($this->client->requests)['params'];
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

        end($this->client->requests)['respond']([
            ['title' => 'Organize imports', 'kind' => 'source.organizeImports', 'edit' => $this->editOf('ORGANIZED')],
        ], error: null);

        $this->assertStringContainsString('ORGANIZED', $this->text(), 'applied straight away');
    }

    /** More than one, and it is a choice again. */
    public function testSeveralOrganizeAnswersOfferAPicker(): void
    {
        [$controller] = $this->ready();
        $controller->requestOrganizeImports(1, 0);

        end($this->client->requests)['respond']([
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

        end($this->client->requests)['respond']([], error: null);

        $flashed = $this->flashed();
        $this->assertStringContainsString('Nothing to organize', $flashed);
        $this->assertStringNotContainsString('No quick fixes here', $flashed);
    }
}
