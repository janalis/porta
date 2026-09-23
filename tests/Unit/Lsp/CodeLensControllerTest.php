<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Lsp;

use PHPUnit\Framework\TestCase;
use Porta\Editor\Buffer;
use Porta\Editor\Document;
use Porta\Event\ActiveBufferChanged;
use Porta\Event\BufferSaved;
use Porta\Event\LspStatusChanged;
use Porta\I18n\Translator;
use Porta\Lsp\CodeLensController;
use Porta\Lsp\CodeLensRefresher;
use Porta\Lsp\LspSessionPool;
use Porta\Tests\Support\FakeLspClient;
use Porta\Tests\Support\FixedActiveFile;
use Porta\Tests\Support\Probe;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\StatusBarWidget;
use Porta\Workbench\Notifier;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

final class CodeLensControllerTest extends TestCase
{
    private CodeEditorWidget $editor;
    private FakeLspClient $client;
    private FixedActiveFile $activeFile;
    private StatusBarWidget $status;

    /** @param array<string, mixed> $capabilities */
    private function controller(array $capabilities = ['codeLensProvider' => ['resolveProvider' => false]]): CodeLensController
    {
        $this->editor = new CodeEditorWidget();
        $tui = new Tui(terminal: new VirtualTerminal(columns: 100, rows: 20));
        $pool = new LspSessionPool(
            '/p',
            $this->activeFile = new FixedActiveFile(),
            new Translator('en'),
            fn (array $command, string $rootDir, \Closure $onLog): FakeLspClient => $this->client = new FakeLspClient($capabilities),
        );
        $pool->configure(
            ['php' => [['command' => ['php-ls'], 'name' => 'php-ls']]],
            static function (string $line): void {},
            static function (array $params): void {},
            static fn (array $edit): bool => false,
        );

        return new CodeLensController($pool, $this->editor, $this->editor->decorations, $tui, new Notifier($this->status = new StatusBarWidget(), $tui), new Translator('en'));
    }

    private function readyOn(CodeLensController $controller, string $path = '/p/a.php'): void
    {
        $this->editor->showDocument($path, new Document("<?php\nclass A {}\nfunction b() {}\n"));
        $this->activeFile->set($path);
        Probe::get($controller, 'lsp')->notifyDidOpen($path, "<?php\n", 'php', 1);
    }

    /** @param list<array<string, mixed>> $lenses */
    private function answer(array $lenses): void
    {
        \end($this->client->requests)['respond']($lenses);
    }

    private static function lens(int $line, string $title): array
    {
        return ['range' => ['start' => ['line' => $line, 'character' => 0]], 'command' => ['title' => $title, 'command' => 'lens.run', 'arguments' => [$line]]];
    }

    /** phpactor advertises no codeLensProvider; asking it earns an error per edit burst. */
    public function testAServerWithoutTheCapabilityIsNeverAsked(): void
    {
        $controller = $this->controller(capabilities: []);
        $this->readyOn($controller);

        $controller->requestCodeLenses();

        $this->assertSame([], $this->client->requests);
    }

    public function testLensesAreGroupedByTheLineTheySitAbove(): void
    {
        $controller = $this->controller();
        $this->readyOn($controller);

        $controller->requestCodeLenses();
        $this->answer([self::lens(1, '3 usages'), self::lens(2, '1 usage'), self::lens(1, '2 implementations')]);

        $store = $this->editor->decorations;
        $this->assertSame(['3 usages', '2 implementations'], \array_column($store->codeLensesAt(1), 'title'), 'server order is kept within a line');
        $this->assertSame(['1 usage'], \array_column($store->codeLensesAt(2), 'title'));
        $this->assertSame([], $store->codeLensesAt(0), 'a line with no lens has none');
    }

    /**
     * A lens with nothing to show, or nowhere to show it, is dropped rather
     * than rendered as an empty row above the code.
     */
    public function testUnusableLensesAreDroppedRatherThanShownBlank(): void
    {
        $controller = $this->controller();
        $this->readyOn($controller);

        $controller->requestCodeLenses();
        $this->answer([
            ['range' => ['start' => ['line' => 1]], 'command' => ['title' => '']],
            ['range' => ['start' => ['line' => 1]]],
            ['command' => ['title' => 'nowhere to go']],
            ['range' => ['start' => ['line' => -1]], 'command' => ['title' => 'before the file']],
            self::lens(1, 'the real one'),
        ]);

        $this->assertSame(['the real one'], \array_column($this->editor->decorations->codeLensesAt(1), 'title'));
    }

    /**
     * The answer is a round trip, so it can land after the user has moved on —
     * painting it then would label a different file's code.
     */
    public function testAnAnswerArrivingAfterABufferSwitchIsDropped(): void
    {
        $controller = $this->controller();
        $this->readyOn($controller);
        $controller->requestCodeLenses();

        $this->editor->showDocument('/p/other.php', new Document("<?php\n"));
        $this->answer([self::lens(1, '3 usages')]);

        $this->assertSame([], $this->editor->decorations->codeLensesAt(1));
    }

