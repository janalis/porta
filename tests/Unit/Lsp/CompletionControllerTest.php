<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Lsp;

use PHPUnit\Framework\TestCase;
use Porta\Editor\CompletionItem;
use Porta\Editor\Document;
use Porta\Editor\InlayHint;
use Porta\Editor\InlayHintMode;
use Porta\I18n\Translator;
use Porta\Lsp\CompletionController;
use Porta\Lsp\LspSessionPool;
use Porta\Support\Scheduler;
use Porta\Tests\Support\FakeLspClient;
use Porta\Tests\Support\FakeLspPool;
use Porta\Tests\Support\FakeScheduler;
use Porta\Tests\Support\FixedActiveFile;
use Porta\Tests\Support\Probe;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\EditorCallbacks;
use Porta\Widget\StatusBarWidget;
use Porta\Workbench\Notifier;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

final class CompletionControllerTest extends TestCase
{
    private CodeEditorWidget $editor;
    private FixedActiveFile $activeFile;
    private FakeLspClient $client;
    private Tui $tui;

    /** @param array<string, mixed> $capabilities */
    private function controller(array $capabilities = ['inlayHintProvider' => true], ?Scheduler $scheduler = null): CompletionController
    {
        $this->editor = new CodeEditorWidget();
        $this->tui = new Tui(terminal: new VirtualTerminal(columns: 100, rows: 20));
        $notifier = new Notifier(new StatusBarWidget(), $this->tui);
        $translator = new Translator('en');
        $pool = FakeLspPool::create('/p', $this->activeFile = new FixedActiveFile(), fn (): FakeLspClient => $this->client = new FakeLspClient($capabilities));

        return new CompletionController($pool, $this->editor, $this->tui, $notifier, $translator, $scheduler);
    }

    /** Opens $path in the editor and the fake LSP session, ready to answer requests. */
    /**
     * A controller over TWO servers on php, each with its own fake client —
     * a general server and one that runs beside it.
     *
     * @param array<string, mixed>|null $firstCapabilities what the FIRST server declares, when a
     *        test needs the two to differ
     *
     * @return array{0: CompletionController, 1: list<FakeLspClient>}
     */
    private function twoServerController(?array $firstCapabilities = null): array
    {
        $this->editor = new CodeEditorWidget();
        $this->tui = new Tui(terminal: new VirtualTerminal(columns: 100, rows: 20));
        $built = [];
        $pool = new LspSessionPool(
            '/p',
            $this->activeFile = new FixedActiveFile(),
            new Translator('en'),
            static function (array $command, string $rootDir, \Closure $onLog) use (&$built, $firstCapabilities): FakeLspClient {
                $resolves = ['completionProvider' => ['resolveProvider' => true]];
                $client = new FakeLspClient([] === $built && null !== $firstCapabilities ? $firstCapabilities : $resolves);
                $built[] = $client;

                return $client;
            },
        );
        $pool->configure(
            [
                'php' => [
                    ['command' => ['php-ls'], 'name' => 'php-ls'],
                    ['command' => ['sf-ls'], 'name' => 'sf-ls'],
                ],
            ],
            static function (string $line): void {},
            static function (array $params): void {},
            static fn (array $edit): bool => false,
        );
        $controller = new CompletionController($pool, $this->editor, $this->tui, new Notifier(new StatusBarWidget(), $this->tui), new Translator('en'));
        $this->editor->showDocument('/p/a.php', new Document("<?php\n\$a->fo\n"));
        $this->activeFile->set('/p/a.php');
        $pool->notifyDidOpen('/p/a.php', "<?php\n", 'php', 1);

        return [$controller, $built];
    }

    private function readyOn(CompletionController $controller, string $path, string $text): void
    {
        $this->editor->showDocument($path, new Document($text));
        $this->activeFile->set($path);
        Probe::get($controller, 'lsp')->notifyDidOpen($path, $text, 'php', 1);
    }

