<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Workbench\Dock;

use PHPUnit\Framework\TestCase;
use Porta\Editor\BufferManager;
use Porta\Editor\EditorPanes;
use Porta\Event\DockTabMoveRequested;
use Porta\Event\DockToolBound;
use Porta\Event\DockToolClosed;
use Porta\Event\TerminalRevealRequested;
use Porta\Event\TerminalSessionCloseRequested;
use Porta\Terminal\PtyProcess;
use Porta\Terminal\TerminalBuffer;
use Porta\Terminal\TerminalSession;
use Porta\Terminal\TerminalSessions;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\TestBottomDock;
use Porta\Tests\Support\TestTui;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\DebugPanelWidget;
use Porta\Widget\DockTabsWidget;
use Porta\Widget\ResultsPanelWidget;
use Porta\Widget\StatusBarWidget;
use Porta\Widget\Support\ResultsPanelFields;
use Porta\Widget\Support\ResultsViewState;
use Porta\Widget\TerminalPanelWidget;
use Porta\Workbench\Dock\BottomDock;
use Porta\Workbench\Dock\DockPanels;
use Porta\Workbench\Dock\DockTab;
use Porta\Workbench\Dock\DockToolHandlers;
use Porta\Workbench\Dock\DockToolTab;
use Porta\Workbench\Dock\DockToolTabs;
use Porta\Workbench\Dock\ResultsPanelMode;
use Porta\Workbench\EditorArea;
use Porta\Workbench\Notifier;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Tui\Render\RenderContext;
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

    private function dock(?EventDispatcher $events = null): BottomDock
    {
        $events ??= new EventDispatcher();
        $this->tui = TestTui::create(100, 24);
        $this->editor = new CodeEditorWidget();
        $this->terminal = new TerminalPanelWidget();
        $this->results = new ResultsPanelWidget();
        $this->debug = new DebugPanelWidget();
        $this->strip = new DockTabsWidget(events: $events);
        $this->sessions = new TerminalSessions();
        $this->tools = new DockToolTabs();

        return TestBottomDock::create(
            $this->tui,
            $this->terminal,
            $this->results,
            $this->debug,
            new EditorArea(
                new BufferManager(),
                $this->editor,
                new EditorPanes(),
                $this->tui,
            ),
            $this->strip,
            $this->sessions,
            $this->tools,
            $events,
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
        $dock->showList(ResultsPanelMode::Notifications, 'Notifications', [['path' => 'a', 'line' => 0, 'text' => 't']]);

        $tabs = $dock->aliveTabs();
        $this->assertTrue($dock->stripVisible());
        $this->assertSame([DockTab::Terminal, DockTab::Results, DockTab::Results], \array_column($tabs, 'tab'));
        $this->assertSame(['sh 1', 'Problems', 'Notifications'], \array_column($tabs, 'label'), 'sessions and tool instances each keep their own strip tab');
        $this->assertSame([false, false, true], \array_column($tabs, 'active'), 'the newest tool owns the slot');
        $this->assertSame(['shell:1', '1', '2'], \array_column($tabs, 'key'), 'session tabs carry their session id, tool tabs their instance key');
        $this->assertSame(['', '', '1 result'], \array_column($tabs, 'status'), 'only the displayed tool carries a status');

        $dock->hide();
        $this->assertSame([], $dock->aliveTabs(), 'hiding the dock hides the strip too');
    }

    public function testAStripDragReordersWithinItsOwnFamilyModel(): void
    {
        $dock = $this->dock();
        $this->sessions->create('shell:1', 'sh 1');
        $this->sessions->create('shell:2', 'sh 2');
        $dock->showList(ResultsPanelMode::Problems, 'Problems', []);
        $dock->showList(ResultsPanelMode::Notifications, 'Notifications', []);

        $this->strip->moveTab(DockTab::Results, 0, 1); // the wired widget callback lands in DockToolTabs::move
        $this->assertSame(['Notifications', 'Problems'], \array_map(static fn (DockToolTab $tab): string => $tab->title, $this->tools->all()));
        $this->assertSame('Notifications', $this->tools->active()?->title, 'the active pointer follows its tab');

        $this->strip->moveTab(DockTab::Terminal, 0, 1); // and the Terminal family lands in TerminalSessions::move
        $this->assertSame(['shell:2', 'shell:1'], \array_map(static fn (TerminalSession $session): string => $session->id, $this->sessions->all()));

        $this->strip->moveTab(DockTab::Debug, 0, 1); // a single fixed tab: nothing to reorder
        $this->assertSame(['Notifications', 'Problems'], \array_map(static fn (DockToolTab $tab): string => $tab->title, $this->tools->all()));
    }

    public function testReinvokingAToolReusesItsTabAndRefreshes(): void
    {
        $dock = $this->dock();
        $first = $dock->showList(ResultsPanelMode::Problems, 'Problems', []);
        $dock->showList(ResultsPanelMode::Search, 'Usages', [['path' => 'a.php', 'line' => 0, 'text' => 'x']]);

        $again = $dock->showList(ResultsPanelMode::Problems, 'Problems', [['path' => 'b.php', 'line' => 1, 'text' => 'boom']]);
        $this->assertSame($first, $again, 'same (mode, title) reuses the tab');
        $this->assertCount(2, Probe::get($this->tools, 'tabs'), 'no duplicate appended');
        $this->assertSame($first, $dock->activeTool(), 'the reused tab took the slot back');
        $this->assertSame([['path' => 'b.php', 'line' => 1, 'text' => 'boom']], $first->rows, 'and its content refreshed');

        $other = $dock->showList(ResultsPanelMode::Search, 'Usages: other', []);
        $this->assertCount(3, Probe::get($this->tools, 'tabs'), 'a different title appends a new tab');
        $this->assertSame($other, $dock->activeTool());
    }

    public function testRevealSwitchesTabsAndFocuses(): void
    {
        $events = new EventDispatcher();
        $dock = $this->dock($events);
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
        $events->addListener(TerminalRevealRequested::class, static function () use (&$revealed): void {
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

        $todo = $dock->showList(ResultsPanelMode::Notifications, 'Notifications', []);
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
        $events = new EventDispatcher();
        $dock = $this->dock($events);
        $this->sessions->create('shell:1', 'sh 1');
        $this->sessions->create('shell:2', 'sh 2');
        $this->terminal->open('sh 2', new TerminalBuffer());
        $problems = $dock->showList(ResultsPanelMode::Problems, 'Problems', []);
        $todo = $dock->showList(ResultsPanelMode::Notifications, 'Notifications', []);

        $dock->closeTab(DockTab::Results, (string) $problems->id); // × an INACTIVE instance
        $this->assertFalse($dock->isToolAlive($problems), 'the strip × really closes a tool');
        $this->assertSame($todo, $dock->activeTool(), 'the displayed tool is untouched');

        $dock->closeTab(DockTab::Results); // × the last (active) instance
        $this->assertFalse($this->results->isOpen(), 'the last tool close closes the panel');
        $this->assertSame($this->terminal, $dock->visiblePanel(), 'the previous dock tab comes back');
        $dock->closeTab(DockTab::Results, '42'); // unknown key: a no-op

        $dock->closeTab(DockTab::Terminal, 'shell:1'); // no close hook wired: a no-op
        $closed = [];
        $events->addListener(TerminalSessionCloseRequested::class, static function (TerminalSessionCloseRequested $event) use (&$closed): void {
            $closed[] = $event->index;
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
        $events = new EventDispatcher();
        $dock = $this->dock($events);
        $problems = $dock->showList(ResultsPanelMode::Problems, 'Problems', [['path' => 'a.php', 'line' => 0, 'text' => 'boom']]);
        $todo = $dock->showList(ResultsPanelMode::Notifications, 'Notifications', []);
        $closed = [];
        $events->addListener(DockToolClosed::class, static function (DockToolClosed $event) use (&$closed): void {
            $closed[] = $event->tab;
        });

        $dock->closeTab(DockTab::Results, (string) $todo->id);
        $this->assertSame([$todo], $closed, 'the close event carried the ×ed tab');
        $this->assertSame($problems, $dock->activeTool(), 'the neighbour instance took the slot');
        $this->assertSame(ResultsPanelMode::Problems, $dock->mode());
        $this->assertTrue($this->results->isOpen(), 'the panel stays open while instances remain');
    }

    public function testMarkSearchingAndRefreshIfBound(): void
    {
        $dock = $this->dock();
        $todo = $dock->showList(ResultsPanelMode::Notifications, 'Notifications', []);
        $dock->markSearching($todo);
        $this->assertStringContainsString('searching…', $this->results->statusText());

        $problems = $dock->showList(ResultsPanelMode::Problems, 'Problems', []);
        $todo->rows = [['path' => 'a.php', 'line' => 0, 'text' => 'TODO x']];
        $todo->searching = false;
        $dock->refreshIfBound($todo); // not on display: the model keeps it, the widget shows Problems
        $this->assertSame('Problems', $this->results->getTitle());
        $this->assertStringContainsString('0 results', $this->results->statusText());

        $dock->reveal(DockTab::Results, (string) $todo->id);
        $this->assertStringContainsString('1 result', $this->results->statusText(), 'binding shows the refreshed rows');

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
        $events = new EventDispatcher();
        $dock = $this->dock($events);
        $this->sessions->create('shell:1', 'sh 1');
        $this->terminal->open('sh 1', new TerminalBuffer());
        $events->addListener(TerminalRevealRequested::class, function (): void {
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

        $this->assertSame(17, Probe::get($this->terminal, 'visibleRows'));
        $this->assertSame(17, Probe::get($this->results, 'visibleRows'));
        $this->assertSame(17, Probe::get($this->debug, 'visibleRows'));
        $this->assertSame($this->terminal, Probe::get($dock, 'terminalPanel'));
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

    public function testRevealingAnAlreadyFocusedVisibleTabStillRenders(): void
    {
        $dock = $this->dock();
        $this->debug->open('a.php');
        $dock->reveal(DockTab::Debug); // visible AND focused already
        $this->assertSame($this->debug, $this->tui->getFocus());

        // Focus does not move this time — Tui::setFocus()'s own
        // requestRender (unconditional only on an actual focus CHANGE) does
        // not fire, isolating focusPanel()'s own requestRender call.
        Probe::set($this->tui, 'renderRequested', false);
        $dock->reveal(DockTab::Debug);

        $this->assertTrue(Probe::get($this->tui, 'renderRequested'), 'clicking the already-focused tab still repaints');
    }

    public function testRevealingADebugTabThatIsNotOpenStaysANoOp(): void
    {
        $dock = $this->dock();
        $this->tui->setFocus($this->terminal);

        $dock->reveal(DockTab::Debug); // no session ever started

        $this->assertSame($this->terminal, $this->tui->getFocus(), 'nothing to reveal, nothing to focus');
    }

    public function testSwitchingTabsRoundTripsTheViewState(): void
    {
        $dock = $this->dock();
        $rows = [
            ['path' => 'src/A.php', 'line' => 0, 'text' => 'alpha zero'],
            ['path' => 'src/A.php', 'line' => 4, 'text' => 'alpha four'],
            ['path' => 'src/B.php', 'line' => 2, 'text' => 'beta two'],
        ];
        $problems = $dock->showList(ResultsPanelMode::Problems, 'Problems', $rows);
        $this->results->handleInput("\x1b[B"); // move the selection off the first match

        $dock->showList(ResultsPanelMode::Notifications, 'Notifications', [['path' => 't.php', 'line' => 1, 'text' => 'todo']]);
        $this->assertInstanceOf(ResultsViewState::class, $problems->viewState, 'switching away froze the outgoing tab\'s view state');
        $this->assertSame('src/A.php', $problems->viewState->selectedPath);
        $this->assertSame(4, $problems->viewState->selectedLine);

        $dock->showList(ResultsPanelMode::Problems, 'Problems', $rows);
        $captured = $this->results->captureViewState();
        $this->assertSame('src/A.php', $captured->selectedPath, 'switching back restored the selection by identity');
        $this->assertSame(4, $captured->selectedLine);
    }

    public function testAClosedTabsStateDoesNotLeakOntoItsNeighbour(): void
    {
        $dock = $this->dock();
        $dock->showList(ResultsPanelMode::Problems, 'Problems', [
            ['path' => 'p.php', 'line' => 1, 'text' => 'problem'],
            ['path' => 'q.php', 'line' => 2, 'text' => 'quibble'],
        ]);
        $this->results->handleInput("\x1b[B"); // neighbour's own state: second row
        $displayed = $dock->showList(ResultsPanelMode::Notifications, 'Notifications', [['path' => 't.php', 'line' => 1, 'text' => 'todo']]);

        $dock->closeTab(DockTab::Results); // closes TODO, binds the Problems neighbour
        $this->assertFalse($this->tools->contains($displayed));
        $captured = $this->results->captureViewState();
        $this->assertSame('q.php', $captured->selectedPath, 'the neighbour got ITS OWN frozen state back, not the corpse\'s');
    }

    public function testAFreshBindStillResetsAndSelectsTheFirstMatch(): void
    {
        $dock = $this->dock();
        $dock->showList(ResultsPanelMode::Problems, 'Problems', [
            ['path' => 'p.php', 'line' => 1, 'text' => 'problem'],
            ['path' => 'q.php', 'line' => 2, 'text' => 'quibble'],
        ]);
        $captured = $this->results->captureViewState();
        $this->assertSame('p.php', $captured->selectedPath, 'no saved state: the first match is selected as always');
        $this->assertSame('', $captured->filter);
    }

    public function testShowToolBindsAContributedTabAndLeavesTheModeBehind(): void
    {
        // A contributed tool is not one of core's multiplexed contents, so the
        // dock's mode goes null while it is bound. Anything that reads mode()
        // to decide what a row MEANS has to see that, not the stale mode of
        // whichever core tool happened to be bound before.
        $dock = $this->dock();
        $dock->showList(ResultsPanelMode::Problems, 'Problems', []);
        $this->assertSame(ResultsPanelMode::Problems, $dock->mode());

        $handlers = new DockToolHandlers('todo:todo');
        $tab = $dock->showTool($handlers, 'Notifications', [['path' => 'a.php', 'line' => 1, 'text' => 'TODO: this']]);

        $this->assertNull($dock->mode(), 'no core mode is serving this tab');
        $this->assertSame($handlers, $tab->handlers);
        $this->assertSame($tab, $dock->activeTool());
        $this->assertSame($this->results, $dock->visiblePanel());
        $this->assertSame($this->results, $this->tui->getFocus(), 'showing a tool focuses it');

        $again = $dock->showTool($handlers, 'Notifications', []);
        $this->assertSame($tab, $again, 'the id reuses the tab rather than appending');
        $this->assertSame([], $again->rows);
    }

    public function testTabMovedWithANullFamilyIsANoOp(): void
    {
        $events = new EventDispatcher();
        $dock = $this->dock($events);

        $events->dispatch(new DockTabMoveRequested(null, 0, 1));

        $this->addToAssertionCount(1); // must not throw UnhandledMatchError
        $this->assertSame($this->tools, Probe::get($dock, 'tools'), 'and nothing moved');
    }

    public function testDismissingTheTerminalOrResultsPanelHidesTheDock(): void
    {
        $dock = $this->dock();
        $this->terminal->open('Terminal', new TerminalBuffer());
        $this->terminal->dismiss();
        $this->assertNull($dock->visiblePanel(), "the terminal panel's Esc is wired to hide()");
        $this->assertTrue($this->terminal->isOpen(), 'a soft hide (hide()) keeps the tab alive, unlike the unwired close() fallback');

        $dock->showList(ResultsPanelMode::Problems, 'Problems', []);
        $this->results->dismiss();
        $this->assertNull($dock->visiblePanel(), "the results panel's Esc is wired to hide()");
        $this->assertTrue($this->results->isOpen(), 'same: still a soft hide, not a real close');
    }

    public function testAliveTabsMarksOnlyTheActiveSessionAndFlagsAStoppedOne(): void
    {
        $dock = $this->dock();
        $this->sessions->create('shell:1', 'sh 1');
        $sh2 = $this->sessions->create('shell:2', 'sh 2'); // create() activates the newest
        $sh2->attach(new PtyProcess(['/bin/true'], '/tmp')); // attached but never started: stopped, not running
        $this->terminal->open('sh 2', new TerminalBuffer());
        Probe::set($this->terminal, 'scrollBack', 3); // gives statusText() a non-empty value while shown+active

        $tabs = $dock->aliveTabs();

        $this->assertSame([false, true], \array_column($tabs, 'active'), 'only the truly-active session is flagged, not every session while the terminal is shown');
        $this->assertSame(['sh 1', 'sh 2 ○'], \array_column($tabs, 'label'), 'a stopped session (process attached, not running) gets the glyph with its leading space; a never-attached one does not');
        $this->assertNotSame('', $this->terminal->statusText(), 'sanity: the status really is non-empty here');
        $this->assertSame(['', $this->terminal->statusText()], \array_column($tabs, 'status'), "only the active row carries the panel's status");
    }

    public function testMultipleContributedPanelsAllParticipateInScanningAndVisibility(): void
    {
        $panels = new DockPanels();
        $closed = new DebugPanelWidget();
        $panels->register($closed, 'closed-one', static fn (): string => 'Closed');
        $open = new DebugPanelWidget();
        $panels->register($open, 'open-one', static fn (): string => 'Open');

        $tui = TestTui::create(100, 24);
        $dock = new BottomDock(
            $tui,
            new TerminalPanelWidget(),
            new ResultsPanelWidget(),
            $panels,
            new DockTabsWidget(),
            new DockToolTabs(),
            new TerminalSessions(),
            new Notifier(new StatusBarWidget(), $tui),
            new EditorArea(new BufferManager(), new CodeEditorWidget(), new EditorPanes(), $tui),
        );
        $dock->wire();

        $open->open('b.php'); // opens AND, via the wired hook, takes the dock slot

        // panels() must carry every registered panel, not just the first, or
        // the second one's own onOpen/onClose/onDismiss hooks are never wired
        // and it can never become the visible one at all.
        $this->assertSame($open, $dock->visiblePanel(), 'the second registered panel can become visible too');

        // aliveTabs() must skip the closed FIRST entry without stopping the
        // scan, so the open SECOND one still reaches the strip.
        $this->assertSame(['open-one'], \array_column($dock->aliveTabs(), 'key'));
    }

    public function testRevealAndCloseTabOnTerminalToleratesNoEventDispatcher(): void
    {
        $tui = TestTui::create(100, 24);
        $sessions = new TerminalSessions();
        $sessions->create('shell:1', 'sh 1');
        $dock = TestBottomDock::create(
            $tui,
            new TerminalPanelWidget(),
            new ResultsPanelWidget(),
            new DebugPanelWidget(),
            sessions: $sessions,
        );

        $dock->reveal(DockTab::Terminal); // must not crash on the nullsafe dispatch
        $dock->closeTab(DockTab::Terminal, 'shell:1'); // ditto, a different nullsafe site

        $this->addToAssertionCount(2);
    }

    public function testHideReturnsFocusFromTheVisiblePanelAndRenders(): void
    {
        $dock = $this->dock();
        $this->debug->open('a.php');
        $this->tui->setFocus($this->debug);
        Probe::set($this->tui, 'renderRequested', false);

        $dock->hide();

        $this->assertSame($this->editor, $this->tui->getFocus(), 'focus returns to the editor');
        $this->assertTrue(Probe::get($this->tui, 'renderRequested'), 'hide() repaints even when something was visible');
    }

    public function testHideWithNothingVisibleDoesNotTouchFocus(): void
    {
        $dock = $this->dock();
        $this->tui->setFocus($this->terminal); // arbitrary: nothing is open, so nothing is visible
        Probe::set($this->tui, 'renderRequested', false);

        $dock->hide(); // must not try to return focus FROM a null visible panel

        $this->assertSame($this->terminal, $this->tui->getFocus());
        // With nothing visible, returnFocusFrom() (whose own tail always
        // renders) is never reached — hide()'s OWN requestRender() call is
        // the only thing left that can still request a repaint here.
        $this->assertTrue(Probe::get($this->tui, 'renderRequested'), 'hiding an already-hidden dock still repaints (e.g. the strip disappearing)');
    }

    public function testCycleWithASingleTabIsANoOpAndDoesNotStealFocus(): void
    {
        $dock = $this->dock();
        $dock->showList(ResultsPanelMode::Problems, 'Problems', []);
        $this->tui->setFocus($this->terminal); // deliberately elsewhere

        $dock->cycle(1);

        $this->assertSame($this->terminal, $this->tui->getFocus(), 'a single tab must not steal focus back');
    }

    public function testCycleWithExactlyTwoTabsStillCycles(): void
    {
        $dock = $this->dock();
        $problems = $dock->showList(ResultsPanelMode::Problems, 'Problems', []);
        $dock->showList(ResultsPanelMode::Notifications, 'Notifications', []);

        $dock->cycle(1);

        $this->assertSame($problems, $dock->activeTool(), 'exactly 2 tabs is still enough to cycle, not "too few"');
    }

    public function testClosingATerminalTabByIdKeepsScanningPastNonMatches(): void
    {
        $events = new EventDispatcher();
        $dock = $this->dock($events);
        $this->sessions->create('shell:1', 'sh 1');
        $this->sessions->create('shell:2', 'sh 2');
        $closed = [];
        $events->addListener(TerminalSessionCloseRequested::class, static function (TerminalSessionCloseRequested $event) use (&$closed): void {
            $closed[] = $event->index;
        });

        $dock->closeTab(DockTab::Terminal, 'shell:2');

        $this->assertSame([1], $closed, 'the loop must keep scanning past the shell:1 mismatch to find shell:2 at index 1');
    }

    public function testClosingAnUnregisteredContributedPanelIsANoOp(): void
    {
        $dock = $this->dock();

        $dock->closeTab(null, 'nonexistent'); // no panel registered under a null tab at all

        $this->addToAssertionCount(1); // must not crash calling close() on a null find() result
    }

    public function testClosingTheDebugTabDoesNotAlsoCloseTheActiveToolTab(): void
    {
        $dock = $this->dock();
        $tool = $dock->showList(ResultsPanelMode::Problems, 'Problems', []);
        $this->debug->open('a.php');

        $dock->closeTab(DockTab::Debug);

        $this->assertFalse($this->debug->isOpen(), 'the debug panel really closed');
        $this->assertTrue($dock->isToolAlive($tool), 'closing an unrelated tab must not also close the active tool');
    }

    public function testClosingABackgroundToolTabRendersButDoesNotRebind(): void
    {
        $events = new EventDispatcher();
        $dock = $this->dock($events);
        $problems = $dock->showList(ResultsPanelMode::Problems, 'Problems', []);
        $todo = $dock->showList(ResultsPanelMode::Notifications, 'Notifications', []); // displayed
        $bound = [];
        $events->addListener(DockToolBound::class, static function (DockToolBound $event) use (&$bound): void {
            $bound[] = $event->tab;
        });
        Probe::set($this->tui, 'renderRequested', false);

        $dock->closeTab(DockTab::Results, (string) $problems->id); // a BACKGROUND (non-displayed) tab

        $this->assertSame([], $bound, 'closing a tab that was never displayed must not rebind the one that is');
        $this->assertTrue(Probe::get($this->tui, 'renderRequested'), 'the strip still shrinks, so it still repaints');
        $this->assertSame($todo, $dock->activeTool(), 'the displayed tool is genuinely untouched');
    }

    public function testClosingTheDisplayedToolTabRebindsAndRenders(): void
    {
        $events = new EventDispatcher();
        $dock = $this->dock($events);
        $problems = $dock->showList(ResultsPanelMode::Problems, 'Problems', []);
        $todo = $dock->showList(ResultsPanelMode::Notifications, 'Notifications', []); // displayed
        $bound = [];
        $events->addListener(DockToolBound::class, static function (DockToolBound $event) use (&$bound): void {
            $bound[] = $event->tab;
        });
        Probe::set($this->tui, 'renderRequested', false);

        $dock->closeTab(DockTab::Results, (string) $todo->id); // the DISPLAYED tab itself

        $this->assertSame([$problems], $bound, 'closing the displayed tab rebinds its neighbour');
        $this->assertTrue(Probe::get($this->tui, 'renderRequested'));
    }

    public function testShowListUsesTheProvidedFieldsNotTheDefault(): void
    {
        $dock = $this->dock();

        $dock->showList(ResultsPanelMode::Search, 'Custom', [], ResultsPanelFields::searchResults());

        $this->assertTrue(Probe::get($this->results, 'grouped'), 'the caller-supplied fields must win over the resultsOnly() default');
    }

    public function testShowToolResetsSearchingOnReuse(): void
    {
        $dock = $this->dock();
        $handlers = new DockToolHandlers('demo:tool');
        $tab = $dock->showTool($handlers, 'Demo', []);
        $dock->markSearching($tab);
        $this->assertTrue($tab->searching);

        $again = $dock->showTool($handlers, 'Demo', []);

        $this->assertSame($tab, $again);
        $this->assertFalse($again->searching, 'reopening a tool must clear a stale searching flag, not leave it true');
    }

    public function testShowBindsFocusesAndRenders(): void
    {
        $dock = $this->dock();
        $tab = $dock->showList(ResultsPanelMode::Problems, 'Problems', []); // already focuses the panel
        Probe::set($this->tui, 'renderRequested', false);

        // Focus is ALREADY on the results panel, so Tui::setFocus()'s own
        // (unconditional-on-change) requestRender does not fire this time —
        // isolating show()'s OWN requestRender call.
        $dock->show($tab); // called directly, not through showList/showTool

        $this->assertSame($tab, $dock->activeTool());
        $this->assertSame($this->results, $this->tui->getFocus());
        $this->assertTrue(Probe::get($this->tui, 'renderRequested'));
    }

    public function testRevealingAConcealedOpenPanelActivatesAndFocusesIt(): void
    {
        $dock = $this->dock();
        $this->debug->open('a.php'); // opens and becomes visible
        $this->terminal->open('Terminal', new TerminalBuffer()); // conceals debug behind it
        $this->assertFalse($this->debug->isVisible());
        $this->tui->setFocus($this->terminal);
        Probe::set($this->tui, 'renderRequested', false);

        $dock->reveal(DockTab::Debug);

        $this->assertSame($this->debug, $dock->visiblePanel(), 'the concealed-but-open panel is brought back');
        $this->assertSame($this->debug, $this->tui->getFocus());
        $this->assertTrue(Probe::get($this->tui, 'renderRequested'));
    }

    public function testMarkSearchingAndRefreshIfBoundRequestARender(): void
    {
        $dock = $this->dock();
        $todo = $dock->showList(ResultsPanelMode::Notifications, 'Notifications', []);
        Probe::set($this->tui, 'renderRequested', false);

        $dock->markSearching($todo);
        $this->assertTrue(Probe::get($this->tui, 'renderRequested'), 'markSearching() always repaints');

        Probe::set($this->tui, 'renderRequested', false);
        $dock->refreshIfBound($todo);
        $this->assertTrue(Probe::get($this->tui, 'renderRequested'), 'a bound refresh repaints too');
    }

    public function testReturnFocusFromDirectlyRepaintsEvenWithoutMovingFocus(): void
    {
        $dock = $this->dock();
        $this->debug->open('a.php');
        $this->tui->setFocus($this->terminal); // focus is NOT on $widget below
        Probe::set($this->tui, 'renderRequested', false);

        // Since focus isn't on $this->debug, the internal setFocus() branch
        // (whose own requestRender fires only on an actual focus CHANGE)
        // never runs — isolating returnFocusFrom()'s own trailing call.
        $dock->returnFocusFrom($this->debug); // public, callable directly

        $this->assertSame($this->terminal, $this->tui->getFocus(), 'focus is untouched — it was never on $widget');
        $this->assertTrue(Probe::get($this->tui, 'renderRequested'), 'but it still unconditionally repaints');
    }

    public function testActivatingAnAlreadySeenPanelMovesItToTheEndWithoutDuplicating(): void
    {
        $dock = $this->dock();
        $this->terminal->open('Terminal', new TerminalBuffer());
        $this->results->open('Problems', ResultsPanelFields::resultsOnly());
        $this->debug->open('a.php');
        $this->assertSame([$this->terminal, $this->results, $this->debug], Probe::get($dock, 'mru'));

        $this->terminal->open('Terminal', new TerminalBuffer()); // re-activate an already-seen panel

        $this->assertSame([$this->results, $this->debug, $this->terminal], Probe::get($dock, 'mru'), 'moved to the end, not duplicated');
    }

    public function testRestoreAfterReindexesMruSoAGapDoesNotSwallowTheLastEntry(): void
    {
        $dock = $this->dock();
        $this->terminal->open('Terminal', new TerminalBuffer());
        $this->results->open('Problems', ResultsPanelFields::resultsOnly());
        $this->debug->open('a.php'); // debug is now visible

        // Simulate an mru with a gap at index 1 — restoreAfter() must reindex
        // it before indexing by count()-1, or the true last entry (results,
        // now at key 2) is unreachable and nothing gets restored.
        Probe::set($dock, 'mru', [0 => $this->terminal, 2 => $this->results]);

        $this->debug->close();

        $this->assertSame($this->results, $dock->visiblePanel(), 'the reindexed mru must still find its last (real) entry');
    }
}
