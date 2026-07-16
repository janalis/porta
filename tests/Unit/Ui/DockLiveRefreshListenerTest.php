<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;
use Porta\Editor\BufferManager;
use Porta\Event\DiagnosticsChanged;
use Porta\Search\ProblemRows;
use Porta\Tests\Support\TestBottomDock;
use Porta\Ui\BottomDock;
use Porta\Ui\DockLiveRefreshListener;
use Porta\Ui\ResultsPanelMode;
use Porta\Widget\DebugPanelWidget;
use Porta\Widget\ResultsPanelWidget;
use Porta\Widget\TerminalPanelWidget;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Style\StyleSheet;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

final class DockLiveRefreshListenerTest extends TestCase
{
    private Tui $tui;
    private ResultsPanelWidget $results;
    private BufferManager $buffers;
    private BottomDock $dock;

    private function listener(): DockLiveRefreshListener
    {
        $this->tui = new Tui(terminal: new VirtualTerminal(columns: 100, rows: 24), keybindings: new Keybindings([]), renderer: new Renderer(new StyleSheet()));
        $this->results = new ResultsPanelWidget();
        $this->buffers = new BufferManager();
        $this->dock = TestBottomDock::create($this->tui, new TerminalPanelWidget(), $this->results, new DebugPanelWidget());

        return new DockLiveRefreshListener($this->dock, $this->results, $this->tui, new ProblemRows($this->buffers, '/p'));
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

    public function testNoRefreshWhenThePanelServesAnotherToolOrIsClosed(): void
    {
        $listener = $this->listener();
        $buffer = $this->buffers->open('/p/src/A.php', "<?php\n");
        $buffer->diagnostics = [0 => [['severity' => 1, 'message' => 'boom', 'col' => 0, 'endCol' => 1]]];

        $listener->onDiagnosticsChanged(new DiagnosticsChanged()); // closed panel: a no-op
        $this->assertFalse($this->results->isOpen());

        $this->dock->showList(ResultsPanelMode::History, 'Local History', [['path' => 'x', 'line' => 0, 'text' => 'entry']]);
        $listener->onDiagnosticsChanged(new DiagnosticsChanged()); // foreign mode: a no-op
        $this->assertStringContainsString('entry', $this->plainPanel(), 'the history rows were not clobbered');
        $this->assertStringNotContainsString('boom', $this->plainPanel());
    }
}
