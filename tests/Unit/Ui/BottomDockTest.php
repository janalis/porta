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
use Porta\Ui\DockToolTabs;
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
    private DockToolTabs $tools;

    private function dock(): BottomDock
    {
        $this->tui = new Tui(terminal: new VirtualTerminal(columns: 100, rows: 24), keybindings: new Keybindings([]), renderer: new Renderer(new StyleSheet()));
        $this->editor = new CodeEditorWidget();
        $this->terminal = new TerminalPanelWidget();
        $this->results = new ResultsPanelWidget();
        $this->debug = new DebugPanelWidget();
        $this->strip = new DockTabsWidget();
        $this->sessions = new TerminalSessions();
        $this->tools = new DockToolTabs();

        return TestBottomDock::create(
            $this->tui,
            $this->terminal,
            $this->results,
            $this->debug,
            new EditorArea(new BufferManager(), $this->editor, new ImageViewerWidget(), new HelpViewerWidget(), $this->tui),
            $this->strip,
            $this->sessions,
            $this->tools,
        );
    }

    public function testOpeningAPanelConcealsTheOthers(): void
    {
        $dock = $this->dock();
        $this->terminal->open('Terminal', new TerminalBuffer());
        $this->assertSame($this->terminal, $dock->visiblePanel());

        $dock->showList(ResultsPanelMode::Problems, 'Problems', []);
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
        $dock->showList(ResultsPanelMode::Problems, 'Problems', []);
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
        $dock->showList(ResultsPanelMode::Problems, 'Problems', []);
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
        $dock->showList(ResultsPanelMode::Problems, 'Problems', []);

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

    public function testShowListDrivesTheResultsPanelAndMode(): void
    {
        $dock = $this->dock();
        $dock->showList(ResultsPanelMode::Problems, 'Problems', [['path' => 'a.php', 'line' => 1, 'text' => 'boom']]);
        $this->assertSame(ResultsPanelMode::Problems, $dock->mode());
        $this->assertSame($this->results, $dock->visiblePanel());

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

    public function testAliveTabsListTheOpenToolsAndMarkTheVisibleOne(): void
    {
        $dock = $this->dock();
        $this->assertSame([], $dock->aliveTabs(), 'a hidden dock has no strip');
        $this->assertFalse($dock->stripVisible());

        $this->sessions->create('shell:1', 'sh 1');
        $this->terminal->open('sh 1', new TerminalBuffer());
        $dock->showList(ResultsPanelMode::Problems, 'Problems', []);
        $dock->showList(ResultsPanelMode::Todo, 'TODO', [['path' => 'a', 'line' => 0, 'text' => 't']]);

        $tabs = $dock->aliveTabs();
        $this->assertTrue($dock->stripVisible());
        $this->assertSame([DockTab::Terminal, DockTab::Results, DockTab::Results], array_column($tabs, 'tab'));
        $this->assertSame(['sh 1', 'Problems', 'TODO'], array_column($tabs, 'label'), 'sessions and tool instances each keep their own strip tab');
        $this->assertSame([false, false, true], array_column($tabs, 'active'), 'the newest tool owns the slot');
        $this->assertSame(['shell:1', '1', '2'], array_column($tabs, 'key'), 'session tabs carry their session id, tool tabs their instance key');
        $this->assertSame(['', '', '1 results'], array_column($tabs, 'status'), 'only the displayed tool carries a status');

        $dock->hide();
        $this->assertSame([], $dock->aliveTabs(), 'hiding the dock hides the strip too');
    }

    public function testAStripDragReordersWithinItsOwnFamilyModel(): void
    {
        $dock = $this->dock();
        $this->sessions->create('shell:1', 'sh 1');
        $this->sessions->create('shell:2', 'sh 2');
        $dock->showList(ResultsPanelMode::Problems, 'Problems', []);
        $dock->showList(ResultsPanelMode::Todo, 'TODO', []);

        $this->strip->moveTab(DockTab::Results, 0, 1); // the wired widget callback lands in DockToolTabs::move
        $this->assertSame(['TODO', 'Problems'], array_map(static fn ($tab) => $tab->title, $this->tools->all()));
        $this->assertSame('TODO', $this->tools->active()?->title, 'the active pointer follows its tab');

        $this->strip->moveTab(DockTab::Terminal, 0, 1); // and the Terminal family lands in TerminalSessions::move
        $this->assertSame(['shell:2', 'shell:1'], array_map(static fn ($session) => $session->id, $this->sessions->all()));

        $this->strip->moveTab(DockTab::Debug, 0, 1); // a single fixed tab: nothing to reorder
        $this->assertSame(['TODO', 'Problems'], array_map(static fn ($tab) => $tab->title, $this->tools->all()));
    }

    public function testReinvokingAToolReusesItsTabAndRefreshes(): void
    {
        $dock = $this->dock();
        $first = $dock->showList(ResultsPanelMode::Problems, 'Problems', []);
        $dock->showList(ResultsPanelMode::Search, 'Usages', [['path' => 'a.php', 'line' => 0, 'text' => 'x']]);

        $again = $dock->showList(ResultsPanelMode::Problems, 'Problems', [['path' => 'b.php', 'line' => 1, 'text' => 'boom']]);
        $this->assertSame($first, $again, 'same (mode, title) reuses the tab');
        $this->assertSame(2, $this->tools->count(), 'no duplicate appended');
        $this->assertSame($first, $dock->activeTool(), 'the reused tab took the slot back');
        $this->assertSame([['path' => 'b.php', 'line' => 1, 'text' => 'boom']], $first->rows, 'and its content refreshed');

        $other = $dock->showList(ResultsPanelMode::Search, 'Usages: other', []);
        $this->assertSame(3, $this->tools->count(), 'a different title appends a new tab');
        $this->assertSame($other, $dock->activeTool());
    }

    public function testRevealSwitchesTabsAndFocuses(): void
    {
        $dock = $this->dock();
        $problems = $dock->showList(ResultsPanelMode::Problems, 'Problems', []);
        $usages = $dock->showList(ResultsPanelMode::Search, 'Usages', [['path' => 'a.php', 'line' => 0, 'text' => 'x']]);
        $this->debug->open('a.php');

        $dock->reveal(DockTab::Results); // '' = the model's active tool (Usages)
        $this->assertSame($this->results, $dock->visiblePanel());
        $this->assertSame($usages, $dock->activeTool());
        $this->assertSame($this->results, $this->tui->getFocus());

        $dock->reveal(DockTab::Results, (string) $problems->id); // switch instances by key
        $this->assertSame($problems, $dock->activeTool(), 'a keyed reveal binds that instance');
        $this->assertSame(ResultsPanelMode::Problems, $dock->mode(), 'the dock mode follows the bound tab');

        // Already displayed: the bind is a no-op, but the focus is the other
        // half of what revealing a tab means — clicking the tab you are
        // looking at must still take you to it.
        $this->tui->setFocus($this->terminal);
        $dock->reveal(DockTab::Results, (string) $problems->id);
        $this->assertSame($problems, $dock->activeTool(), 'still bound to the same instance');
        $this->assertSame($this->results, $this->tui->getFocus(), 'and the focus landed anyway');
        $dock->reveal(DockTab::Results, '99'); // unknown key: a no-op
        $this->assertSame($problems, $dock->activeTool());
        $dock->reveal(DockTab::Terminal); // no reveal hook wired here: a no-op
        $this->assertSame($this->results, $dock->visiblePanel());

        $revealed = false;
        $dock->onTerminalReveal(static function () use (&$revealed): void {
            $revealed = true;
        });
        $dock->reveal(DockTab::Terminal);
        $this->assertTrue($revealed, 'a Terminal tab reveals through its controller hook');

        $this->sessions->create('shell:1', 'sh 1');
        $this->sessions->create('shell:2', 'sh 2'); // create activates the newest
        $dock->reveal(DockTab::Terminal, 'shell:1');
        $this->assertSame('shell:1', $this->sessions->active()?->id, 'a keyed session reveal activates that session first');

        $this->debug->close();
        $dock->reveal(DockTab::Debug); // dead tab: a no-op
        $this->assertSame($this->results, $dock->visiblePanel());
    }

    public function testCycleWalksTheStripTabsInstancesIncluded(): void
    {
        $dock = $this->dock();
        $dock->cycle(1); // nothing alive: a no-op

        $problems = $dock->showList(ResultsPanelMode::Problems, 'Problems', []);
        $dock->cycle(1); // a single tab: a no-op
        $this->assertSame($this->results, $dock->visiblePanel());

        $todo = $dock->showList(ResultsPanelMode::Todo, 'TODO', []);
        $this->debug->open('a.php');

        $dock->cycle(1); // debug → wraps to the first instance
        $this->assertSame($problems, $dock->activeTool(), 'cycle walks into the tool instances');
        $dock->cycle(1);
        $this->assertSame($todo, $dock->activeTool());
        $dock->cycle(1);
        $this->assertSame($this->debug, $dock->visiblePanel(), 'and on to the Debug tab');
        $dock->cycle(-1);
        $this->assertSame($todo, $dock->activeTool(), 'and back');
    }

    public function testCloseTabClosesToolsAndRoutesSessionTabsToTheirController(): void
    {
        $dock = $this->dock();
        $this->sessions->create('shell:1', 'sh 1');
        $this->sessions->create('shell:2', 'sh 2');
        $this->terminal->open('sh 2', new TerminalBuffer());
        $problems = $dock->showList(ResultsPanelMode::Problems, 'Problems', []);
        $todo = $dock->showList(ResultsPanelMode::Todo, 'TODO', []);

        $dock->closeTab(DockTab::Results, (string) $problems->id); // × an INACTIVE instance
        $this->assertFalse($dock->isToolAlive($problems), 'the strip × really closes a tool');
        $this->assertSame($todo, $dock->activeTool(), 'the displayed tool is untouched');

        $dock->closeTab(DockTab::Results); // × the last (active) instance
        $this->assertFalse($this->results->isOpen(), 'the last tool close closes the panel');
        $this->assertSame($this->terminal, $dock->visiblePanel(), 'the previous dock tab comes back');
        $dock->closeTab(DockTab::Results, '42'); // unknown key: a no-op

        $dock->closeTab(DockTab::Terminal, 'shell:1'); // no close hook wired: a no-op
        $closed = [];
        $dock->onTerminalSessionClose(static function (int $index) use (&$closed): void {
            $closed[] = $index;
        });
        $dock->closeTab(DockTab::Terminal, 'shell:1');
        $dock->closeTab(DockTab::Terminal, 'nope'); // unknown session: a no-op
        $this->assertSame([0], $closed, "a session tab's × routes its index to the controller");

        $this->debug->open('a.php');
        $dock->closeTab(DockTab::Debug);
        $this->assertFalse($this->debug->isOpen(), 'the Debug × closes the panel for real');
    }

    public function testClosingTheDisplayedInstanceBindsItsNeighbour(): void
    {
        $dock = $this->dock();
        $problems = $dock->showList(ResultsPanelMode::Problems, 'Problems', [['path' => 'a.php', 'line' => 0, 'text' => 'boom']]);
        $todo = $dock->showList(ResultsPanelMode::Todo, 'TODO', []);
        $closed = [];
        $dock->onToolClosed(static function ($tab) use (&$closed): void {
            $closed[] = $tab;
        });

        $dock->closeTab(DockTab::Results, (string) $todo->id);
        $this->assertSame([$todo], $closed, 'the close hook fired for the ×ed tab');
        $this->assertSame($problems, $dock->activeTool(), 'the neighbour instance took the slot');
        $this->assertSame(ResultsPanelMode::Problems, $dock->mode());
        $this->assertTrue($this->results->isOpen(), 'the panel stays open while instances remain');
    }

    public function testMarkSearchingAndRefreshIfBound(): void
    {
        $dock = $this->dock();
        $todo = $dock->showList(ResultsPanelMode::Todo, 'TODO', []);
        $dock->markSearching($todo);
        $this->assertStringContainsString('searching…', $this->results->statusText());

        $problems = $dock->showList(ResultsPanelMode::Problems, 'Problems', []);
        $todo->rows = [['path' => 'a.php', 'line' => 0, 'text' => 'TODO x']];
        $todo->searching = false;
        $dock->refreshIfBound($todo); // not on display: the model keeps it, the widget shows Problems
        $this->assertSame('Problems', $this->results->getTitle());
        $this->assertStringContainsString('0 results', $this->results->statusText());

        $dock->reveal(DockTab::Results, (string) $todo->id);
        $this->assertStringContainsString('1 results', $this->results->statusText(), 'binding shows the refreshed rows');

        $todo->rows = [];
        $dock->refreshIfBound($todo); // on display: pushed straight to the widget
        $this->assertStringContainsString('0 results', $this->results->statusText());

        $dock->markSearching($problems); // not on display: flag only
        $this->assertTrue($problems->searching);
        $this->assertStringNotContainsString('searching…', $this->results->statusText());

        // Binding a still-searching tab restores its 'searching…' status…
        $dock->reveal(DockTab::Results, (string) $problems->id);
        $this->assertStringContainsString('searching…', $this->results->statusText());
        // …and a bound refresh of a still-searching tab keeps it.
        $dock->refreshIfBound($problems);
        $this->assertStringContainsString('searching…', $this->results->statusText());
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

    public function testRevealingTheAlreadyVisibleDebugTabStillFocusesIt(): void
    {
        $dock = $this->dock();
        $this->debug->open('a.php');
        $dock->reveal(DockTab::Debug);
        $this->assertSame($this->debug, $dock->visiblePanel());

        $this->tui->setFocus($this->terminal);
        $dock->reveal(DockTab::Debug); // already displayed

        $this->assertSame($this->debug, $this->tui->getFocus(), 'clicking the displayed Debug tab brings you to it');
    }

    public function testRevealingADebugTabThatIsNotOpenStaysANoOp(): void
    {
        $dock = $this->dock();
        $this->tui->setFocus($this->terminal);

        $dock->reveal(DockTab::Debug); // no session ever started

        $this->assertSame($this->terminal, $this->tui->getFocus(), 'nothing to reveal, nothing to focus');
    }
}