    public function testRequestInlayHintsCoversASmallDocumentEntirelyAndAppliesTheResult(): void
    {
        // A document inside the viewport margin: the ranged request degrades
        // to the whole document, exactly as before the range existed.
        $controller = $this->controller();
        $this->readyOn($controller, '/p/a.php', "<?php\n\$a=1;\n\$b=2;\n"); // 4 lines (trailing blank line)

        $controller->requestInlayHints();

        $this->assertCount(1, $this->client->requests);
        $this->assertSame('textDocument/inlayHint', $this->client->requests[0]['method']);
        $this->assertSame(
            [
                'textDocument' => ['uri' => 'file:///p/a.php'],
                'range' => [
                    'start' => ['line' => 0, 'character' => 0],
                    'end' => ['line' => 3, 'character' => 0],
                ],
            ],
            $this->client->requests[0]['params'],
        );

        $requested = $this->requestedRenderSince(function (): void {
            $this->client->requests[0]['respond']([
                ['position' => ['line' => 1, 'character' => 2], 'label' => ': int'],
            ]);
        });
        $this->assertTrue($requested, 'applying the hints requests a render');

        $screen = \implode("\n", \array_map(AnsiUtils::stripAnsiCodes(...), $this->editor->render(new RenderContext(80, 8))));
        $this->assertStringContainsString(': int', $screen);
    }

    public function testRequestInlayHintsWithNoFileOpenDoesNotCrash(): void
    {
        $controller = $this->controller(); // no showDocument() was ever called: getFilePath() is null

        $controller->requestInlayHints();

        $this->addToAssertionCount(1);
    }

    public function testRequestInlayHintsOnASingleLineDocumentClampsTheRangeToZero(): void
    {
        $controller = $this->controller();
        $this->readyOn($controller, '/p/a.php', '<?php');

        $controller->requestInlayHints();

        $this->assertSame(
            ['line' => 0, 'character' => 0],
            $this->client->requests[0]['params']['range']['end'],
            'a single-line document must not send a negative end line',
        );
    }

    public function testRequestInlayHintsIgnoresAResponseAfterTheFileSwitchedAway(): void
    {
        $controller = $this->controller();
        $this->readyOn($controller, '/p/a.php', "<?php\n\$a=1;\n");

        $controller->requestInlayHints();
        $respond = $this->client->requests[0]['respond'];

        // The user switched files before the response arrived.
        $this->editor->showDocument('/p/other.php', new Document("<?php\n"));
        $respond([['position' => ['line' => 1, 'character' => 0], 'label' => 'stale']]);

        $screen = \implode("\n", \array_map(AnsiUtils::stripAnsiCodes(...), $this->editor->render(new RenderContext(80, 8))));
        $this->assertStringNotContainsString('stale', $screen, 'a response for a file that is no longer open must be dropped');
    }

    public function testRequestInlayHintsIgnoresANonArrayResult(): void
    {
        $controller = $this->controller();
        $this->readyOn($controller, '/p/a.php', "<?php\n");

        $controller->requestInlayHints();
        $requested = $this->requestedRenderSince(function (): void {
            $this->client->requests[0]['respond'](null);
        });

        $this->assertFalse($requested, 'an error/null result must not repaint');
    }

    public function testRequestInlayHintsSkipsAServerWithoutTheProvider(): void
    {
        $controller = $this->controller(capabilities: []);
        $this->readyOn($controller, '/p/a.php', "<?php\n\$a=1;\n");

        $controller->requestInlayHints();

        $this->assertSame([], $this->client->requests, 'no inlayHintProvider advertised — no request, no error response per edit');
    }

    public function testHintsSetToNoneAreNotAskedFor(): void
    {
        $controller = $this->controller();
        $this->readyOn($controller, '/p/a.php', "<?php\n\$a=1;\n");
        $this->editor->display->setInlayHints(InlayHintMode::None);

        $controller->requestInlayHints();

        $this->assertSame([], $this->client->requests, 'a hint nobody will see is not a round trip worth making');
    }

