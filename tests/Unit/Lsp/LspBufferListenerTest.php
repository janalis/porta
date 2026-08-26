<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Lsp;

use PHPUnit\Framework\TestCase;
use Porta\Editor\BufferManager;
use Porta\Event\BufferSaved;
use Porta\Event\BufferSwitched;
use Porta\I18n\Translator;
use Porta\ImageViewer\ImageData;
use Porta\Lsp\CompletionController;
use Porta\Lsp\LspBufferListener;
use Porta\Lsp\LspSessionPool;
use Porta\Tests\Support\FakeLspClient;
use Porta\Tests\Support\FixedActiveFile;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\StatusBarWidget;
use Porta\Workbench\Notifier;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

final class LspBufferListenerTest extends TestCase
{
    private FakeLspClient $client;
    private FixedActiveFile $activeFile;
    private CodeEditorWidget $editor;

    /** @return array{0: LspBufferListener, 1: LspSessionPool} */
    private function listener(): array
    {
        $pool = new LspSessionPool(
            '/tmp/proj',
            $this->activeFile = new FixedActiveFile(),
            new Translator('en'),
            fn (array $command, string $rootDir, \Closure $onLog): FakeLspClient => $this->client = new FakeLspClient(['inlayHintProvider' => true]),
        );
        $pool->configure(
            ['php' => ['command' => ['php-ls'], 'name' => 'php-ls']],
            static function (string $line): void {},
            static function (array $params): void {},
            static fn (array $edit): bool => false,
        );
        $tui = new Tui(terminal: new VirtualTerminal(columns: 80, rows: 10));
        $this->editor = new CodeEditorWidget();
        $completion = new CompletionController($pool, $this->editor, $tui, new Notifier(new StatusBarWidget(), $tui), new Translator('en'));

        return [new LspBufferListener($pool, $completion), $pool];
    }

    public function testSavedNotifiesDidSave(): void
    {
        [$listener, $pool] = $this->listener();
        $buffers = new BufferManager();
        $text = $buffers->open('/p/a.php', "<?php\n");
        $pool->notifyDidOpen('/p/a.php', "<?php\n", 'php', 1);

        $listener->onBufferSaved(new BufferSaved($text));

        $this->assertSame(
            ['method' => 'textDocument/didSave', 'params' => ['textDocument' => ['uri' => 'file:///p/a.php']]],
            end($this->client->notifications),
        );
    }

    public function testSavedWithNoMatchingSessionIsSilent(): void
    {
        [$listener, $pool] = $this->listener();
        $buffers = new BufferManager();
        $text = $buffers->open('/p/a.php', "<?php\n"); // never opened with the LSP pool: no session exists

        $listener->onBufferSaved(new BufferSaved($text));

        $this->assertFalse($pool->isActive(), 'confirms no session was ever started, proving the call above was inert');
    }

    public function testNonViewerSwitchRequestsInlayHints(): void
    {
        [$listener, $pool] = $this->listener();
        $this->editor->showDocument('/p/a.php', new \Porta\Editor\Document("<?php\n\$x = 1;\n"));
        $this->activeFile->set('/p/a.php');
        $pool->notifyDidOpen('/p/a.php', "<?php\n\$x = 1;\n", 'php', 1);
        $buffers = new BufferManager();
        $text = $buffers->open('/p/a.php', "<?php\n\$x = 1;\n");

        $listener->onBufferSwitched(new BufferSwitched($text));

        $this->assertSame(['textDocument/inlayHint'], array_column($this->client->requests, 'method'), 'a plain text buffer requests inlay hints');
    }

    public function testViewerSwitchSkipsInlayHints(): void
    {
        [$listener, $pool] = $this->listener();
        $this->editor->showDocument('/p/a.php', new \Porta\Editor\Document("<?php\n"));
        $this->activeFile->set('/p/a.php');
        $pool->notifyDidOpen('/p/a.php', "<?php\n", 'php', 1);

        $gd = imagecreatetruecolor(2, height: 2);
        \assert(false !== $gd, description: 'the truecolor image was created');
        $buffers = new BufferManager();
        $image = $buffers->openPane('/p/i.png', 'image', new ImageData($gd, 2, 2, 5));

        $listener->onBufferSwitched(new BufferSwitched($image));

        $this->assertSame([], $this->client->requests, 'a viewer tab never requests inlay hints');
    }
}
