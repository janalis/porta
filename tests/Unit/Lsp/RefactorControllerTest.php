<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Lsp;

use PHPUnit\Framework\TestCase;
use Porta\Config\EditorConfig\ResolvedEditorConfig;
use Porta\Editor\BufferManager;
use Porta\I18n\Translator;
use Porta\Lsp\LspSessionPool;
use Porta\Lsp\RefactorController;
use Porta\Lsp\WorkspaceEditor;
use Porta\Support\ProjectPaths;
use Porta\Tests\Support\FakeLspClient;
use Porta\Tests\Support\FixedActiveFile;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\TestTui;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\StatusBarWidget;
use Porta\Workbench\Notifier;
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
            new WorkspaceEditor($this->buffers, $this->editor, $pool),
            new ProjectPaths('/tmp/proj'),
        );
    }

    /** @return array{0: RefactorController, 1: LspSessionPool} a controller with an initialized session on a.php */
    private function ready(): array
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
        $controller = new RefactorController(
            $pool,
            $this->editor,
            $tui,
            new Notifier($this->statusBar, $tui),
            new Translator('en'),
            $this->buffers,
            new WorkspaceEditor($this->buffers, $this->editor, $pool),
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
}
