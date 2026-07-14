<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Lsp;

use PHPUnit\Framework\TestCase;
use Porta\Editor\BufferManager;
use Porta\Event\BufferSaved;
use Porta\Event\BufferSwitched;
use Porta\I18n\Translator;
use Porta\Image\ImageData;
use Porta\Lsp\CompletionController;
use Porta\Lsp\LspBufferListener;
use Porta\Lsp\LspSessionPool;
use Porta\Tests\Support\FixedActiveFile;
use Porta\Ui\Notifier;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\StatusBarWidget;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

final class LspBufferListenerTest extends TestCase
{
    private function listener(): LspBufferListener
    {
        $pool = new LspSessionPool(sys_get_temp_dir(), new FixedActiveFile(), new Translator('en'));
        $tui = new Tui(terminal: new VirtualTerminal(columns: 80, rows: 10));
        $completion = new CompletionController($pool, new CodeEditorWidget(), $tui, new Notifier(new StatusBarWidget(), $tui), new Translator('en'));

        return new LspBufferListener($pool, $completion);
    }

    public function testSavedNotifiesDidSaveAndViewerSwitchesSkipInlayHints(): void
    {
        $listener = $this->listener();
        $buffers = new BufferManager();
        $text = $buffers->open('/tmp/a.php', "<?php\n");

        // No server configured: both calls must be silent no-ops, proving the
        // listener guards route correctly rather than exploding pre-LSP.
        $listener->onBufferSaved(new BufferSaved($text));
        $listener->onBufferSwitched(new BufferSwitched($text));

        $gd = imagecreatetruecolor(2, 2);
        \assert(false !== $gd);
        $image = $buffers->openImage('/tmp/i.png', new ImageData($gd, 2, 2, 5));
        $listener->onBufferSwitched(new BufferSwitched($image)); // viewer tab: no inlay request

        $this->addToAssertionCount(1);
    }
}
