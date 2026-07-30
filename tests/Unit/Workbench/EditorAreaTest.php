<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Workbench;

use PHPUnit\Framework\TestCase;
use Porta\Editor\BufferManager;
use Porta\I18n\Translator;
use Porta\Image\ImageData;
use Porta\Tests\Support\FakeLayoutEngine;
use Porta\Tests\Support\FakeRasterizer;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\GraphViewerWidget;
use Porta\Widget\HelpViewerWidget;
use Porta\Widget\ImageViewerWidget;
use Porta\Workbench\EditorArea;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

final class EditorAreaTest extends TestCase
{
    private BufferManager $buffers;

    private CodeEditorWidget $editor;

    private ImageViewerWidget $imageViewer;

    private HelpViewerWidget $helpViewer;

    private GraphViewerWidget $graphViewer;

    private Tui $tui;

    private EditorArea $area;

    protected function setUp(): void
    {
        $this->buffers = new BufferManager();
        $this->editor = new CodeEditorWidget();
        $this->imageViewer = new ImageViewerWidget(new FakeRasterizer(), new Translator('en'));
        $this->helpViewer = new HelpViewerWidget();
        $this->graphViewer = new GraphViewerWidget(new FakeLayoutEngine(), new Translator('en'));
        $this->tui = new Tui(terminal: new VirtualTerminal(columns: 40, rows: 10));
        $this->area = new EditorArea($this->buffers, $this->editor, $this->imageViewer, $this->helpViewer, $this->graphViewer, $this->tui);
    }

    public function testTheEditorIsTheContentWithoutBuffersAndForTextBuffers(): void
    {
        $this->assertSame($this->editor, $this->area->widget(), 'empty workspace: the editor');

        $this->buffers->open('/tmp/a.php', "<?php\n");
        $this->assertSame($this->editor, $this->area->widget(), 'text buffer: the editor');
    }

    public function testViewerTabsResolveToTheirViewer(): void
    {
        $gd = imagecreatetruecolor(2, height: 2);
        \assert(false !== $gd, description: 'the truecolor image handle was created');
        $this->buffers->openPane('/tmp/a.png', 'image', new ImageData($gd, 2, 2, 10));
        $this->assertSame($this->imageViewer, $this->area->widget(), 'image tab: the image viewer');

        $this->buffers->openPane('/tmp/help.md', 'help', '# Hi');
        $this->assertSame($this->helpViewer, $this->area->widget(), 'help tab: the help viewer');

        $this->buffers->openPane('/tmp/arch.dot', 'graph', 'digraph {}');
        $this->assertSame($this->graphViewer, $this->area->widget(), 'graph tab: the graph viewer');
    }

    public function testFocusFocusesTheCurrentContentWidget(): void
    {
        $this->tui->add($this->editor);
        $this->area->focus();

        $this->assertSame($this->editor, $this->tui->getFocus());
    }
}
