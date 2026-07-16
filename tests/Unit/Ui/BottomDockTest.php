<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;
use Porta\Editor\BufferManager;
use Porta\Terminal\TerminalBuffer;
use Porta\Terminal\TerminalSessions;
use Porta\Tests\Support\TestBottomDock;
use Porta\Ui\BottomDock;
use Porta\Ui\DockTab;
use Porta\Ui\EditorArea;
use Porta\Ui\ResultsPanelMode;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\DebugPanelWidget;
use Porta\Widget\DockTabsWidget;
use Porta\Widget\HelpViewerWidget;
use Porta\Widget\ImageViewerWidget;
use Porta\Widget\ResultsPanelFields;
use Porta\Widget\ResultsPanelWidget;
use Porta\Widget\TerminalPanelWidget;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Render\RenderContext;
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
    private DockTabsWidget $strip;
    private TerminalSessions $sessions;

    private function dock(): BottomDock
    {
        $this->tui = new Tui(terminal: new VirtualTerminal(columns: 100, rows: 24), keybindings: new Keybindings([]), renderer: new Renderer(new StyleSheet()));
        $this->editor = new CodeEditorWidget();
        $this->terminal = new TerminalPanelWidget();
        $this->results = new ResultsPanelWidget();
        $this->debug = new DebugPanelWidget();
        $this->strip = new DockTabsWidget();
        $this->sessions = new TerminalSessions();

        return TestBottomDock::create(
            $this->tui,
            $this->terminal,
            $this->results,
            $this->debug,
            new EditorArea(new BufferManager(), $this->editor, new ImageViewerWidget(), new HelpViewerWidget(), $this->tui),
            $this->strip,
            $this->sessions,
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
        $this->debug->dismiss(); // wired: Esc hides the dock, the tab stays alive
        $this->assertTrue($this->debug->isOpen());
        $this->assertFalse($this->debug->isVisible());

        $lone = new DebugPanelWidget();
        $lone->open('b.php');
        $lone->dismiss(); // no hook wired (isolated widget): falls back to close()
        $this->assertFalse($lone->isOpen());
    }

    public function testEscOnAPickerClosesItForReal(): void
    {
        $dock = $this->dock();
        $this->terminal->open('Terminal', new TerminalBuffer());
        $dock->openQuery(ResultsPanelMode::Action, 'Find Action');

        $this->results->dismiss(); // Esc on the command palette
        $this->assertFalse($this->results->isOpen(), 'a picker closes instead of hiding');
        $this->assertSame($this->terminal, $dock->visiblePanel(), 'the previously visible tab comes back');
    }

    public function testAliveTabsListTheOpenToolsAndMarkTheVisibleOne(): void
    {
        $dock = $this->dock();
        $this->assertSame([], $dock->aliveTabs(), 'a hidden dock has no strip');
        $this->assertFalse($dock->stripVisible());

        $this->sessions->create('shell:1', 'sh 1');
        $this->terminal->open('sh 1', new TerminalBuffer());
        $this->results->open('Problems', ResultsPanelFields::resultsOnly());

        $tabs = $dock->aliveTabs();
        $this->assertTrue($dock->stripVisible());
        $this->assertSame([DockTab::Terminal, DockTab::Results], array_column($tabs, 'tab'));
        $this->assertSame(['Terminal', 'Problems'], array_column($tabs, 'label'));
        $this->assertSame([false, true], array_column($tabs, 'active'), 'the results tab owns the slot');
        $this->assertSame(['', '0 results'], array_column($tabs, 'status'), "each tool carries its status for the strip's right side");

        $dock->hide();
        $this->assertSame([], $dock->aliveTabs(), 'hiding the dock hides the strip too');
    }

    public function testAPickerBypassesTheStrip(): void
    {
        $dock = $this->dock();
        $this->sessions->create('shell:1', 'sh 1');
        $this->terminal->open('sh 1', new TerminalBuffer());
        $dock->openQuery(ResultsPanelMode::Symbol, 'Go to Symbol');

        $this->assertSame([], $dock->aliveTabs(), 'a transient picker never appears as a dock tab');
    }

    public function testRevealSwitchesTabsAndFocuses(): void
    {
        $dock = $this->dock();
        $this->results->open('Problems', ResultsPanelFields::resultsOnly());
        $this->debug->open('a.php');

        $dock->reveal(DockTab::Results);
        $this->assertSame($this->results, $dock->visiblePanel());
        $this->assertSame($this->results, $this->tui->getFocus());

        $dock->reveal(DockTab::Results); // already visible: a no-op
        $this->assertSame($this->results, $dock->visiblePanel());
        $dock->reveal(DockTab::Terminal); // no reveal hook wired here: a no-op
        $this->assertSame($this->results, $dock->visiblePanel());

        $revealed = false;
        $dock->onTerminalReveal(static function () use (&$revealed): void {
            $revealed = true;
        });
        $dock->reveal(DockTab::Terminal);
        $this->assertTrue($revealed, 'the Terminal tab reveals through its controller hook');

        $this->debug->close();
        $dock->reveal(DockTab::Debug); // dead tab: a no-op
        $this->assertSame($this->results, $dock->visiblePanel());
    }

    public function testCycleWalksTheAliveTabsInStripOrder(): void
    {
        $dock = $this->dock();
        $dock->cycle(1); // nothing alive: a no-op

        $this->results->open('Problems', ResultsPanelFields::resultsOnly());
        $dock->cycle(1); // a single tab: a no-op
        $this->assertSame($this->results, $dock->visiblePanel());

        $this->debug->open('a.php');
        $dock->cycle(1);
        $this->assertSame($this->results, $dock->visiblePanel(), 'debug → results, wrapping forward');
        $dock->cycle(-1);
        $this->assertSame($this->debug, $dock->visiblePanel(), 'and back');
    }

    public function testCloseTabClosesToolsButOnlyConcealsTheTerminal(): void
    {
        $dock = $this->dock();
        $this->sessions->create('shell:1', 'sh 1');
        $this->terminal->open('sh 1', new TerminalBuffer());
        $this->results->open('Problems', ResultsPanelFields::resultsOnly());

        $dock->closeTab(DockTab::Results);
        $this->assertFalse($this->results->isOpen(), 'the strip × really closes a tool');
        $this->assertSame($this->terminal, $dock->visiblePanel(), 'the previous tab comes back');

        $dock->closeTab(DockTab::Terminal);
        $this->assertTrue($this->terminal->isOpen(), 'terminal sessions keep running');
        $this->assertNull($dock->visiblePanel());
        $this->assertSame([], $dock->aliveTabs());
    }

    public function testStripClicksRevealAndCloseThroughTheWiring(): void
    {
        $dock = $this->dock();
        $this->sessions->create('shell:1', 'sh 1');
        $this->terminal->open('sh 1', new TerminalBuffer());
        $dock->onTerminalReveal(function (): void {
            $this->terminal->open('sh 1', new TerminalBuffer());
        });
        $this->debug->open('a.php');

        $this->strip->render(new RenderContext(100, 1));
        // ' Terminal × │ Debug × ': the first tab's label starts at column 1.
        $this->assertTrue($this->strip->handleTabClick(2), 'the label zone is a tab hit');
        $this->assertSame($this->terminal, $dock->visiblePanel(), 'clicking the Terminal tab revealed it');

        $this->strip->render(new RenderContext(100, 1));
        $this->assertFalse($this->strip->handleTabClick(90), 'the empty tail falls through to the resize grab');
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