    public function testSwitchingHintsOffDropsThemAndBackOnAsksAtOnce(): void
    {
        $controller = $this->controller();
        $this->readyOn($controller, '/p/a.php', "<?php\n\$a=1;\n");
        $this->editor->decorations->setInlayHints([1 => [new InlayHint(2, ': int')]]);

        $controller->applyInlayHintMode(InlayHintMode::None);

        $this->assertSame(InlayHintMode::None, $this->editor->display->inlayHints);
        $this->assertSame([], $this->editor->decorations->inlayHintsAt(1), 'held hints would go stale under edits nobody re-asks about');
        $this->assertSame([], $this->client->requests);

        $controller->applyInlayHintMode(InlayHintMode::Tooltip);

        $this->assertSame(InlayHintMode::Tooltip, $this->editor->display->inlayHints);
        $this->assertCount(1, $this->client->requests, 'a card needs the hints too, and waiting for the next edit is not live');
        $this->assertSame('textDocument/inlayHint', $this->client->requests[0]['method']);
    }

    public function testRequestInlayHintsClampsToTheViewportPlusMargin(): void
    {
        $controller = $this->controller();
        $this->readyOn($controller, '/p/a.php', \str_repeat("\$x = 1;\n", times: 2_000));
        $this->editor->scrollBy(600);

        $controller->requestInlayHints();

        $range = $this->client->requests[0]['params']['range'];
        $this->assertSame(100, $range['start']['line'], 'viewport top 600 minus the 500-line margin');
        $this->assertSame(1124, $range['end']['line'], 'viewport top + 24 default rows + the margin');
    }

    public function testASettledScrollOutsideTheHintedWindowRefetches(): void
    {
        $controller = $this->controller();
        $this->readyOn($controller, '/p/a.php', \str_repeat("\$x = 1;\n", times: 2_000));
        $controller->requestInlayHints(); // hinted window: lines 0..524

        $this->editor->scrollBy(600); // visible 600..624 — outside the window
        $controller->viewportScrolled(); // no scheduler: settles immediately

        $this->assertCount(2, $this->client->requests, 'the viewport left the window — hints re-fetch');
        $this->assertSame(100, $this->client->requests[1]['params']['range']['start']['line']);

        $controller->viewportScrolled(); // unchanged viewport: still covered
        $this->assertCount(2, $this->client->requests, 'a settle inside the fresh window must not re-fetch');
    }

    public function testViewportScrolledDebouncesThroughTheScheduler(): void
    {
        $scheduler = new FakeScheduler();
        $controller = $this->controller(scheduler: $scheduler);
        $this->readyOn($controller, '/p/a.php', \str_repeat("\$x = 1;\n", times: 2_000));
        $controller->requestInlayHints();

        $this->editor->scrollBy(600);
        $controller->viewportScrolled();
        $controller->viewportScrolled(); // a wheel flood re-arms, never stacks

        $this->assertCount(1, $this->client->requests, 'nothing re-fetches while the wheel is still moving');
        $this->assertCount(1, $scheduler->scheduled, 'one settle timer armed');
        $this->assertSame(['1'], $scheduler->cancelled, 'the first arm was replaced');

        $scheduler->fire();
        $this->assertCount(2, $this->client->requests, 'the settled viewport fetched its window');
    }

    public function testViewportScrolledWithNoFileOpenIsInert(): void
    {
        $controller = $this->controller();

        $controller->viewportScrolled();

        // @mago-expect lint:no-isset -- $client is an UNINITIALIZED typed property here; array_key_exists does not apply and reading it would throw
        $this->assertFalse(isset($this->client) && [] !== $this->client->requests, 'no file — nothing to fetch');
    }

