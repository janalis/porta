<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Workbench\Dock;

use PHPUnit\Framework\TestCase;
use Porta\Editor\BufferManager;
use Porta\Event\DiagnosticsChanged;
use Porta\Event\LspLogAppended;
use Porta\Event\NotificationLogged;
use Porta\Lsp\LspLog;
use Porta\Search\ProblemRows;
use Porta\Support\ProjectPaths;
use Porta\Tests\Support\TestBottomDock;
use Porta\Tests\Support\TestTui;
use Porta\Widget\DebugPanelWidget;
use Porta\Widget\ResultsPanelWidget;
use Porta\Widget\TerminalPanelWidget;
use Porta\Workbench\Dock\BottomDock;
use Porta\Workbench\Dock\DockLiveRefreshListener;
use Porta\Workbench\Dock\DockToolTabs;
use Porta\Workbench\Dock\ResultsPanelMode;
use Porta\Workbench\NotificationLog;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Tui;

final class DockLiveRefreshListenerTest extends TestCase
{
    private Tui $tui;
    private ResultsPanelWidget $results;
    private BufferManager $buffers;
    private BottomDock $dock;
    private LspLog $lspLog;
    private NotificationLog $notificationLog;
    private DockToolTabs $tools;

    private function listener(): DockLiveRefreshListener
    {
        $this->tui = TestTui::create(100, 24);
        $this->results = new ResultsPanelWidget();
        $this->buffers = new BufferManager();
        $this->tools = new DockToolTabs();
        $this->dock = TestBottomDock::create($this->tui, new TerminalPanelWidget(), $this->results, new DebugPanelWidget(), tools: $this->tools);
        $this->lspLog = new LspLog(new MockClock('2026-07-16 10:00:00'), new EventDispatcher());
        $this->notificationLog = new NotificationLog(new MockClock('2026-07-16 11:00:00'), new EventDispatcher());

        return new DockLiveRefreshListener($this->dock, $this->tools, new ProblemRows($this->buffers, new ProjectPaths('/p')), $this->lspLog, $this->notificationLog);
    }

    private function plainPanel(): string
    {
        return AnsiUtils::stripAnsiCodes(implode("\n", $this->results->render(new RenderContext(100, 12))));
    }

    public function testRefreshesAnOpenProblemsTabInPlace(): void
    {
        $listener = $this->listener();
        $this->dock->showList(ResultsPanelMode::Problems, 'Problems', []);
        $this->assertStringContainsString('0 results', $this->results->statusText());

        $buffer = $this->buffers->open('/p/src/A.php', "<?php\n");
        $buffer->diagnostics = [0 => [['severity' => 1, 'message' => 'boom', 'col' => 0, 'endCol' => 1]]];
        $listener->onDiagnosticsChanged(new DiagnosticsChanged());

        $this->assertStringContainsString('✗ boom', $this->plainPanel(), 'the open Problems tab picked up the new diagnostic');
        $this->assertStringContainsString('1 results', $this->results->statusText());
    }

    public function testAConcealedProblemsTabStillRefreshes(): void
    {
        $listener = $this->listener();
        $this->dock->showList(ResultsPanelMode::Problems, 'Problems', []);
        $this->dock->hide(); // concealed, still alive

        $buffer = $this->buffers->open('/p/src/A.php', "<?php\n");
        $buffer->diagnostics = [0 => [['severity' => 2, 'message' => 'warn', 'col' => 0, 'endCol' => 1]]];
        $listener->onDiagnosticsChanged(new DiagnosticsChanged());

        $this->assertFalse($this->results->isVisible());
        $this->assertStringContainsString('1 results', $this->results->statusText(), 'the hidden tab keeps its count fresh for the strip');
    }

    public function testRefreshesAnOpenLspLogTab(): void
    {
        $listener = $this->listener();
        $this->dock->showList(ResultsPanelMode::LspLog, 'LSP Server Log', []);

        $this->lspLog->append('indexing…');
        $listener->onLspLogAppended(new LspLogAppended());

        $this->assertStringContainsString('10:00:00  indexing…', $this->plainPanel(), 'the open log tab tailed the new line');
    }

    public function testRefreshesAnOpenNotificationsTab(): void
    {
        $listener = $this->listener();
        $this->dock->showList(ResultsPanelMode::Notifications, 'Notifications', []);

        $this->notificationLog->append('saved a.php');
        $listener->onNotificationLogged(new NotificationLogged());

        $this->assertStringContainsString('11:00:00  saved a.php', $this->plainPanel(), 'the open tab picked up the new flash');
    }

    public function testNoRefreshWhenThePanelServesAnotherToolOrIsClosed(): void
    {
        $listener = $this->listener();
        $buffer = $this->buffers->open('/p/src/A.php', "<?php\n");
        $buffer->diagnostics = [0 => [['severity' => 1, 'message' => 'boom', 'col' => 0, 'endCol' => 1]]];

        $listener->onDiagnosticsChanged(new DiagnosticsChanged()); // closed panel: a no-op
        $this->assertFalse($this->results->isOpen());

        $this->dock->showList(ResultsPanelMode::Notifications, 'Notifications', [['path' => 'x', 'line' => 0, 'text' => 'entry']]);
        $listener->onDiagnosticsChanged(new DiagnosticsChanged()); // foreign mode: a no-op
        $this->assertStringContainsString('entry', $this->plainPanel(), 'the history rows were not clobbered');
        $this->assertStringNotContainsString('boom', $this->plainPanel());
    }

    public function testProblemsRefreshKeepsTheSelectedDiagnostic(): void
    {
        $listener = $this->listener();
        $this->dock->showList(ResultsPanelMode::Problems, 'Problems', []);

        $a = $this->buffers->open('/p/src/A.php', "<?php\n\n");
        $a->diagnostics = [0 => [['severity' => 1, 'message' => 'first', 'col' => 0, 'endCol' => 1]], 1 => [['severity' => 1, 'message' => 'second', 'col' => 0, 'endCol' => 1]]];
        $listener->onDiagnosticsChanged(new DiagnosticsChanged());
        $this->results->handleInput("\x1b[B"); // select the second diagnostic

        // A new buffer's diagnostics land ABOVE ours in the rebuilt rows.
        $before = $this->buffers->open('/p/src/0Aaa.php', "<?php\n");
        $before->diagnostics = [0 => [['severity' => 1, 'message' => 'earlier', 'col' => 0, 'endCol' => 1]]];
        $listener->onDiagnosticsChanged(new DiagnosticsChanged());

        $state = $this->results->captureViewState();
        $this->assertSame('src/A.php', $state->selectedPath, 'the selected diagnostic survived the live refresh');
        $this->assertSame(1, $state->selectedLine);
    }

    public function testLogAppendKeepsTheNewestLineSelected(): void
    {
        $listener = $this->listener();
        $this->notificationLog->append('older');
        $listener->onNotificationLogged(new NotificationLogged());
        $this->dock->showList(ResultsPanelMode::Notifications, 'Notifications', []);
        $listener->onNotificationLogged(new NotificationLogged());

        $this->notificationLog->append('newest');
        $listener->onNotificationLogged(new NotificationLogged());
        $this->assertStringContainsString('newest', explode("\n", $this->plainPanel())[0], 'location-less rows re-select the newest line on refresh');
    }
}
