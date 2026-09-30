<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Workbench;

use PHPUnit\Framework\TestCase;
use Porta\Editor\BufferManager;
use Porta\Editor\EditorPanes;
use Porta\Editor\PreviewMode;
use Porta\ImageViewer\ImageData;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\TestPane;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\HelpViewerWidget;
use Porta\Workbench\EditorArea;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

final class EditorAreaTest extends TestCase
{
    private BufferManager $buffers;

    private CodeEditorWidget $editor;

    private TestPane $imageViewer;

    private HelpViewerWidget $helpViewer;

    private TestPane $graphViewer;

    private Tui $tui;

    private EditorArea $area;

    protected function setUp(): void
    {
        $this->buffers = new BufferManager();
        $this->editor = new CodeEditorWidget();
        $this->imageViewer = new TestPane('image');
        $this->helpViewer = new HelpViewerWidget();
        $this->graphViewer = new TestPane('graph');
        $this->tui = new Tui(terminal: new VirtualTerminal(columns: 40, rows: 10));
        $panes = new EditorPanes();
        foreach ([$this->imageViewer, $this->helpViewer, $this->graphViewer] as $pane) {
            $panes->register($pane, static fn (string $path): string => '');
        }
        $this->area = new EditorArea($this->buffers, $this->editor, $panes, $this->tui);
    }

    public function testTheEditorIsTheContentWithoutBuffersAndForTextBuffers(): void
    {
        $this->assertSame($this->editor, $this->area->widget(), 'empty workspace: the editor');

        $this->buffers->open('/tmp/a.php', "<?php\n");
        $this->assertSame($this->editor, $this->area->widget(), 'text buffer: the editor');
    }

    public function testViewerTabsResolveToTheirViewer(): void
    {
        $gd = \imagecreatetruecolor(2, height: 2);
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

    public function testFocusMovesFocusAwayFromWhateverElseWasFocused(): void
    {
        // Tui::add() auto-focuses the first widget added, so a lone
        // $this->editor add already leaves it focused without focus()'s own
        // setFocus() call doing anything — start focused elsewhere instead,
        // so only that explicit call can move it back.
        $other = new CodeEditorWidget();
        $this->tui->add($other);
        $this->tui->add($this->editor);
        $this->tui->setFocus($other);

        $this->area->focus();

        $this->assertSame($this->editor, $this->tui->getFocus());
    }

    public function testFocusRequestsARender(): void
    {
        $this->tui->add($this->editor);
        Probe::set($this->tui, 'renderRequested', false);

        $this->area->focus();

        $this->assertTrue((bool) Probe::get($this->tui, 'renderRequested'));
    }

    /** A markdown text buffer previewed by the graph test pane, in $mode. */
    private function previewed(PreviewMode $mode): void
    {
        $buffer = $this->buffers->open('/tmp/README.md', '# hi');
        $buffer->previewId = 'graph';
        $buffer->previewMode = $mode;
    }

    public function testASplitAnswersWithTheHalfThatHasTheKeysAndEntersAtTheText(): void
    {
        $this->previewed(PreviewMode::Split);
        $this->tui->add($this->editor);
        $this->tui->add($this->graphViewer);

        $this->tui->setFocus($this->editor);
        $this->assertSame($this->editor, $this->area->widget());
        $this->tui->setFocus($this->graphViewer);
        $this->assertSame($this->graphViewer, $this->area->widget(), 'the preview has the keys: it is the area\'s content');
        $this->assertSame($this->editor, $this->area->entry(), 'but entering the area anew lands on the text');

        $this->assertSame($this->graphViewer, $this->area->visiblePane(), 'the pointer can reach the preview whoever has the keys');
        $this->assertSame([$this->editor, $this->graphViewer], $this->area->members(), 'two stops on the focus ring');
    }

    public function testALonePreviewIsTheWholeArea(): void
    {
        $this->previewed(PreviewMode::Preview);

        $this->assertSame($this->graphViewer, $this->area->widget());
        $this->assertSame($this->graphViewer, $this->area->entry());
        $this->assertSame($this->graphViewer, $this->area->visiblePane());
        $this->assertSame([$this->graphViewer], $this->area->members());
    }

    public function testTheTextAloneHasNoPaneToReach(): void
    {
        $this->previewed(PreviewMode::Editor);

        $this->assertSame($this->editor, $this->area->widget());
        $this->assertNull($this->area->visiblePane());
        $this->assertSame([$this->editor], $this->area->members());
    }

    public function testAViewerTabIsReachedWhateverHasTheKeys(): void
    {
        $this->buffers->openPane('/tmp/arch.dot', 'graph', 'digraph {}');

        $this->assertSame($this->graphViewer, $this->area->entry());
        $this->assertSame($this->graphViewer, $this->area->visiblePane());
        $this->assertSame([$this->graphViewer], $this->area->members());
    }
}