    /** A non-array answer (an error) leaves whatever was showing alone. */
    public function testAnErrorAnswerChangesNothing(): void
    {
        $controller = $this->controller();
        $this->readyOn($controller);
        $controller->requestCodeLenses();
        $this->answer([self::lens(1, 'first')]);

        $controller->requestCodeLenses();
        \end($this->client->requests)['respond'](null);

        $this->assertSame(['first'], \array_column($this->editor->decorations->codeLensesAt(1), 'title'));
    }

    /**
     * The refresher checks the toggle, not the controller — so turning code
     * vision off stops the REQUESTS rather than only the painting. A lens count
     * is a real round trip per save; paying for it invisibly would be worse
     * than not having the feature.
     */
    public function testTheRefresherAsksNothingWhileTheToggleIsOff(): void
    {
        $controller = $this->controller();
        $this->readyOn($controller);
        $display = $this->editor->display;

        $refresher = new CodeLensRefresher($controller, $display);
        $refresher->onBufferSaved(new BufferSaved(new Buffer('/p/a.php', new Document("<?php\n"))));

        $this->assertSame([], $this->client->requests, 'off by default, so nothing was asked');

        $display->setCodeLenses(true);
        $refresher->onBufferSaved(new BufferSaved(new Buffer('/p/a.php', new Document("<?php\n"))));

        $this->assertCount(1, $this->client->requests, 'and on, the save is the settle point');
        $this->assertSame('textDocument/codeLens', $this->client->requests[0]['method']);
    }

    /**
     * A server becoming ready is the third settle point. Without it the file
     * you launch with is opened before any session exists, so it asks for
     * lenses that cannot be answered and never asks again.
     */
    public function testAServerBecomingReadyRefreshesTheLaunchFile(): void
    {
        $controller = $this->controller();
        $this->readyOn($controller);
        $display = $this->editor->display;
        $display->setCodeLenses(true);

        $refresher = new CodeLensRefresher($controller, $display);
        $refresher->onLspStatusChanged(new LspStatusChanged());

        $this->assertCount(1, $this->client->requests);
        $this->assertSame('textDocument/codeLens', $this->client->requests[0]['method']);
    }

    /** A buffer switch is the other settle point, for the same reason. */
    public function testABufferSwitchAlsoRefreshesWhenOn(): void
    {
        $controller = $this->controller();
        $this->readyOn($controller);
        $this->editor->display->setCodeLenses(true);

        new CodeLensRefresher($controller, $this->editor->display)
            ->onActiveBufferChanged(new ActiveBufferChanged($this->editor->getFilePath() ?? ''));

        $this->assertCount(1, $this->client->requests);
    }

    /** Clicking a lens runs its command, and says which one when it comes back. */
    public function testRunningALensExecutesItsCommandAndReportsTheOutcome(): void
    {
        $controller = $this->controller();
        $this->readyOn($controller);
        $controller->requestCodeLenses();
        $this->answer([self::lens(1, '3 usages')]);

        $controller->runCodeLens(1, 0);

        $request = \end($this->client->requests);
        $this->assertSame('workspace/executeCommand', $request['method']);
        $this->assertSame('lens.run', $request['params']['command']);
        $this->assertSame([1], $request['params']['arguments'], 'the arguments the server sent with it');

        $request['respond'](null, error: null);
        $this->assertStringContainsString('Ran "3 usages"', $this->flashed());
    }

    /** A failure names the lens and the reason rather than going quiet. */
    public function testAFailedLensSaysWhichAndWhy(): void
    {
        $controller = $this->controller();
        $this->readyOn($controller);
        $controller->requestCodeLenses();
        $this->answer([self::lens(1, '3 usages')]);

        $controller->runCodeLens(1, 0);
        \end($this->client->requests)['respond'](null, error: ['code' => -32_603, 'message' => 'no handler']);

        $flashed = $this->flashed();
        $this->assertStringContainsString('3 usages', $flashed);
        $this->assertStringContainsString('no handler', $flashed);
    }

    /**
     * An informational lens carries no verb — "3 usages" reads the same either
     * way — so clicking it says so rather than failing silently.
     */
    public function testALensWithNoCommandSaysItHasNothingToRun(): void
    {
        $controller = $this->controller();
        $this->readyOn($controller);
        $controller->requestCodeLenses();
        $this->answer([['range' => ['start' => ['line' => 1]], 'command' => ['title' => 'just a count']]]);

        $controller->runCodeLens(1, 0);

        $this->assertStringContainsString('nothing to run', $this->flashed());
        $this->assertCount(1, $this->client->requests, 'and nothing was sent');
    }

    /** A stale index (the lenses changed under the click) runs nothing. */
    public function testAnIndexWithNoLensBehindItRunsNothing(): void
    {
        $controller = $this->controller();
        $this->readyOn($controller);

        $controller->runCodeLens(1, 3);

        $this->assertSame([], $this->client->requests);
    }

    private function flashed(): string
    {
        return AnsiUtils::stripAnsiCodes(\implode('', $this->status->render(new RenderContext(120, 1))));
    }
}
