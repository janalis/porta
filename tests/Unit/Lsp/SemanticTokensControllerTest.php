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
use Porta\Lsp\LspSessionPool;
use Porta\Lsp\SemanticTokensController;
use Porta\Lsp\SemanticTokensRefresher;
use Porta\Tests\Support\FakeLspClient;
use Porta\Tests\Support\FixedActiveFile;
use Porta\Tests\Support\Probe;
use Porta\Widget\CodeEditorWidget;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * The request side: who is asked, who is not, and what a stale or malformed
 * answer is allowed to do to the buffer.
 */
final class SemanticTokensControllerTest extends TestCase
{
    private const array CAPABILITY = [
        'semanticTokensProvider' => [
            'legend' => ['tokenTypes' => ['method', 'property', 'variable'], 'tokenModifiers' => ['static', 'deprecated']],
            'full' => true,
        ],
    ];

    private CodeEditorWidget $editor;
    private Tui $tui;
    private FakeLspClient $client;
    private FixedActiveFile $activeFile;

    /** @param array<string, mixed> $capabilities */
    private function controller(array $capabilities = self::CAPABILITY): SemanticTokensController
    {
        $this->editor = new CodeEditorWidget();
        $tui = new Tui(terminal: new VirtualTerminal(columns: 100, rows: 20));
        $this->tui = $tui;
        $pool = new LspSessionPool(
            '/p',
            $this->activeFile = new FixedActiveFile(),
            new Translator('en'),
            fn (array $command, string $rootDir, \Closure $onLog): FakeLspClient => $this->client = new FakeLspClient($capabilities),
        );
        $pool->configure(
            ['php' => ['command' => ['php-ls'], 'name' => 'php-ls']],
            static function (string $line): void {},
            static function (array $params): void {},
            static fn (array $edit): bool => false,
        );

        return new SemanticTokensController($pool, $this->editor, $this->editor->decorations(), $tui);
    }

    private function readyOn(SemanticTokensController $controller, string $path = '/p/a.php'): void
    {
        $this->editor->showDocument($path, new Document("<?php\nclass A {}\nfunction b() {}\n"));
        $this->activeFile->set($path);
        Probe::get($controller, 'lsp')->notifyDidOpen($path, "<?php\n", 'php', 1);
    }

    /** phpactor advertises no semanticTokensProvider; asking it earns an error per save. */
    public function testAServerWithoutTheCapabilityIsNeverAsked(): void
    {
        $controller = $this->controller(capabilities: []);
        $this->readyOn($controller);
        $before = \count($this->client->requests);

        $controller->requestSemanticTokens();

        self::assertCount($before, $this->client->requests);
    }

    public function testAServerOfferingOnlyRangesIsNeverAskedForAFullPass(): void
    {
        $controller = $this->controller([
            'semanticTokensProvider' => [
                'legend' => ['tokenTypes' => ['method'], 'tokenModifiers' => []],
                'full' => false,
                'range' => true,
            ],
        ]);
        $this->readyOn($controller);
        $before = \count($this->client->requests);

        $controller->requestSemanticTokens();

        self::assertCount($before, $this->client->requests);
    }

    public function testAServerAdvertisingAnEmptyLegendIsNeverAsked(): void
    {
        // Without a legend the indices in the answer mean nothing, so the
        // request could only produce colours we invented.
        $controller = $this->controller(['semanticTokensProvider' => ['legend' => ['tokenTypes' => []], 'full' => true]]);
        $this->readyOn($controller);
        $before = \count($this->client->requests);

        $controller->requestSemanticTokens();

        self::assertCount($before, $this->client->requests);
    }

    public function testALegendThatIsNotEvenAListIsTreatedAsAbsent(): void
    {
        // Servers do send malformed capabilities; the gate must hold rather
        // than let a request through that could only be decoded by guessing.
        $controller = $this->controller(['semanticTokensProvider' => ['legend' => ['tokenTypes' => 'method'], 'full' => true]]);
        $this->readyOn($controller);
        $before = \count($this->client->requests);

        $controller->requestSemanticTokens();

        self::assertCount($before, $this->client->requests);
    }

    public function testAnAnswerWhoseDataIsNotAListPaintsNothing(): void
    {
        $controller = $this->controller();
        $this->readyOn($controller);
        $controller->requestSemanticTokens();

        end($this->client->requests)['respond'](['data' => 'not a list']);

        self::assertSame([], $this->editor->decorations()->semanticTokensAt(1));
    }

    public function testWithNoFileOpenNothingIsAsked(): void
    {
        // The other half of the gate: a capable server is still not asked about
        // a buffer that is not there.
        $controller = $this->controller();
        $this->readyOn($controller);
        $this->editor->showDocument('', new Document(''));
        $before = \count($this->client->requests);

        $controller->requestSemanticTokens();

        self::assertCount($before, $this->client->requests);
    }

