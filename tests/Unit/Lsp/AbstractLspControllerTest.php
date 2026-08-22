<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Lsp;

use PHPUnit\Framework\TestCase;
use Porta\Editor\Document;
use Porta\I18n\Translator;
use Porta\Lsp\AbstractLspController;
use Porta\Lsp\FlushMode;
use Porta\Lsp\LspSessionPool;
use Porta\Tests\Support\FakeLspClient;
use Porta\Tests\Support\FixedActiveFile;
use Porta\Tests\Support\StatusReads;
use Porta\Tests\Support\TestTui;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\StatusBarWidget;
use Porta\Workbench\Notifier;

final class AbstractLspControllerTest extends TestCase
{
    use StatusReads;

    private FakeLspClient $client;
    private LspSessionPool $pool;
    private CodeEditorWidget $editor;
    private StatusBarWidget $statusBar;

    /**
     * A concrete subclass calling its parent's protected members normally
     * (not via reflection): if any of them slipped to private, this class
     * would fail to even declare its wrapper methods — a real inheritance
     * break, not just a behavioral one.
     */
    private function controller(): object
    {
        $this->client = new FakeLspClient();
        $this->editor = new CodeEditorWidget();
        $this->pool = new LspSessionPool(
            '/tmp/proj',
            new FixedActiveFile('/tmp/proj/a.php'),
            new Translator('en'),
            fn (array $command, string $rootDir, \Closure $onLog): FakeLspClient => $this->client,
        );
        $this->pool->configure(
            ['php' => ['command' => ['php-ls'], 'name' => 'php-ls']],
            static function (string $line): void {},
            static function (array $params): void {},
            static fn (array $edit): bool => false,
        );
        $tui = TestTui::create();
        $this->statusBar = new StatusBarWidget();

        return new class($this->pool, $this->editor, $tui, new Notifier($this->statusBar, $tui), new Translator('en')) extends AbstractLspController {
            public function transPublic(string $id, array $params = []): string
            {
                return $this->trans($id, $params);
            }

            public function readyPublic(FlushMode $flush = FlushMode::Flush): ?string
            {
                return $this->ready($flush);
            }

            public function uriPublic(string $path): string
            {
                return $this->uri($path);
            }

            public function flashPublic(string $message): void
            {
                $this->flash($message);
            }
        };
    }

    public function testTransDelegatesToTheTranslator(): void
    {
        $this->assertSame('debug', $this->controller()->transPublic('debug'));
    }

    public function testReadyDelegatesToTheSessionPoolForTheActiveFile(): void
    {
        $controller = $this->controller();
        $this->assertNull($controller->readyPublic(), 'no file open in the editor at all');

        $doc = new Document("<?php\n");
        $this->editor->showDocument('/tmp/proj/a.php', $doc);
        $this->assertNull($controller->readyPublic(), 'the editor has the file, but no server session for it yet');

        $this->pool->notifyDidOpen('/tmp/proj/a.php', $doc->getText(), 'php', 1);
        $this->assertSame('/tmp/proj/a.php', $controller->readyPublic(), 'now a session exists and is ready');
    }

    public function testUriDelegatesToTheUriConverter(): void
    {
        $this->assertSame('file:///tmp/proj/a.php', $this->controller()->uriPublic('/tmp/proj/a.php'));
    }

    public function testFlashNotifiesTheStatusBar(): void
    {
        $this->controller()->flashPublic('hello there');

        $this->assertSame('hello there', $this->flashText($this->statusBar));
    }
}
