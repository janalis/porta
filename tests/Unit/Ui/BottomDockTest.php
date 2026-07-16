<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;
use Porta\Editor\BufferManager;
use Porta\Terminal\TerminalBuffer;
use Porta\Tests\Support\TestBottomDock;
use Porta\Ui\BottomDock;
use Porta\Ui\EditorArea;
use Porta\Ui\ResultsPanelMode;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\DebugPanelWidget;
use Porta\Widget\HelpViewerWidget;
use Porta\Widget\ImageViewerWidget;
use Porta\Widget\ResultsPanelFields;
use Porta\Widget\ResultsPanelWidget;
use Porta\Widget\TerminalPanelWidget;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Style\StyleSheet;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

final class BottomDockTest extends TestCase
{
    private Tui $tui;
    private CodeEditorWidget $editor;
    private TerminalPanelWidget $terminal;
    private ResultsPanelWidget $results;
    private DebugPanelWidget $debug;

    private function dock(): BottomDock
    {
        $this->tui = new Tui(terminal: new VirtualTerminal(columns: 100, rows: 24), keybindings: new Keybindings([]), renderer: new Renderer(new StyleSheet()));
        $this->editor = new CodeEditorWidget();
        $this->terminal = new TerminalPanelWidget();
        $this->results = new ResultsPanelWidget();
        $this->debug = new DebugPanelWidget();

        return TestBottomDock::create(
            $this->tui,
            $this->terminal,
            $this->results,
            $this->debug,
            new EditorArea(new BufferManager(), $this->editor, new ImageViewerWidget(), new HelpViewerWidget(), $this->tui),
        );
    }

    public function testOpeningAPanelConcealsTheOthers(): void
    {
        $dock = $this->dock();
        $this->terminal->open('Terminal', new TerminalBuffer());
        $this->assertSame($this->terminal, $dock->visiblePanel());

        $this->results->open('Problems', ResultsPanelFields::resultsOnly());
        $this->assertSame($this->results, $dock->visiblePanel(), 'the newest panel takes the dock slot');
        $this->assertTrue($this->terminal->isOpen(), 'the concealed terminal stays alive');
        $this->assertFalse($this->terminal->isVisible(), 'but it no longer renders');

        $this->debug->open('a.php');
        $this->assertSame($this->debug, $dock->visiblePanel());
        $this->assertFalse($this->results->isVisible());
    }

    public function testClosingRevealsTheMostRecentlyVisibleAliveTab(): void
    {
        $dock = $this->dock();
        $this->terminal->open('Terminal', new TerminalBuffer());
        $this->results->open('Problems', ResultsPanelFields::resultsOnly());
        $this->debug->open('a.php');

        $this->debug->close();
        $this->assertSame($this->results, $dock->visiblePanel(), 'closing the visible panel restores the previous tab');

        $this->results->close();
        $this->assertSame($this->terminal, $dock->visiblePanel(), 'the restore walks the visibility order');

        $this->terminal->close();
        $this->assertNull($dock->visiblePanel(), 'no alive tabs, no dock');
    }

    public function testTheRestoreOrderSkipsTabsThatDiedWhileConcealed(): void
    {
        $dock = $this->dock();
        $this->terminal->open('Terminal', new TerminalBuffer());
        $this->results->open('Problems', ResultsPanelFields::resultsOnly());
        $this->debug->open('a.php');

        $this->results->close(); // dies while concealed — the visible debug panel stays
        $this->assertSame($this->debug, $dock->visiblePanel());

        $this->debug->close();
        $this->assertSame($this->terminal, $dock->visiblePanel(), 'the dead results tab is skipped');
    }

    public function testReopeningAConcealedPanelRevealsIt(): void
    {
        $dock = $this->dock();
        $this->terminal->open('Terminal', new TerminalBuffer());
        $this->results->open('Problems', ResultsPanelFields::resultsOnly());

        $this->terminal->open('Terminal', new TerminalBuffer());
        $this->assertSame($this->terminal, $dock->visiblePanel());
        $this->assertFalse($this->results->isVisible());
        $this->assertTrue($this->results->isOpen());
    }

    public function testClosingAFocusedPanelReturnsFocusToTheEditor(): void
    {
        $dock = $this->dock();
        $this->debug->open('a.php');
        $this->tui->setFocus($this->debug);

        $this->debug->close();
        $this->assertSame($this->editor, $this->tui->getFocus(), 'focus returns to the editor area');
        $this->assertNull($dock->visiblePanel());
    }

    public function testOpenQueryAndShowListDriveTheResultsPanelAndMode(): void
    {
        $dock = $this->dock();
        $dock->openQuery(ResultsPanelMode::Symbol, 'Go to Symbol');
        $this->assertSame(ResultsPanelMode::Symbol, $dock->mode());
        $this->assertSame($this->results, $dock->visiblePanel());
        $this->assertSame($this->results, $this->tui->getFocus());

        $dock->showList(ResultsPanelMode::Problems, 'Problems', [['path' => 'a.php', 'line' => 1, 'text' => 'boom']]);
        $this->assertSame(ResultsPanelMode::Problems, $dock->mode());

        $dock->setMode(ResultsPanelMode::Search);
        $this->assertSame(ResultsPanelMode::Search, $dock->mode());
    }

    public function testRevealConcealAndDismissEdges(): void
    {
        $this->dock();
        $this->terminal->reveal(); // closed: a no-op
        $this->assertFalse($this->terminal->isVisible());
        $this->terminal->open('Terminal', new TerminalBuffer());
        $this->terminal->reveal(); // already revealed: a no-op
        $this->assertTrue($this->terminal->isVisible());
        $this->results->open('Problems', ResultsPanelFields::resultsOnly());
        $this->terminal->conceal(); // already concealed: a no-op
        $this->assertFalse($this->terminal->isVisible());

        $this->debug->dismiss(); // closed: a no-op
        $this->assertFalse($this->debug->isOpen());
        $this->debug->open('a.php');
        $this->debug->dismiss(); // no hook wired: falls back to close()
        $this->assertFalse($this->debug->isOpen());

        $dismissed = false;
        $this->results->onDismiss(static function () use (&$dismissed): void {
            $dismissed = true;
        });
        $this->results->dismiss();
        $this->assertTrue($dismissed, 'a wired dismiss hook replaces close()');
        $this->assertTrue($this->results->isOpen(), 'the panel stays alive');
    }

    public function testSetPanelRowsDrivesTheSharedHeight(): void
    {
        $dock = $this->dock();
        $dock->setPanelRows(17);

        $this->assertSame(17, $this->terminal->visibleRows());
        $this->assertSame(17, $this->results->visibleRows());
        $this->assertSame(17, $this->debug->visibleRows());
        $this->assertSame($this->terminal, $dock->terminalPanel());
    }
}
