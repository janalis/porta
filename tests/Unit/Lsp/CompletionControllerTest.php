<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Lsp;

use PHPUnit\Framework\TestCase;
use Porta\Editor\Document;
use Porta\I18n\Translator;
use Porta\Lsp\CompletionController;
use Porta\Lsp\LspSessionPool;
use Porta\Support\Scheduler;
use Porta\Tests\Support\FakeLspClient;
use Porta\Tests\Support\FakeScheduler;
use Porta\Tests\Support\FixedActiveFile;
use Porta\Tests\Support\Probe;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\StatusBarWidget;
use Porta\Workbench\Notifier;
use Symfony\Component\Tui\Ansi\AnsiUtils;
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
        $pool = new LspSessionPool(
            '/p',
            $this->activeFile = new FixedActiveFile(),
            $translator,
            fn (array $command, string $rootDir, \Closure $onLog): FakeLspClient => $this->client = new FakeLspClient($capabilities),
        );
        $pool->configure(
            ['php' => ['command' => ['php-ls'], 'name' => 'php-ls']],
            static function (string $line): void {},
            static function (array $params): void {},
            static fn (array $edit): bool => false,
        );

        return new CompletionController($pool, $this->editor, $this->tui, $notifier, $translator, $scheduler);
    }

    /** Opens $path in the editor and the fake LSP session, ready to answer requests. */
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

        $screen = implode("\n", array_map(AnsiUtils::stripAnsiCodes(...), $this->editor->render(new RenderContext(80, 8))));
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

        $screen = implode("\n", array_map(AnsiUtils::stripAnsiCodes(...), $this->editor->render(new RenderContext(80, 8))));
        $this->assertStringNotContainsString('stale', $screen, 'a response for a file that is no longer open must be dropped');
    }

    public function testRequestInlayHintsIgnoresANonArrayResult(): void
    {
        $controller = $this->controller();
        $this->readyOn($controller, '/p/a.php', "<?php\n");

        $controller->requestInlayHints();
        $requested = $this->requestedRenderSince(function () use ($controller): void {
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

    public function testRequestInlayHintsClampsToTheViewportPlusMargin(): void
    {
        $controller = $this->controller();
        $this->readyOn($controller, '/p/a.php', str_repeat("\$x = 1;\n", times: 2_000));
        $this->editor->scrollBy(600);

        $controller->requestInlayHints();

        $range = $this->client->requests[0]['params']['range'];
        $this->assertSame(100, $range['start']['line'], 'viewport top 600 minus the 500-line margin');
        $this->assertSame(1124, $range['end']['line'], 'viewport top + 24 default rows + the margin');
    }

    public function testASettledScrollOutsideTheHintedWindowRefetches(): void
    {
        $controller = $this->controller();
        $this->readyOn($controller, '/p/a.php', str_repeat("\$x = 1;\n", times: 2_000));
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
        $this->readyOn($controller, '/p/a.php', str_repeat("\$x = 1;\n", times: 2_000));
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
        $controller = $this->controller();
        $this->readyOn($controller, '/p/a.php', "<?php\n\$a->fo\n");

        $controller->requestCompletion(1, 6);
        $this->assertSame('textDocument/completion', $this->client->requests[0]['method']);
        $this->client->requests[0]['respond'](['items' => [['label' => 'foo'], ['label' => 'bar']]], error: null);

        $screen = implode("\n", array_map(AnsiUtils::stripAnsiCodes(...), $this->editor->render(new RenderContext(80, 8))));
        $this->assertStringContainsString('foo', $screen);
        $this->assertStringContainsString('bar', $screen);
    }

    public function testRequestCompletionGuardsOnAnUnreadySession(): void
    {
        $controller = $this->controller(); // no file open: ready() is null

        $controller->requestCompletion(0, 0); // must not throw — no session exists to request against

        $this->addToAssertionCount(1);
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

        $screen = implode("\n", array_map(AnsiUtils::stripAnsiCodes(...), $this->editor->render(new RenderContext(80, 8))));
        $this->assertStringContainsString('strlen(string $string): int', $screen);
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
        $this->assertSame('textDocument/didChange', end($this->client->notifications)['method'] ?? null, 'the buffer resync notifies the server');
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
}