    public function testRequestCompletionShowsMappedItems(): void
    {
        // The capability is not decoration: only servers that ADVERTISE
        // completion are asked, so that a second server on the language which
        // does not is not sent a request it can only answer with an error.
        $controller = $this->controller(['completionProvider' => ['resolveProvider' => true]]);
        $this->readyOn($controller, '/p/a.php', "<?php\n\$a->fo\n");

        $controller->requestCompletion(1, 6);
        $this->assertSame('textDocument/completion', $this->client->requests[0]['method']);
        $this->client->requests[0]['respond'](['items' => [['label' => 'foo'], ['label' => 'bar']]], error: null);

        $screen = \implode("\n", \array_map(AnsiUtils::stripAnsiCodes(...), $this->editor->render(new RenderContext(80, 8))));
        $this->assertStringContainsString('foo', $screen);
        $this->assertStringContainsString('bar', $screen);
    }

    /** Answered after a tab switch, completions are the other file's: no popup opens over this one. */
    public function testCompletionsAnsweredAfterATabSwitchAreDropped(): void
    {
        $controller = $this->controller(['completionProvider' => ['resolveProvider' => true]]);
        $this->readyOn($controller, '/p/a.php', "<?php\n\$a->fo\n");
        $controller->requestCompletion(1, 6);

        $this->editor->showDocument('/p/b.php', new Document("<?php\n"));
        $this->client->requests[0]['respond'](['items' => [['label' => 'foo']]], error: null);

        $this->assertFalse($this->editor->isCompletionOpen());
    }

    public function testRequestCompletionGuardsOnAnUnreadySession(): void
    {
        $controller = $this->controller(); // no file open: ready() is null

        $this->assertFalse($controller->requestCompletion(0, 0), 'no session to ask: says so, and does not throw');
    }

    /** Asked means a server was asked: none advertising completion is none asked. */
    public function testRequestCompletionReportsWhetherAnyServerWasAsked(): void
    {
        $controller = $this->controller(['completionProvider' => true]);
        $this->readyOn($controller, '/p/a.php', "<?php\n\$a->fo\n");
        $this->assertTrue($controller->requestCompletion(1, 6));

        $silent = $this->controller(['inlayHintProvider' => true]);
        $this->readyOn($silent, '/p/a.php', "<?php\n\$a->fo\n");
        $this->assertFalse($silent->requestCompletion(1, 6));
    }

    /**
     * An error is still an answer: a Ctrl+Space that said "Loading…" says
     * "No suggestions" — but an error after a list arrived leaves the list.
     */
    public function testAnErrorAnswersALoadingPopup(): void
    {
        $controller = $this->controller(['completionProvider' => true]);
        $this->readyOn($controller, '/p/a.php', "<?php\n\$a->fo\n");
        $this->editor->setCallbacks(new EditorCallbacks(completion: $controller));

        $this->editor->getDocument()->setCursor(1, 6);
        $this->editor->handleInput("\x00"); // ctrl+space
        $this->assertStringContainsString('Loading…', $this->screen());
        $this->client->requests[0]['respond'](null, error: ['code' => -32_603, 'message' => 'boom']);
        $this->assertStringContainsString('No suggestions', $this->screen());
    }

    /** Esc is spent on a note: it goes, and nothing else happens. */
    public function testEscapeDismissesTheNote(): void
    {
        $this->controller();
        $this->editor->openFile('/p/a.php', "<?php\n");
        $this->editor->setCallbacks(new EditorCallbacks());
        $this->editor->handleInput("\x00"); // ctrl+space, nobody to ask
        $this->assertStringContainsString('No language server', $this->screen());

        $this->editor->handleInput("\x1b");
        $this->assertStringNotContainsString('No language server', $this->screen());
    }

    public function testRequestSignatureHelpShowsTheActiveSignatureLabel(): void
    {
        $controller = $this->controller();
        $this->readyOn($controller, '/p/a.php', "<?php\nstrlen(\n");

        $controller->requestSignatureHelp(1, 7);
        $this->client->requests[0]['respond']([
            'signatures' => [
                ['label' => 'strlen(string $string): int'],
                ['label' => 'other(): void'],
            ],
            'activeSignature' => 0,
        ], error: null);

        $screen = \implode("\n", \array_map(AnsiUtils::stripAnsiCodes(...), $this->editor->render(new RenderContext(80, 8))));
        $this->assertStringContainsString('strlen(string $string): int  1/2', $screen, 'with the overload it is');
    }

