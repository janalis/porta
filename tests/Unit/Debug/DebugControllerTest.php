<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Debug;

use PHPUnit\Framework\TestCase;
use Porta\Debug\BreakpointStore;
use Porta\Debug\DapClientFactory;
use Porta\Debug\DapClientInterface;
use Porta\Debug\DebugAdapters;
use Porta\Debug\DebugController;
use Porta\Debug\DebugSessionState;
use Porta\Debug\EditorDecorations;
use Porta\Editor\Document;
use Porta\Editor\FileOpener;
use Porta\Event\DebugStatusChanged;
use Porta\I18n\Translator;
use Porta\Tests\Support\FakeDapClient;
use Porta\Ui\Notifier;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\DebugPanelWidget;
use Porta\Widget\StatusBarWidget;
use Porta\Widget\TerminalPanelWidget;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

final class DebugControllerTest extends TestCase
{
    private CodeEditorWidget $editor;
    private DebugPanelWidget $panel;
    private StatusBarWidget $statusBar;
    private FakeDapClient $client;
    private int $statusEvents = 0;
    /** @var list<string> */
    private array $opened = [];

    private function controller(bool $withAdapter = true, bool $withFile = true): DebugController
    {
        $this->editor = new CodeEditorWidget();
        if ($withFile) {
            $this->editor->showDocument('/p/prog.php', new Document("<?php\n// l2\n// l3\n// l4\n"));
        }
        $this->panel = new DebugPanelWidget();
        $this->statusBar = new StatusBarWidget();
        $this->client = new FakeDapClient();
        $this->opened = [];
        $tui = new Tui(terminal: new VirtualTerminal(columns: 100, rows: 20));

        $breakpoints = new BreakpointStore();
        $state = new DebugSessionState();
        $opened = &$this->opened;
        $this->statusEvents = 0;
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(DebugStatusChanged::class, function (): void {
            ++$this->statusEvents;
        });

        return new DebugController(
            $breakpoints,
            new DebugAdapters($withAdapter ? ['php' => ['debug' => ['adapter' => 'mock-adapter']]] : []),
            $this->editor,
            $this->panel,
            new TerminalPanelWidget(),
            new Notifier($this->statusBar, $tui),
            $tui,
            new Translator(),
            '/p',
            new class($opened) implements FileOpener {
                /** @param list<string> $opened */
                public function __construct(
                    private array &$opened,
                ) {}

                public function openFile(string $path, bool $focusEditor = false): void
                {
                    $this->opened[] = $path;
                }
            },
            new class($this->client) implements DapClientFactory {
                public function __construct(
                    private readonly FakeDapClient $client,
                ) {}

                public function create(array $argv): DapClientInterface
                {
                    return $this->client;
                }
            },
            $dispatcher,
            $state,
            new EditorDecorations($breakpoints, $state, $this->editor),
        );
    }

    private function flashed(): string
    {
        return AnsiUtils::stripAnsiCodes(implode('', $this->statusBar->render(new RenderContext(100, 1))));
    }

    private function pauseAtBreakpoint(DebugController $controller, int $line = 2): void
    {
        $this->client->responses['stackTrace'] = [
            [
                'stackFrames' => [
                    ['id' => 1, 'name' => '{main}', 'source' => ['path' => '/p/prog.php'], 'line' => $line + 1],
                    ['id' => 2, 'name' => 'require', 'source' => ['path' => '/p/other.php'], 'line' => 1],
                ],
            ],
            null,
        ];
        $this->client->responses['scopes'] = [['scopes' => [['name' => 'Locals', 'variablesReference' => 1000]]], null];
        $this->client->responses['variables'] = [['variables' => [['name' => '$x', 'value' => '42', 'variablesReference' => 1001]]], null];

        $controller->startForCurrentFile();
        $this->client->respond('initialize', ['supportsConfigurationDoneRequest' => true]);
        $this->client->respond('launch');
        $this->client->emit('stopped', ['reason' => 'breakpoint', 'threadId' => 1]);
    }

    public function testGuardsFlashWithoutFileAdapterOrWhileRunning(): void
    {
        $controller = $this->controller(withFile: false);
        $controller->toggleBreakpointAtCaret();
        $this->assertStringContainsString('Nothing to debug', $this->flashed());
        $controller->startForCurrentFile();
        $this->assertStringContainsString('Nothing to debug', $this->flashed());

        $controller = $this->controller(withAdapter: false);
        $controller->startForCurrentFile();
        $this->assertStringContainsString('No debug adapter configured for php', $this->flashed());

        $controller = $this->controller();
        $this->pauseAtBreakpoint($controller);
        $this->assertTrue($controller->isActive());
        $controller->startForCurrentFile();
        $this->assertStringContainsString('Debugger already running', $this->flashed());
    }

    public function testFailedAdapterSpawnAndLaunchErrorsFlash(): void
    {
        $controller = $this->controller();
        $this->client->failToStart = true;
        $controller->startForCurrentFile();
        $this->assertStringContainsString('Could not start the debug adapter', $this->flashed());
        $this->assertFalse($controller->isActive());
        $this->client->emit('stopped', ['reason' => 'breakpoint', 'threadId' => 1]);
        $this->assertSame([], $this->opened, 'a straggler stop after a failed spawn is ignored');

        $controller = $this->controller();
        $controller->startForCurrentFile();
        $this->client->respond('initialize', [], 'bad handshake');
        $this->assertStringContainsString('Could not start the debug adapter — initialize failed: bad handshake', $this->flashed());
        $this->assertFalse($controller->isActive());
    }

