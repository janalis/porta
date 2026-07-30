<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Workbench;

use PHPUnit\Framework\TestCase;
use Porta\Editor\BufferManager;
use Porta\Editor\EditorPanes;
use Porta\I18n\Translator;
use Porta\Terminal\TerminalBuffer;
use Porta\Tests\Support\FakeLayoutEngine;
use Porta\Tests\Support\FakeRasterizer;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\TestBottomDock;
use Porta\Tests\Support\TestTui;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\DebugPanelWidget;
use Porta\Widget\FileTreeWidget;
use Porta\Widget\GraphViewerWidget;
use Porta\Widget\HelpViewerWidget;
use Porta\Widget\ImageViewerWidget;
use Porta\Widget\MenuBarWidget;
use Porta\Widget\ResultsPanelWidget;
use Porta\Widget\Support\ResultsPanelFields;
use Porta\Widget\TerminalPanelWidget;
use Porta\Workbench\EditorArea;
use Porta\Workbench\FocusRing;
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
        $this->tui = TestTui::create(100, 24);
        $this->tree = new FileTreeWidget($this->project->dir);
        $this->editor = new CodeEditorWidget();
        $this->terminal = new TerminalPanelWidget();
        $this->results = new ResultsPanelWidget();
        $this->debug = new DebugPanelWidget();

        // With no buffers open, EditorArea resolves to the editor — the same
        // widget the old closure returned.
        $editorArea = new EditorArea(
            new BufferManager(),
            $this->editor,
            new EditorPanes(),
            $this->tui,
        );
        $dock = TestBottomDock::create($this->tui, $this->terminal, $this->results, $this->debug, $editorArea);

        return new FocusRing($this->tui, $this->tree, $dock, $editorArea);
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

    public function testOnlyTheVisibleDockPanelJoinsTheRing(): void
    {
        $ring = $this->ring();
        $this->terminal->open('Terminal', new TerminalBuffer());
        $this->results->open('Usages', ResultsPanelFields::resultsOnly()); // conceals the terminal
        $this->tui->setFocus($this->editor);

        $ring->cycle(1);
        $this->assertSame($this->results, $this->tui->getFocus(), 'the visible panel follows the editor; the concealed terminal is skipped');

        $ring->cycle(1);
        $this->assertSame($this->tree, $this->tui->getFocus(), 'wraps around past the visible panel');
    }

    public function testClosedPanelsAreSkipped(): void
    {
        $ring = $this->ring();
        $this->debug->open('Debug');
        $this->tui->setFocus($this->editor);

        $ring->cycle(1);
        $this->assertSame($this->debug, $this->tui->getFocus(), 'closed terminal/results leave only the visible debug panel');
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