    /**
     * Alt+↓/↑ walk the overloads the server sent, each with its OWN active
     * argument, without asking again; with one signature they are the
     * declaration keys as ever.
     */
    public function testAltArrowsWalkTheOverloads(): void
    {
        $controller = $this->controller();
        $this->readyOn($controller, '/p/a.php', "<?php\nf(1, \n");
        $controller->requestSignatureHelp(1, 5);
        $this->client->requests[0]['respond'](
            [
                'signatures' => [
                    ['label' => 'f(int $a): void', 'parameters' => [['label' => 'int $a']]],
                    ['label' => 'f(int $a, int $b): void', 'parameters' => [['label' => 'int $a'], ['label' => 'int $b']]],
                ],
                'activeSignature' => 5, // out of range: the first is shown
                'activeParameter' => 1,
            ],
            error: null,
        );
        $this->assertStringContainsString('f(int $a): void  1/2', $this->screen());

        $this->editor->handleInput("\x1b[1;3B"); // alt+down
        $this->assertStringContainsString('f(int $a, int $b): void  2/2', $this->screen());
        $this->assertStringContainsString("\x1b[1m\x1b[4mint \$b", \implode('', $this->editor->render(new RenderContext(100, 8))), 'its own active argument');
        $this->editor->handleInput("\x1b[1;3B");
        $this->assertStringContainsString('1/2', $this->screen(), 'wrapping');
        $this->editor->handleInput("\x1b[1;3A"); // alt+up
        $this->assertStringContainsString('2/2', $this->screen());
        $this->editor->handleInput("\x1b[1;3A");
        $this->assertStringContainsString('1/2', $this->screen());

        // A legacy terminal spells Alt+↑/↓ ESC p / ESC n, which only the
        // keymap's declaration keys name.
        $this->editor->setKeybindings(new Keybindings(['prev_member' => ['alt+up'], 'next_member' => ['alt+down']]));
        $this->editor->handleInput("\x1bn");
        $this->assertStringContainsString('2/2', $this->screen());
        $this->editor->handleInput("\x1bp");
        $this->assertStringContainsString('1/2', $this->screen());
    }

    public function testResolveCompletionAppliesDeferredAutoImportEdits(): void
    {
        $controller = $this->controller();
        $this->readyOn($controller, '/p/a.php', "<?php\n\$x = new Foo();\n");

        $controller->resolveCompletion(['label' => 'Foo']);
        $this->client->requests[0]['respond']([
            'additionalTextEdits' => [
                [
                    'range' => ['start' => ['line' => 0, 'character' => 0], 'end' => ['line' => 0, 'character' => 0]],
                    'newText' => "use App\\Foo;\n",
                ],
            ],
        ]);

        $this->assertStringContainsString('use App\\Foo;', $this->editor->getDocument()->getText());
        $this->assertSame('textDocument/didChange', \end($this->client->notifications)['method'] ?? null, 'the buffer resync notifies the server');
    }

    /** The import the resolve brings is the accept's: one Ctrl+Z takes back both — unless the user typed in between. */
    public function testADeferredImportUndoesWithTheAcceptItFinishes(): void
    {
        $controller = $this->controller();
        $this->readyOn($controller, '/p/a.php', "<?php\n\$x = new ;\n");
        $document = $this->editor->getDocument();
        $import = ['additionalTextEdits' => [['range' => ['start' => ['line' => 1, 'character' => 0], 'end' => ['line' => 1, 'character' => 0]], 'newText' => "use App\\Foo;\n"]]];

        $document->setCursor(1, 9);
        $document->insert('Foo'); // the accept
        $controller->resolveCompletion(['label' => 'Foo']);
        $this->client->requests[0]['respond']($import);
        $document->undo();
        $this->assertSame("<?php\n\$x = new ;\n", $document->getText(), 'the import and the accept, in one step');

        $document->setCursor(1, 9);
        $document->insert('Foo');
        $controller->resolveCompletion(['label' => 'Foo']);
        $document->insert('(');
        $this->client->requests[1]['respond']($import);
        $document->undo();
        $this->assertSame("<?php\n\$x = new Foo(;\n", $document->getText(), 'typed since: the import is a step of its own');
    }