    public function testAServerThatDoesNotMentionFullAtAllIsNeverAsked(): void
    {
        // Absent is not the same as false in the wire format, but it means the
        // same here: no whole-document pass on offer.
        $controller = $this->controller([
            'semanticTokensProvider' => [
                'legend' => ['tokenTypes' => ['method'], 'tokenModifiers' => []],
                'range' => true,
            ],
        ]);
        $this->readyOn($controller);
        $before = \count($this->client->requests);

        $controller->requestSemanticTokens();

        self::assertCount($before, $this->client->requests);
    }

    public function testAnAnswerAsksForAFrame(): void
    {
        // Tokens stored without a repaint are tokens nobody sees until the next
        // keystroke happens to trigger one.
        $controller = $this->controller();
        $this->readyOn($controller);
        $controller->requestSemanticTokens();
        Probe::set($this->tui, 'renderRequested', false);

        end($this->client->requests)['respond'](['data' => [1, 4, 5, 1, 0]]);

        self::assertTrue((bool) Probe::get($this->tui, 'renderRequested'));
    }

    public function testALegendWithAStrayNonStringIsRefusedWholeRatherThanShifted(): void
    {
        // A legend is positional. Dropping the bad entry would shift every name
        // after it, so a token naming index 2 would resolve to the wrong type —
        // worse than no colour, because it looks like an answer.
        $controller = $this->controller([
            'semanticTokensProvider' => [
                'legend' => ['tokenTypes' => ['method', 42, 'variable'], 'tokenModifiers' => []],
                'full' => true,
            ],
        ]);
        $this->readyOn($controller);
        $before = \count($this->client->requests);

        $controller->requestSemanticTokens();

        self::assertCount($before, $this->client->requests);
    }

    public function testAnAnswerIsDecodedAgainstTheServersOwnLegendAndStored(): void
    {
        $controller = $this->controller();
        $this->readyOn($controller);

        $controller->requestSemanticTokens();
        end($this->client->requests)['respond'](['data' => [1, 4, 5, 1, 2]]);

        // Type index 1 is 'property' in THIS legend, and bit 1 is 'deprecated'.
        self::assertSame(
            [['col' => 4, 'endCol' => 9, 'type' => 'property', 'modifiers' => ['deprecated']]],
            $this->editor->decorations()->semanticTokensAt(1),
        );
    }

    public function testTheRequestNamesTheFileBeingEdited(): void
    {
        $controller = $this->controller();
        $this->readyOn($controller);

        $controller->requestSemanticTokens();
        $request = end($this->client->requests);

        self::assertSame('textDocument/semanticTokens/full', $request['method']);
        self::assertSame('file:///p/a.php', $request['params']['textDocument']['uri']);
    }

    public function testAnAnswerArrivingAfterABufferSwitchIsDropped(): void
    {
        // It describes a file that is no longer on screen: painting it would
        // colour this buffer with another file's symbols.
        $controller = $this->controller();
        $this->readyOn($controller);
        $controller->requestSemanticTokens();

        $this->editor->showDocument('/p/other.php', new Document("<?php\n"));
        end($this->client->requests)['respond'](['data' => [1, 4, 5, 1, 0]]);

        self::assertSame([], $this->editor->decorations()->semanticTokensAt(1));
    }

    public function testAMalformedStreamIsRefusedWholeRatherThanShifted(): void
    {
        $controller = $this->controller();
        $this->readyOn($controller);
        $controller->requestSemanticTokens();

        end($this->client->requests)['respond'](['data' => [1, 4, 'five', 1, 0]]);

        self::assertSame([], $this->editor->decorations()->semanticTokensAt(1));
    }

    public function testANonArrayAnswerIsIgnored(): void
    {
        $controller = $this->controller();
        $this->readyOn($controller);
        $controller->requestSemanticTokens();

        end($this->client->requests)['respond'](null);

        self::assertSame([], $this->editor->decorations()->semanticTokensAt(1));
    }

    public function testAServerBecomingReadyRefreshesTheLaunchFile(): void
    {
        // The launch file is opened BEFORE any session exists, so its
        // ActiveBufferChanged finds no capability and asks nothing. Without
        // this the one buffer you actually start looking at is the one that
        // never gets coloured.
        $controller = $this->controller();
        $this->readyOn($controller);
        $refresher = new SemanticTokensRefresher($controller);
        $before = \count($this->client->requests);

        $refresher->onLspStatusChanged(new LspStatusChanged());

        self::assertCount($before + 1, $this->client->requests);
    }

    public function testTheRefresherAsksOnBufferChangeAndOnSave(): void
    {
        // The refresher is also what constructs the controller: nothing else
        // injects it, and a service nothing injects is never built.
        $controller = $this->controller();
        $this->readyOn($controller);
        $refresher = new SemanticTokensRefresher($controller);
        $before = \count($this->client->requests);

        $refresher->onActiveBufferChanged(new ActiveBufferChanged('/p/a.php'));
        $refresher->onBufferSaved(new BufferSaved(new Buffer('/p/a.php', new Document("<?php\n"))));

        self::assertCount($before + 2, $this->client->requests);
    }
}
