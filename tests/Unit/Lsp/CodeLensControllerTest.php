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
use Porta\Tests\Support\FakeLspPool;
use Porta\Tests\Support\FixedActiveFile;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\StatusReads;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\StatusBarWidget;
use Porta\Workbench\Notifier;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

final class CodeLensControllerTest extends TestCase
{
    use StatusReads;

    private CodeEditorWidget $editor;
    private FakeLspClient $client;
    private FixedActiveFile $activeFile;
    private StatusBarWidget $status;

    /** @param array<string, mixed> $capabilities */
    private function controller(array $capabilities = ['codeLensProvider' => ['resolveProvider' => false]]): CodeLensController
    {
        $this->editor = new CodeEditorWidget();
        $tui = new Tui(terminal: new VirtualTerminal(columns: 100, rows: 20));
        $pool = FakeLspPool::create('/p', $this->activeFile = new FixedActiveFile(), fn (): FakeLspClient => $this->client = new FakeLspClient($capabilities));

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
        \end($this->client->requests)['respond']($lenses, error: null);
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
        \end($this->client->requests)['respond'](null, error: null);

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
        $this->assertStringContainsString('Ran "3 usages"', $this->statusRow($this->status, 120));
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

        $flashed = $this->statusRow($this->status, 120);
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

        $this->assertStringContainsString('nothing to run', $this->statusRow($this->status, 120));
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

    /**
     * A controller over TWO servers on php, each with its own fake client.
     *
     * @return array{0: CodeLensController, 1: list<FakeLspClient>}
     */
    private function twoServerController(): array
    {
        $this->editor = new CodeEditorWidget();
        $tui = new Tui(terminal: new VirtualTerminal(columns: 100, rows: 20));
        $built = [];
        $pool = new LspSessionPool(
            '/p',
            $this->activeFile = new FixedActiveFile(),
            new Translator('en'),
            static function (array $command, string $rootDir, \Closure $onLog) use (&$built): FakeLspClient {
                $client = new FakeLspClient(['codeLensProvider' => ['resolveProvider' => false]]);
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
        $controller = new CodeLensController($pool, $this->editor, $this->editor->decorations, $tui, new Notifier($this->status = new StatusBarWidget(), $tui), new Translator('en'));
        $this->editor->showDocument('/p/a.php', new Document("<?php\nclass A {}\nfunction b() {}\n"));
        $this->activeFile->set('/p/a.php');
        $pool->notifyDidOpen('/p/a.php', "<?php\n", 'php', 1);

        return [$controller, $built];
    }

    /**
     * Both servers' lenses sit above the same line.
     *
     * A Symfony server's route lens belongs above the same method a PHP
     * server counts usages on, and neither is the other's to replace.
     */
    public function testLensesFromEveryServerShareTheLine(): void
    {
        [$controller, $clients] = $this->twoServerController();

        $controller->requestCodeLenses();
        $clients[0]->requests[0]['respond']([self::lens(1, '3 usages')], error: null);
        $this->assertSame(['3 usages'], \array_column($this->editor->decorations->codeLensesAt(1), 'title'), 'the first shows without waiting');

        $clients[1]->requests[0]['respond']([self::lens(1, 'GET /home')], error: null);

        $this->assertSame(['3 usages', 'GET /home'], \array_column($this->editor->decorations->codeLensesAt(1), 'title'));
    }

    /**
     * The merged map stays in LINE order however the answers arrived.
     *
     * The store is walked by line to lay rows out above the code; a map whose
     * keys ran 7, 2 because the second server answered about an earlier line
     * would be a different walk for the same file.
     */
    public function testTheMergedLensesStayInLineOrder(): void
    {
        [$controller, $clients] = $this->twoServerController();

        $controller->requestCodeLenses();
        $clients[0]->requests[0]['respond']([self::lens(2, 'later line')], error: null);
        $clients[1]->requests[0]['respond']([self::lens(1, 'earlier line')], error: null);

        $this->assertSame([1, 2], \array_keys(Probe::get($this->editor->decorations, 'codeLenses')));
    }

    /**
     * Running a lens executes its command on the server that published it.
     *
     * A command id means nothing to another server, which would at best
     * answer "unknown command" and at worst run something of its own that
     * happens to share the name.
     */
    public function testRunningALensGoesBackToTheServerThatPublishedIt(): void
    {
        [$controller, $clients] = $this->twoServerController();
        $controller->requestCodeLenses();
        $clients[0]->requests[0]['respond']([self::lens(1, 'mine')], error: null);
        $clients[1]->requests[0]['respond']([self::lens(1, 'theirs')], error: null);

        $controller->runCodeLens(1, 1); // the second lens, from the second server

        $this->assertSame(['textDocument/codeLens'], \array_column($clients[0]->requests, 'method'), 'the other server was not asked');
        $this->assertSame(['textDocument/codeLens', 'workspace/executeCommand'], \array_column($clients[1]->requests, 'method'));
    }

    /** A server that never claimed lenses is not asked for them. */
    public function testOnlyServersThatAdvertiseLensesAreAsked(): void
    {
        [$controller, $clients] = $this->twoServerController();
        Probe::set($clients[1], 'capabilities', []);

        $controller->requestCodeLenses();

        $this->assertCount(1, $clients[0]->requests);
        $this->assertSame([], $clients[1]->requests);
    }
}