    public function testResolveCompletionDetailAnswersWithTheServersType(): void
    {
        // phpactor's real answer, measured: its completion RESPONSE carries no
        // detail at all, and this is where the type for the popup's column
        // comes from.
        $controller = $this->controller(capabilities: ['completionProvider' => ['resolveProvider' => true]]);
        $this->readyOn($controller, '/p/a.php', "<?php\n\$t->\n");
        $seen = [];

        $controller->resolveCompletionDetail(['label' => 'greet'], static function (string $detail) use (&$seen): void {
            $seen[] = $detail;
        });
        $painted = $this->requestedRenderSince(function (): void {
            $this->client->requests[0]['respond'](['label' => 'greet', 'detail' => 'pub greet(string $who): string']);
        });

        $this->assertSame(['pub greet(string $who): string'], $seen);
        // The answer lands long after the frame that drew the popup; without a
        // redraw the column stays empty until something else repaints.
        $this->assertTrue($painted, 'a resolved type asks for a repaint');
    }

    public function testAResolveThatAnswersONLYADocblockStillAnswers(): void
    {
        // phpantom_lsp's shape: it sends the type with the completion and keeps
        // the docblock for the resolve. A guard that needed BOTH would drop the
        // half that only ever arrives here.
        $controller = $this->controller(capabilities: ['completionProvider' => ['resolveProvider' => true]]);
        $this->readyOn($controller, '/p/a.php', "<?php\n\$t->\n");
        $seen = [];

        $controller->resolveCompletionDetail(['label' => 'name'], static function (string $detail, string $documentation) use (&$seen): void {
            $seen[] = [$detail, $documentation];
        });
        $this->client->requests[0]['respond'](['label' => 'name', 'documentation' => ['kind' => 'markdown', 'value' => 'The name.']]);

        $this->assertSame([['', 'The name.']], $seen, 'no type, and the docblock still comes through');
    }

    public function testAResolveThatAnswersNEITHERHalfDoesNotCallBack(): void
    {
        $controller = $this->controller(capabilities: ['completionProvider' => ['resolveProvider' => true]]);
        $this->readyOn($controller, '/p/a.php', "<?php\n\$t->\n");
        $called = false;

        $controller->resolveCompletionDetail(['label' => 'greet'], static function (string $detail, string $documentation) use (&$called): void {
            $called = true;
        });
        $this->client->requests[0]['respond'](['label' => 'greet']);

        $this->assertFalse($called, 'an answer with nothing in it is not an answer');
    }

    public function testANONSTRINGTypeIsTreatedAsNoTypeRatherThanCoerced(): void
    {
        // Wire data: a server that sends `detail: 42` would otherwise put "42"
        // in the type column, which is worse than an empty one.
        $controller = $this->controller(capabilities: ['completionProvider' => ['resolveProvider' => true]]);
        $this->readyOn($controller, '/p/a.php', "<?php\n\$t->\n");
        $seen = [];

        $controller->resolveCompletionDetail(['label' => 'greet'], static function (string $detail, string $documentation) use (&$seen): void {
            $seen[] = [$detail, $documentation];
        });
        $this->client->requests[0]['respond'](['label' => 'greet', 'detail' => 42, 'documentation' => 'Greets.']);

        $this->assertSame([['', 'Greets.']], $seen);
    }

    public function testResolveCompletionDetailIsNotEvenSENTToAServerThatDoesNotResolve(): void
    {
        // One request per visible row per popup, every one of them answered
        // with an error. A server that can tell you nothing should cost
        // nothing.
        $controller = $this->controller(capabilities: ['completionProvider' => ['triggerCharacters' => ['>']]]);
        $this->readyOn($controller, '/p/a.php', "<?php\n\$t->\n");

        $controller->resolveCompletionDetail(['label' => 'greet'], static function (string $detail): never {
            self::fail('nothing should answer: '.$detail);
        });

        $this->assertSame([], $this->client->requests);
    }