    public function testStoppedMarshalsEditorPanelStatusAndFrameJumps(): void
    {
        $controller = $this->controller();
        $this->assertNull($controller->statusSegment(), 'idle → no segment');

        $this->pauseAtBreakpoint($controller, line: 2);
        $this->assertSame(['/p/prog.php'], $this->opened, 'stop opened the file through the choke point');
        $this->assertSame(2, $this->editor->getDocument()->getCursorLine());
        $this->assertTrue($controller->isPaused());
        $this->assertSame('⏸ prog.php:3', $controller->statusSegment());
        $this->assertSame(2, $this->statusEvents, 'start and pause each dispatched DebugStatusChanged');

        $panelScreen = implode("\n", array_map(AnsiUtils::stripAnsiCodes(...), $this->panel->render(new RenderContext(100, 14))));
        $this->assertStringContainsString('#0 {main}  prog.php:3', $panelScreen, 'frames display relative paths');
        $this->assertStringContainsString('$x = 42', $panelScreen);

        // Expanding a variable pulls its children through the live session.
        $this->client->responses['variables'] = [['variables' => [['name' => '0', 'value' => "'a'", 'variablesReference' => 0]]], null];
        // Rows: 0 Frames, 1 #0, 2 #1, 3 Variables, 4 Locals, 5 $x.
        for ($i = 0; $i < 5; ++$i) {
            $this->panel->handleInput("\x1b[B");
        }
        $this->panel->handleInput("\r");
        $this->assertStringContainsString("0 = 'a'", implode("\n", array_map(AnsiUtils::stripAnsiCodes(...), $this->panel->render(new RenderContext(100, 14)))));

        // Clicking the second frame jumps to its (absolute) source.
        $this->panel->handleClick(3); // header + Frames + #0 → rel 3 is #1
        $this->assertSame('/p/other.php', end($this->opened));
        $this->assertSame(0, $this->editor->getDocument()->getCursorLine());

        // A stale frame row the controller no longer knows is inert.
        $openedBefore = \count($this->opened);
        $this->panel->setFrames([['id' => 99, 'name' => 'stale', 'path' => 'x.php', 'line' => 0]]);
        $this->panel->handleClick(2); // header + Frames → rel 2 is the stale frame
        $this->assertCount($openedBefore, $this->opened, 'unknown frame ids do not jump');

        // Steps forward to the session; stop() disconnects.
        $this->client->emit('stopped', ['reason' => 'step', 'threadId' => 1]);
        $controller->stepIn();
        $this->assertContains('stepIn', $this->client->commands());
        $this->client->emit('stopped', ['reason' => 'step', 'threadId' => 1]);
        $controller->stepOut();
        $this->assertContains('stepOut', $this->client->commands());
        $this->client->emit('stopped', ['reason' => 'step', 'threadId' => 1]);
        $controller->continueRun();
        $this->assertContains('continue', $this->client->commands());
        $this->assertSame('▶ debug', $controller->statusSegment(), 'running segment');

        $controller->stop();
        $this->assertFalse($controller->isActive());
        $this->assertNull($controller->statusSegment());
    }

    public function testStoppedWithAnEmptyStackStillReportsThePause(): void
    {
        $controller = $this->controller();
        $this->client->responses['stackTrace'] = [[], 'no stack available'];
        $controller->startForCurrentFile();
        $this->client->respond('initialize', []);
        $this->client->respond('launch');
        $this->client->emit('stopped', ['reason' => 'exception', 'threadId' => 1]);

        $this->assertTrue($controller->isPaused());
        $header = AnsiUtils::stripAnsiCodes($this->panel->render(new RenderContext(100, 5))[0]);
        $this->assertStringContainsString('paused', $header);
        $this->assertSame([], $this->opened, 'nothing to open without a frame');

        // The remaining status transitions announce themselves on the bus too.
        $events = $this->statusEvents;
        $this->client->emit('continued', []);
        $this->assertSame($events + 1, $this->statusEvents, 'resume dispatched DebugStatusChanged');
        $this->client->emit('terminated', []);
        $this->assertSame($events + 2, $this->statusEvents, 'termination dispatched DebugStatusChanged');
    }

    public function testBreakpointTogglesSyncTheLiveSessionAndOtherFiles(): void
    {
        $controller = $this->controller();
        $this->pauseAtBreakpoint($controller);

        $controller->toggleBreakpoint('/p/prog.php', 1);
        $this->assertSame([['line' => 2]], $this->client->argumentsOf('setBreakpoints')['breakpoints'], 'live session got the 1-based line');
        $this->assertStringContainsString('●', implode("\n", $this->editor->render(new RenderContext(80, 8))));

        $controller->toggleBreakpoint('/p/elsewhere.php', 5);
        $this->assertSame('/p/elsewhere.php', $this->client->argumentsOf('setBreakpoints')['source']['path'], 'other files sync without touching the editor');

        // Re-applying decorations for another file clears this file's marks.
        $controller->applyEditorDecorations('/p/clean.php');
        $this->assertStringNotContainsString('●', implode("\n", $this->editor->render(new RenderContext(80, 8))));
    }
}
