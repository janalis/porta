<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;
use Porta\Editor\BufferManager;
use Porta\Image\ImageData;
use Porta\Ui\EditorArea;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\HelpViewerWidget;
use Porta\Widget\ImageViewerWidget;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

final class EditorAreaTest extends TestCase
{
    private BufferManager $buffers;

    private CodeEditorWidget $editor;

    private ImageViewerWidget $imageViewer;

    private HelpViewerWidget $helpViewer;

    private Tui $tui;

    private EditorArea $area;

    protected function setUp(): void
    {
        $this->buffers = new BufferManager();
        $this->editor = new CodeEditorWidget();
        $this->imageViewer = new ImageViewerWidget();
        $this->helpViewer = new HelpViewerWidget();
        $this->tui = new Tui(terminal: new VirtualTerminal(columns: 40, rows: 10));
        $this->area = new EditorArea($this->buffers, $this->editor, $this->imageViewer, $this->helpViewer, $this->tui);
    }

    public function testTheEditorIsTheContentWithoutBuffersAndForTextBuffers(): void
    {
        $this->assertSame($this->editor, $this->area->widget(), 'empty workspace: the editor');

        $this->buffers->open('/tmp/a.php', "<?php\n");
        $this->assertSame($this->editor, $this->area->widget(), 'text buffer: the editor');
    }

    public function testViewerTabsResolveToTheirViewer(): void
    {
        $gd = imagecreatetruecolor(2, 2);
        \assert(false !== $gd);
        $this->buffers->openImage('/tmp/a.png', new ImageData($gd, 2, 2, 10));
        $this->assertSame($this->imageViewer, $this->area->widget(), 'image tab: the image viewer');

        $this->buffers->openHelp('/tmp/help.md', '# Hi');
        $this->assertSame($this->helpViewer, $this->area->widget(), 'help tab: the help viewer');
    }

    public function testFocusFocusesTheCurrentContentWidget(): void
    {
        $this->tui->add($this->editor);
        $this->area->focus();

        $this->assertSame($this->editor, $this->tui->getFocus());
    }
}