    public function testAResolveThatComesBackWithNoTypeChangesNothing(): void
    {
        // The column shows what the server said, and "" is not something it
        // said — an empty detail must leave the row as it was rather than
        // overwrite a type the first response did give.
        $controller = $this->controller(capabilities: ['completionProvider' => ['resolveProvider' => true]]);
        $this->readyOn($controller, '/p/a.php', "<?php\n\$t->\n");
        $called = false;

        $controller->resolveCompletionDetail(['label' => 'greet'], static function (string $detail) use (&$called): void {
            $called = true;
        });
        $this->client->requests[0]['respond'](['label' => 'greet', 'detail' => '']);

        $this->assertFalse($called);
    }

    public function testResolveCompletionWithNoEditsIsANoOp(): void
    {
        $controller = $this->controller();
        $this->readyOn($controller, '/p/a.php', "<?php\n\$x = 1;\n");
        $textBefore = $this->editor->getDocument()->getText();

        $controller->resolveCompletion(['label' => 'nothing']);
        $this->client->requests[0]['respond'](['additionalTextEdits' => []]);

        $this->assertSame($textBefore, $this->editor->getDocument()->getText());
    }

    /** Clears the flag first: a caller checks whether ITS action requested a render, not a stale one. */
    private function requestedRenderSince(callable $action): bool
    {
        Probe::set($this->tui, 'renderRequested', false);
        $action();

        return (bool) Probe::get($this->tui, 'renderRequested');
    }

    /** A server that never claimed completion is not asked for it. */
    public function testAServerThatDoesNotAdvertiseCompletionIsNotAsked(): void
    {
        $controller = $this->controller(['inlayHintProvider' => true]);
        $this->readyOn($controller, '/p/a.php', "<?php\n\$a->fo\n");

        $controller->requestCompletion(1, 6);

        $this->assertSame([], \array_column($this->client->requests, 'method'));
    }

    /**
     * A late answer to a superseded request does not reinstate its list.
     *
     * Several servers answer one Ctrl+Space at their own speeds while the user
     * keeps typing. Without a generation, the slow answer to the request
     * before last would put a list of the wrong things back on screen.
     */
    public function testALateAnswerToASupersededRequestIsDropped(): void
    {
        $controller = $this->controller(['completionProvider' => true]);
        $this->readyOn($controller, '/p/a.php', "<?php\n\$a->fo\n");

        $controller->requestCompletion(1, 6);
        $stale = $this->client->requests[0]['respond'];
        $controller->requestCompletion(1, 7); // the user typed on
        $this->client->requests[1]['respond'](['items' => [['label' => 'fresh']]], error: null);

        $stale(['items' => [['label' => 'stale']]], error: null);

        $screen = \implode("\n", \array_map(AnsiUtils::stripAnsiCodes(...), $this->editor->render(new RenderContext(80, 8))));
        $this->assertStringContainsString('fresh', $screen);
        $this->assertStringNotContainsString('stale', $screen);
    }

    /**
     * Both servers' items end up in one popup, and the popup grows as they
     * answer rather than waiting for the slow one.
     *
     * A Symfony server contributing route names beside a PHP server's methods
     * is what a second server on one language is FOR.
     */
    public function testItemsFromEveryServerAreMergedIntoOnePopup(): void
    {
        [$controller, $clients] = $this->twoServerController();

        $controller->requestCompletion(1, 6);
        $clients[0]->requests[0]['respond'](['items' => [['label' => 'formatMessage']]], error: null);

        $this->assertStringContainsString('formatMessage', $this->screen(), 'the first answer shows without waiting for the second');

        $clients[1]->requests[0]['respond'](['items' => [['label' => 'app_home_route']]], error: null);

        $screen = $this->screen();
        $this->assertStringContainsString('formatMessage', $screen);
        $this->assertStringContainsString('app_home_route', $screen);
    }

