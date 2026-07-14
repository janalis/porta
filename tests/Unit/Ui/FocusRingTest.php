<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;
use Porta\Editor\BufferManager;
use Porta\Terminal\TerminalBuffer;
use Porta\Tests\Support\TempProject;
use Porta\Ui\EditorArea;
use Porta\Ui\FocusRing;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\DebugPanelWidget;
use Porta\Widget\FileTreeWidget;
use Porta\Widget\HelpViewerWidget;
use Porta\Widget\ImageViewerWidget;
use Porta\Widget\MenuBarWidget;
use Porta\Widget\ResultsPanelFields;
use Porta\Widget\ResultsPanelWidget;
use Porta\Widget\TerminalPanelWidget;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Style\StyleSheet;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

final class FocusRingTest extends TestCase
{
    private TempProject $project;
    private Tui $tui;
    private FileTreeWidget $tree;
    private CodeEditorWidget $editor;
    private TerminalPanelWidget $terminal;
    private ResultsPanelWidget $results;
    private DebugPanelWidget $debug;

    private function ring(): FocusRing
    {
        $this->project = new TempProject(['a.php' => "<?php\n"]);
        $this->tui = new Tui(terminal: new VirtualTerminal(columns: 100, rows: 24), keybindings: new Keybindings([]), renderer: new Renderer(new StyleSheet()));
        $this->tree = new FileTreeWidget($this->project->dir);
        $this->editor = new CodeEditorWidget();
        $this->terminal = new TerminalPanelWidget();
        $this->results = new ResultsPanelWidget();
        $this->debug = new DebugPanelWidget();

        // With no buffers open, EditorArea resolves to the editor — the same
        // widget the old closure returned.
        $editorArea = new EditorArea(new BufferManager(), $this->editor, new ImageViewerWidget(), new HelpViewerWidget(), $this->tui);

        return new FocusRing($this->tui, $this->tree, $this->terminal, $this->results, $this->debug, $editorArea);
    }

    public function testCyclesTreeAndEditorWhenNoPanelIsOpen(): void
    {
        $ring = $this->ring();
        $this->tui->setFocus($this->tree);

        $ring->cycle(1);
        $this->assertSame($this->editor, $this->tui->getFocus());

        $ring->cycle(1);
        $this->assertSame($this->tree, $this->tui->getFocus(), 'wraps back to the tree');

        $ring->cycle(-1);
        $this->assertSame($this->editor, $this->tui->getFocus(), 'reverse wraps to the last member');
    }

    public function testOpenPanelsJoinTheRingInOrder(): void
    {
        $ring = $this->ring();
        $this->terminal->open('Terminal', new TerminalBuffer());
        $this->results->open('Usages', ResultsPanelFields::resultsOnly());
        $this->tui->setFocus($this->editor);

        $ring->cycle(1);
        $this->assertSame($this->terminal, $this->tui->getFocus(), 'terminal follows the editor');

        $ring->cycle(1);
        $this->assertSame($this->results, $this->tui->getFocus(), 'results panel follows the terminal');

        $ring->cycle(1);
        $this->assertSame($this->tree, $this->tui->getFocus(), 'wraps around past the last open panel');
    }

    public function testClosedPanelsAreSkipped(): void
    {
        $ring = $this->ring();
        $this->debug->open('Debug');
        $this->tui->setFocus($this->editor);

        $ring->cycle(1);
        $this->assertSame($this->debug, $this->tui->getFocus(), 'closed terminal/results are skipped');
    }

    public function testFocusOutsideTheRingEntersAtTheEdge(): void
    {
        $ring = $this->ring();
        $menu = new MenuBarWidget();
        $this->tui->setFocus($menu);

        $ring->cycle(1);
        $this->assertSame($this->tree, $this->tui->getFocus(), 'forward enters at the first member');

        $this->tui->setFocus($menu);
        $ring->cycle(-1);
        $this->assertSame($this->editor, $this->tui->getFocus(), 'backward enters at the last member');
    }
}
