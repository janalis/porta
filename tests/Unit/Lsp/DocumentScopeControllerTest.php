<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Lsp;

use PHPUnit\Framework\TestCase;
use Porta\Editor\Buffer;
use Porta\Editor\CodeScopeKind;
use Porta\Editor\Document;
use Porta\Event\ActiveBufferChanged;
use Porta\Event\BufferSaved;
use Porta\Event\LspStatusChanged;
use Porta\I18n\Translator;
use Porta\Lsp\DocumentScopeController;
use Porta\Lsp\DocumentScopeRefresher;
use Porta\Lsp\DocumentScopes;
use Porta\Lsp\LspSessionPool;
use Porta\Tests\Support\FakeLspClient;
use Porta\Tests\Support\FixedActiveFile;
use Porta\Tests\Support\Probe;
use Porta\Widget\CodeEditorWidget;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * Who gets asked for document symbols, and what the answer is allowed to do.
 */
final class DocumentScopeControllerTest extends TestCase
{
    private CodeEditorWidget $editor;
    private FakeLspClient $client;
    private FixedActiveFile $activeFile;
    private DocumentScopes $scopes;

    /** @param array<string, mixed> $capabilities */
    private function controller(array $capabilities = ['documentSymbolProvider' => true]): DocumentScopeController
    {
        $this->editor = new CodeEditorWidget();
        $this->scopes = new DocumentScopes();
        $tui = new Tui(terminal: new VirtualTerminal(columns: 100, rows: 20));
        $pool = new LspSessionPool(
            '/p',
            $this->activeFile = new FixedActiveFile(),
            new Translator('en'),
            fn (array $command, string $rootDir, \Closure $onLog): FakeLspClient => $this->client = new FakeLspClient($capabilities),
        );
        $pool->configure(
            ['*' => [['command' => ['ls'], 'name' => 'ls']]],
            static function (string $line): void {},
            static function (array $params): void {},
            static fn (array $edit): bool => false,
        );

        return new DocumentScopeController($pool, $this->editor, $this->scopes, $tui);
    }

    private function readyOn(DocumentScopeController $controller, string $path, string $language = 'ts'): void
    {
        $this->editor->showDocument($path, new Document("const a = 1;\n"));
        $this->activeFile->set($path);
        Probe::get($controller, 'lsp')->notifyDidOpen($path, "const a = 1;\n", $language, 1);
    }

    private static function symbol(string $name, int $kind, int $from, int $to): array
    {
        return ['name' => $name, 'kind' => $kind, 'range' => ['start' => ['line' => $from], 'end' => ['line' => $to]]];
    }

    public function testAPhpBufferIsNeverAskedBecauseItIsScannedOffline(): void
    {
        // The offline scanner is instant and right with no server running;
        // spending a round trip to reach the same trail is worse in every way.
        $controller = $this->controller();
        $this->readyOn($controller, '/p/a.php', 'php');
        $before = \count($this->client->requests);

        $controller->requestScopes();

        $this->assertCount($before, $this->client->requests);
    }

    public function testAServerWithoutTheCapabilityIsNeverAsked(): void
    {
        $controller = $this->controller(capabilities: []);
        $this->readyOn($controller, '/p/a.ts');
        $before = \count($this->client->requests);

        $controller->requestScopes();

        $this->assertCount($before, $this->client->requests);
    }

    public function testAnAnswerBecomesTheFilesScopes(): void
    {
        $controller = $this->controller();
        $this->readyOn($controller, '/p/a.ts');

        $controller->requestScopes();
        \end($this->client->requests)['respond']([self::symbol('Svc', 5, 0, 9)]);

        $this->assertSame(
            [['label' => 'Svc', 'startLine' => 1, 'endLine' => 10, 'kind' => CodeScopeKind::Type]],
            $this->scopes->forPath('/p/a.ts'),
        );
    }

    public function testScopesAreNotServedToADIFFERENTFile(): void
    {
        // A trail naming another file's classes is worse than no trail.
        $controller = $this->controller();
        $this->readyOn($controller, '/p/a.ts');
        $controller->requestScopes();
        \end($this->client->requests)['respond']([self::symbol('Svc', 5, 0, 9)]);

        $this->assertSame([], $this->scopes->forPath('/p/other.ts'));
        $this->assertSame([], $this->scopes->forPath(null));
    }

    public function testAnAnswerArrivingAfterABufferSwitchIsDropped(): void
    {
        $controller = $this->controller();
        $this->readyOn($controller, '/p/a.ts');
        $controller->requestScopes();

        $this->editor->showDocument('/p/other.ts', new Document("x\n"));
        \end($this->client->requests)['respond']([self::symbol('Svc', 5, 0, 9)]);

        $this->assertSame([], $this->scopes->forPath('/p/a.ts'));
    }

    public function testTheRefresherAsksOnAllTHREESettlePoints(): void
    {
        // The third is a server becoming ready: the file you launch with is
        // opened before any session exists and would otherwise never get one.
        $controller = $this->controller();
        $this->readyOn($controller, '/p/a.ts');
        $refresher = new DocumentScopeRefresher($controller);
        $before = \count($this->client->requests);

        $refresher->onActiveBufferChanged(new ActiveBufferChanged('/p/a.ts'));
        $refresher->onBufferSaved(new BufferSaved(new Buffer('/p/a.ts', new Document("x\n"))));
        $refresher->onLspStatusChanged(new LspStatusChanged());

        $this->assertCount($before + 3, $this->client->requests);
    }
}