    /** One server failing does not cost the other its items. */
    public function testAnErrorFromOneServerLeavesTheOthersItemsShowing(): void
    {
        [$controller, $clients] = $this->twoServerController();

        $controller->requestCompletion(1, 6);
        $clients[0]->requests[0]['respond'](['items' => [['label' => 'survivor']]], error: null);
        $clients[1]->requests[0]['respond'](null, error: ['code' => -32_603, 'message' => 'boom']);

        $this->assertStringContainsString('survivor', $this->screen());
    }

    /** The same label from two servers is one row, not two. */
    public function testTheSameLabelFromTwoServersIsOneRow(): void
    {
        [$controller, $clients] = $this->twoServerController();

        $controller->requestCompletion(1, 6);
        $clients[0]->requests[0]['respond'](['items' => [['label' => 'shared', 'detail' => 'from the first']]], error: null);
        $clients[1]->requests[0]['respond'](['items' => [['label' => 'shared', 'detail' => 'from the second']]], error: null);

        $screen = $this->screen();
        $this->assertSame(1, \substr_count($screen, needle: 'shared'));
        $this->assertStringContainsString('from the first', $screen, 'the first answer wins the row');
    }

    /**
     * Resolving an item goes back to the server that OFFERED it.
     *
     * Sending it anywhere else is at best an error response and at worst a
     * server acting on an id that means something different to it.
     */
    public function testResolveGoesBackToTheServerThatOfferedTheItem(): void
    {
        [$controller, $clients] = $this->twoServerController();

        $controller->requestCompletion(1, 6);
        $clients[0]->requests[0]['respond'](['items' => [['label' => 'mine']]], error: null);
        $clients[1]->requests[0]['respond'](['items' => [['label' => 'theirs']]], error: null);
        $theirs = $this->itemNamed('theirs');

        $controller->resolveCompletion($theirs->raw);

        $this->assertSame(['textDocument/completion'], \array_column($clients[0]->requests, 'method'), 'the other server was not asked');
        $this->assertSame(['textDocument/completion', 'completionItem/resolve'], \array_column($clients[1]->requests, 'method'));
        $sent = $clients[1]->requests[1]['params'];
        $this->assertSame(['label' => 'theirs'], $sent, 'the bookkeeping key does not travel back onto the wire');
    }

    /** The item's detail is resolved through its OWN server's capability. */
    public function testDetailResolutionAsksTheOfferingServersCapability(): void
    {
        [$controller, $clients] = $this->twoServerController(['completionProvider' => ['resolveProvider' => false]]);

        $controller->requestCompletion(1, 6);
        $clients[0]->requests[0]['respond'](['items' => [['label' => 'no-resolve']]], error: null);
        $clients[1]->requests[0]['respond'](['items' => [['label' => 'resolves']]], error: null);

        $controller->resolveCompletionDetail($this->itemNamed('no-resolve')->raw, static function (string $d, string $doc): void {});
        $this->assertSame(['textDocument/completion'], \array_column($clients[0]->requests, 'method'), 'it said it does not resolve, so it is not asked');

        $controller->resolveCompletionDetail($this->itemNamed('resolves')->raw, static function (string $d, string $doc): void {});
        $this->assertSame(['textDocument/completion', 'completionItem/resolve'], \array_column($clients[1]->requests, 'method'));
    }

    private function screen(): string
    {
        return \implode("\n", \array_map(AnsiUtils::stripAnsiCodes(...), $this->editor->render(new RenderContext(columns: 80, rows: 10))));
    }

    /** The shown completion item with $label, for the raw payload behind it. */
    private function itemNamed(string $label): CompletionItem
    {
        foreach (Probe::get(Probe::object($this->editor, 'completion'), 'items') as $item) {
            if ($label === $item->label) {
                return $item;
            }
        }

        self::fail("no completion item labelled '{$label}'");
    }
}
