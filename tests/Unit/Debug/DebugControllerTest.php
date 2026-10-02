<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Debug;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\Debug\BreakpointStore;
use Porta\Debug\DapClientFactory;
use Porta\Debug\DapClientInterface;
use Porta\Debug\DebugAdapters;
use Porta\Debug\DebugController;
use Porta\Debug\DebugSessionState;
use Porta\Debug\EditorDecorations;
use Porta\Debug\ExceptionFilterStore;
use Porta\Debug\InlineValues;
use Porta\Debug\WatchStore;
use Porta\Editor\Document;
use Porta\Editor\FileOpener;
use Porta\Editor\OpenFocus;
use Porta\Event\DebugFrameSelected;
use Porta\Event\DebugStatusChanged;
use Porta\Event\DebugVariableEditRequested;
use Porta\Event\DebugWatchRemovalRequested;
use Porta\I18n\Translator;
use Porta\Lsp\InlineValueRequest;
use Porta\Lsp\LspSessionPool;
use Porta\Support\ProjectPaths;
use Porta\Terminal\TerminalSession;
use Porta\Terminal\TerminalSessions;
use Porta\Tests\Support\EditorState;
use Porta\Tests\Support\FakeDapClient;
use Porta\Tests\Support\FakeLspClient;
use Porta\Tests\Support\FixedActiveFile;
use Porta\Tests\Support\Presence;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\TestBottomDock;
use Porta\Tests\Support\TestRuns;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\DebugPanelWidget;
use Porta\Widget\ResultsPanelWidget;
use Porta\Widget\StatusBarWidget;
use Porta\Widget\TerminalPanelWidget;
use Porta\Workbench\Notifier;
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

    private FakeLspClient $lspClient;
    private ExceptionFilterStore $filters;
    private TerminalSessions $sessions;
    private Tui $tui;
    private TerminalPanelWidget $terminalPanel;
    private int $statusEvents = 0;
    /** @var list<string> */
    private array $opened = [];

    /** A pool whose one session answers with $capabilities, for the tests that reach the LSP. */
    private function lspPool(array $capabilities): LspSessionPool
    {
        $pool = new LspSessionPool(
            '/p',
            $active = new FixedActiveFile(),
            new Translator('en'),
            fn (array $command, string $rootDir, \Closure $onLog): FakeLspClient => $this->lspClient = new FakeLspClient($capabilities),
        );
        $pool->configure(
            ['php' => [['command' => ['php-ls'], 'name' => 'php-ls']]],
            static function (string $line): void {},
            static function (array $params): void {},
            static fn (array $edit): bool => false,
        );
        $active->set('/p/prog.php');
        $pool->notifyDidOpen('/p/prog.php', "<?php\n", 'php', 1);

        return $pool;
    }

    private function controller(Presence $withAdapter = Presence::With, Presence $withFile = Presence::With, ?LspSessionPool $lsp = null): DebugController
    {
        $this->editor = new CodeEditorWidget();
        if (Presence::With === $withFile) {
            $this->editor->showDocument('/p/prog.php', new Document("<?php\n// l2\n// l3\n// l4\n"));
        }
        $dispatcher = new EventDispatcher();
        $this->panel = new DebugPanelWidget(events: $dispatcher);
        $this->statusBar = new StatusBarWidget();
        $this->client = new FakeDapClient();
        $this->opened = [];
        $tui = new Tui(terminal: new VirtualTerminal(columns: 100, rows: 20));
        $this->tui = $tui;
        $terminalPanel = new TerminalPanelWidget();
        $this->terminalPanel = $terminalPanel;
        $this->sessions = new TerminalSessions();
        $dock = TestBottomDock::create($tui, $terminalPanel, new ResultsPanelWidget(), $this->panel, sessions: $this->sessions);

        $breakpoints = new BreakpointStore();
        $state = new DebugSessionState();
        $opened = &$this->opened;
        $this->statusEvents = 0;
        $dispatcher->addListener(DebugStatusChanged::class, function (): void {
            ++$this->statusEvents;
        });

        $controller = new DebugController(
            $breakpoints,
            new DebugAdapters(Presence::With === $withAdapter ? ['php' => ['debug' => ['adapter' => 'mock-adapter']]] : []),
            $this->editor,
            $this->panel,
            $terminalPanel,
            new Notifier($this->statusBar, $tui),
            $tui,
            new Translator(),
            new ProjectPaths('/p'),
            new class($opened) implements FileOpener {
                /** @param list<string> $opened */
                public function __construct(
                    private array &$opened,
                ) {}

                public function openFile(string $path, OpenFocus $focus = OpenFocus::Keep): void
                {
                    $this->opened[] = $path;
                }
            },
            new readonly class($this->client) implements DapClientFactory {
                public function __construct(
                    private FakeDapClient $client,
                ) {}

                public function create(array $argv): DapClientInterface
                {
                    return $this->client;
                }
            },
            $dispatcher,
            $state,
            new EditorDecorations($breakpoints, $state, $this->editor->decorations),
            new WatchStore(),
            $this->filters = new ExceptionFilterStore(),
            $this->sessions,
            $dock,
            // A pool with no server up: `serverCapability()` answers null, so
            // the inline-value request is never sent and these scenarios see
            // exactly what they saw before it existed. The join has its own
            // test.
            new InlineValues(
                new InlineValueRequest($lsp ?? new LspSessionPool(\sys_get_temp_dir(), new FixedActiveFile(), new Translator())),
                $this->editor->decorations,
                $tui,
                $this->editor,
            ),
        );
        // Compiled from #[AsEventListener] in the container.
        $dispatcher->addListener(DebugFrameSelected::class, $controller->onFrameSelected(...));
        $dispatcher->addListener(DebugVariableEditRequested::class, $controller->onVariableEditRequested(...));
        $dispatcher->addListener(DebugWatchRemovalRequested::class, $controller->onWatchRemoved(...));

        return $controller;
    }

    private function flashed(): string
    {
        return AnsiUtils::stripAnsiCodes(\implode('', $this->statusBar->render(new RenderContext(100, 1))));
    }

    /** Clears the flag first: a caller checks whether ITS action requested a render, not a stale one. */
    private function requestedRenderSince(callable $action): bool
    {
        Probe::set($this->tui, 'renderRequested', false);
        $action();

        return (bool) Probe::get($this->tui, 'renderRequested');
    }

    /** @param array<string, mixed> $capabilities extra initialize capabilities to advertise */
    private function pauseAtBreakpoint(DebugController $controller, int $line = 2, array $capabilities = []): void
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
        $this->client->respond('initialize', [
            'supportsConfigurationDoneRequest' => true,
            'exceptionBreakpointFilters' => [
                ['filter' => 'warning', 'label' => 'Warnings', 'default' => false],
                ['filter' => 'exception', 'label' => 'Uncaught Exceptions', 'default' => true],
            ],
            ...$capabilities,
        ]);
        $this->client->respond('launch');
        $this->client->emit('stopped', ['reason' => 'breakpoint', 'threadId' => 1]);
    }

    public function testExpandVariableCallbackIsInertWithoutASession(): void
    {
        $this->controller(); // wires the panel callbacks; never starts a session
        $callbacks = Probe::get($this->panel, 'callbacks');

        $delivered = 'untouched';
        $requested = $this->requestedRenderSince(static function () use ($callbacks, &$delivered): void {
            ($callbacks->onExpandVariable)(1000, static function (array $v) use (&$delivered): void {
                $delivered = $v;
            });
        });

        $this->assertSame('untouched', $delivered, 'no session — the deliver callback is never invoked');
        $this->assertTrue($requested, 'the render is still requested even when there is nothing to expand');
    }

    public function testToggleBreakpointBeforeAnySessionOnlyTouchesTheGutter(): void
    {
        $controller = $this->controller();

        $requested = $this->requestedRenderSince(static function () use ($controller): void {
            $controller->toggleBreakpoint('/p/prog.php', 1);
        });

        $this->assertTrue($requested);
        $this->assertStringContainsString('●', \implode("\n", $this->editor->render(new RenderContext(80, 8))), 'the gutter updates without a live session');
        $this->assertSame([], $this->client->requests, 'nothing was sent — there is no session to sync');
    }

    public function testStartForCurrentFileRequestsARender(): void
    {
        // Isolated from any prior session (a fresh launch, not a restart's
        // stop+relaunch): nothing else in this call chain requests a render
        // first, so this specifically proves startForCurrentFile()'s own call.
        $controller = $this->controller();

        $requested = $this->requestedRenderSince(static function () use ($controller): void {
            $controller->startForCurrentFile();
        });

        $this->assertTrue($requested);
    }

    public function testGuardsFlashWithoutFileAdapterOrWhileRunning(): void
    {
        $controller = $this->controller(withFile: Presence::Without);
        $controller->toggleBreakpointAtCaret();
        $this->assertStringContainsString('Nothing to debug', $this->flashed());

        // A fresh controller: the assertion below must not be satisfied by
        // the stale flash from the toggle above (same message text).
        $controller = $this->controller(withFile: Presence::Without);
        $controller->startForCurrentFile();
        $this->assertStringContainsString('Nothing to debug', $this->flashed());

        $controller = $this->controller(withAdapter: Presence::Without);
        $controller->startForCurrentFile();
        $this->assertStringContainsString('No debug adapter configured for php', $this->flashed());

        $controller = $this->controller();
        $this->pauseAtBreakpoint($controller);
        $this->assertTrue($controller->isActive());
        $controller->startForCurrentFile();
        $this->assertStringContainsString('Debugger already running', $this->flashed());
        $this->assertCount(
            1,
            \array_filter($this->client->commands(), static fn (string $c): bool => 'initialize' === $c),
            'the guard stops before a second session is spun up',
        );
    }

    public function testAnAdapterThatDiesMidSessionIsSaidAndItsSessionEnds(): void
    {
        $controller = $this->controller();
        $controller->startForCurrentFile();
        $this->client->respond('initialize', []);
        $this->client->respond('launch');

        $this->client->close();

        $this->assertStringContainsString('The debug adapter stopped unexpectedly', $this->flashed());
        $this->assertStringNotContainsString('Could not start', $this->flashed(), 'it had started');
        $this->assertFalse($controller->isActive());
        $this->assertStringContainsString('terminated', $this->panel->statusText());
    }

    public function testASessionThatEndsStopsItsAdapter(): void
    {
        $controller = $this->controller();
        $controller->startForCurrentFile();
        $this->client->respond('initialize', []);
        $this->client->respond('launch');

        $this->client->emit('terminated', []);

        $this->assertTrue($this->client->stopped, 'not left running, holding its port for the next session');
    }

    public function testFailedAdapterSpawnAndLaunchErrorsFlash(): void
    {
        $controller = $this->controller();
        $this->client->failToStart = true;
        $controller->startForCurrentFile();
        $this->assertStringContainsString('Could not start the debug adapter', $this->flashed());
        $this->assertFalse($controller->isActive());
        $this->assertStringContainsString('terminated', $this->panel->statusText(), 'the panel reflects the failed spawn, not a lingering starting state');
        $this->client->emit('stopped', ['reason' => 'breakpoint', 'threadId' => 1]);
        $this->assertSame([], $this->opened, 'a straggler stop after a failed spawn is ignored');

        $controller = $this->controller();
        $controller->startForCurrentFile();
        $this->client->respond('initialize', [], 'bad handshake');
        $this->assertStringContainsString('Could not start the debug adapter — initialize failed: bad handshake', $this->flashed());
        $this->assertFalse($controller->isActive());
        $this->assertStringContainsString('terminated', $this->panel->statusText(), 'onError delegates to onTerminated to reset the panel too');
    }

    public function testTerminatedClearsUiState(): void
    {
        $controller = $this->controller();
        $this->pauseAtBreakpoint($controller);
        $this->assertStringContainsString('▶', \implode("\n", $this->editor->render(new RenderContext(80, 8))));

        $requested = $this->requestedRenderSince(function (): void {
            $this->client->emit('terminated', []);
        });

        $this->assertTrue($requested);
        $this->assertStringNotContainsString('▶', \implode("\n", $this->editor->render(new RenderContext(80, 8))), 'the debug-line marker clears on termination');
        $this->assertStringContainsString('terminated', $this->panel->statusText());

        // A stale stopped-state must not leak back in through a decoration re-apply.
        $controller->applyEditorDecorations('/p/prog.php');
        $this->assertStringNotContainsString('▶', \implode("\n", $this->editor->render(new RenderContext(80, 8))), 'a stale stopped line must not reappear via a decoration re-apply');
    }

    public function testStartForCurrentFileReusesTheDebugTerminalSessionAndResetsPanelState(): void
    {
        $controller = $this->controller();
        $controller->addWatch();
        foreach (\str_split('$x') as $ch) {
            $this->editor->handleInput($ch);
        }
        $this->editor->handleInput("\r");

        $this->pauseAtBreakpoint($controller);
        $debugSession = $this->sessions->find('debug');
        $this->assertInstanceOf(TerminalSession::class, $debugSession);
        $this->client->emit('output', ['category' => 'stdout', 'output' => "hello\n"]);
        $this->assertGreaterThan(1, $debugSession->buffer->lineCount(), 'output landed in the buffer');

        // Another tab steals activation in between: relaunch must claim it back.
        $shell = $this->sessions->create('shell:1', 'shell');
        $this->sessions->makeActive($shell);

        // A second watch is added between sessions, so a skipped re-seed
        // (setWatches not called again) would be visibly incomplete, not
        // just "same as before".
        $controller->addWatch();
        foreach (\str_split('$y') as $ch) {
            $this->editor->handleInput($ch);
        }
        $this->editor->handleInput("\r");

        $requested = $this->requestedRenderSince(static function () use ($controller): void {
            $controller->restart(); // no native support (see pauseAtBreakpoint's default capabilities): stop + relaunch
        });

        $this->assertTrue($requested);
        $this->assertSame(
            [$debugSession],
            \array_values(\array_filter($this->sessions->sessions, static fn (object $s): bool => 'debug' === $s->id)),
            'the same debug terminal session is reused, not duplicated',
        );
        $this->assertSame(1, $debugSession->buffer->lineCount(), 'a fresh launch clears stale output');
        $this->assertSame($debugSession, $this->sessions->active(), 'the debug session becomes active again');
        $this->assertStringContainsString('prog.php', $debugSession->title);

        // Before the panel opens (which resets its DebugState to Starting),
        // it was left at Paused by the first session — a skipped open() would
        // leave that stale text in place.
        $this->assertStringContainsString('starting', $this->panel->statusText(), 'the panel resets to the fresh session, not the old paused one');
        $this->assertTrue($this->panel->isVisible());
        $panelScreen = \implode("\n", \array_map(AnsiUtils::stripAnsiCodes(...), $this->panel->render(new RenderContext(100, 14))));
        $this->assertStringContainsString('$x', $panelScreen, 'the stored watches reseed the freshly opened panel');
        $this->assertStringContainsString('$y', $panelScreen, 'including the one added since the first session opened');

        // The debug-line marker from the first session's stop must not leak
        // into the fresh session before it has stopped anywhere of its own.
        $controller->applyEditorDecorations('/p/prog.php');
        $this->assertStringNotContainsString('▶', \implode("\n", $this->editor->render(new RenderContext(80, 8))), 'no stale stopped-line from the previous session');
    }

    public function testTheStopASKSTheServerAboutTheTOPFramesVariables(): void
    {
        // The wire, not the pieces: the stop has to reach the LSP with the
        // frame the debugger actually stopped in. `$currentFrameId` is set by
        // loadScopes AFTER this runs, so reading it there would ask about the
        // PREVIOUS stop — one frame behind, every time.
        $controller = $this->controller(lsp: $this->lspPool(['inlineValueProvider' => true]));

        $this->pauseAtBreakpoint($controller, line: 2);

        $asked = \array_values(\array_filter($this->lspClient->requests, static fn (array $r): bool => 'textDocument/inlineValue' === $r['method']));
        $this->assertCount(1, $asked, 'one request per stop');
        $this->assertSame(1, $asked[0]['params']['context']['frameId'], 'the TOP frame, which is #0');
        $this->assertSame(2, $asked[0]['params']['context']['stoppedLocation']['start']['line']);
        // A window either side of the stop, so a scroll after stopping does not
        // need a second round-trip.
        $this->assertSame(0, $asked[0]['params']['range']['start']['line'], 'clamped at the top of the file');
        $this->assertSame(42, $asked[0]['params']['range']['end']['line']);

        // And the other half: the adapter's variables reach the join, so
        // answering the request paints.
        $asked[0]['respond']([[
            'range' => ['start' => ['line' => 2, 'character' => 0], 'end' => ['line' => 2, 'character' => 2]],
            'variableName' => '$x',
        ]]);
        $this->assertSame([2 => '$x = 42'], $this->editor->decorations->debugValues);
    }

    public function testCONTINUINGTakesTheInlineValuesOffTheScreen(): void
    {
        // They were facts about a frame that is running again, and left on
        // screen they look live.
        $controller = $this->controller();
        $this->editor->decorations->setDebugValues([2 => '$x = 42']);

        $controller->onContinued();

        $this->assertSame([], $this->editor->decorations->debugValues);
    }

    public function testTERMINATINGTakesThemTooEvenWithoutAContinue(): void
    {
        $controller = $this->controller();
        $this->editor->decorations->setDebugValues([2 => '$x = 42']);

        $controller->onTerminated();

        $this->assertSame([], $this->editor->decorations->debugValues);
    }

    public function testStoppedMarshalsEditorPanelStatusAndFrameJumps(): void
    {
        $controller = $this->controller();
        $this->assertSame([], $controller->statusCells(), 'idle → no segment');

        $this->pauseAtBreakpoint($controller, line: 2);
        $this->assertSame(['/p/prog.php'], $this->opened, 'stop opened the file through the choke point');
        $this->assertSame(2, $this->editor->getDocument()->cursorLine);
        $this->assertSame(0, $this->editor->getDocument()->cursorCol, 'the stop jumps to the line start');
        $this->assertStringContainsString('▶', \implode("\n", $this->editor->render(new RenderContext(80, 8))), 'the stopped-line decoration was applied');
        $this->assertTrue($controller->isPaused());
        $this->assertSame('⏸ prog.php:3', $controller->statusCells()[0]->text);
        $this->assertSame('The debugger is paused at this line', $controller->statusCells()[0]->tooltip, 'the ⏸ in words');
        $this->assertStringContainsString('paused at prog.php:3', $this->panel->statusText(), 'the panel gets its own location string, independent of the status cell');
        $this->assertSame(2, $this->statusEvents, 'start and pause each dispatched DebugStatusChanged');

        $panelScreen = \implode("\n", \array_map(AnsiUtils::stripAnsiCodes(...), $this->panel->render(new RenderContext(100, 14))));
        $this->assertStringContainsString('#0 {main}  prog.php:3', $panelScreen, 'frames display relative paths');
        $this->assertStringContainsString('$x = 42', $panelScreen);

        // Expanding a variable pulls its children through the live session.
        $this->client->responses['variables'] = [['variables' => [['name' => '0', 'value' => "'a'", 'variablesReference' => 0]]], null];
        // Rows: 0 Frames, 1 #0, 2 #1, 3 Variables, 4 Locals, 5 $x.
        for ($i = 0; $i < 5; ++$i) {
            $this->panel->handleInput("\x1b[B");
        }
        $this->panel->handleInput("\r");
        $this->assertStringContainsString("0 = 'a'", \implode("\n", \array_map(AnsiUtils::stripAnsiCodes(...), $this->panel->render(new RenderContext(100, 14)))));

        // Clicking the second frame jumps to its (absolute) source.
        $requested = $this->requestedRenderSince(function (): void {
            $this->panel->handleClick(2); // Frames + #0 → rel 2 is #1 (no header row)
        });
        $this->assertSame('/p/other.php', \end($this->opened));
        $this->assertSame(0, $this->editor->getDocument()->cursorLine);
        $this->assertSame(0, $this->editor->getDocument()->cursorCol, 'the frame jump lands on the line start');
        $this->assertTrue($requested, 'a frame jump requests a render');

        // A stale frame row the controller no longer knows is inert.
        $openedBefore = \count($this->opened);
        $this->panel->setFrames([['id' => 99, 'name' => 'stale', 'path' => 'x.php', 'line' => 0]]);
        $this->panel->handleClick(1); // Frames → rel 1 is the stale frame (no header row)
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
        $this->assertSame('▶ debug', $controller->statusCells()[0]->text, 'running segment');
        $this->assertSame('The debugger is running', $controller->statusCells()[0]->tooltip);

        $controller->stop();
        $this->assertFalse($controller->isActive());
        $this->assertSame([], $controller->statusCells());
    }

    public function testStoppedWithAnEmptyStackStillReportsThePause(): void
    {
        $controller = $this->controller();
        $this->client->responses['stackTrace'] = [[], 'no stack available'];
        $controller->startForCurrentFile();
        $this->client->respond('initialize', []);
        $this->client->respond('launch');
        $statusEventsBefore = $this->statusEvents;
        $requested = $this->requestedRenderSince(function (): void {
            $this->client->emit('stopped', ['reason' => 'exception', 'threadId' => 1]);
        });

        $this->assertTrue($controller->isPaused());
        $this->assertTrue($requested, 'the pause requests a render even without a usable frame');
        $this->assertGreaterThan($statusEventsBefore, $this->statusEvents, 'the pause still announces itself on the bus');
        $this->assertStringContainsString('paused', $this->panel->statusText(), 'the dock-strip status reports the pause');
        $this->assertSame([], $this->opened, 'nothing to open without a frame');

        // The remaining status transitions announce themselves on the bus too.
        $events = $this->statusEvents;
        $this->client->emit('continued', []);
        $this->assertSame($events + 1, $this->statusEvents, 'resume dispatched DebugStatusChanged');
        $this->client->emit('terminated', []);
        $this->assertSame($events + 2, $this->statusEvents, 'termination dispatched DebugStatusChanged');
    }

    public function testContinuedClearsStoppedStateAndTheDebugLineMarker(): void
    {
        $controller = $this->controller();
        $this->pauseAtBreakpoint($controller);
        $this->assertStringContainsString('▶', \implode("\n", $this->editor->render(new RenderContext(80, 8))));

        $requested = $this->requestedRenderSince(function (): void {
            $this->client->emit('continued', []);
        });

        $this->assertTrue($requested);
        $this->assertStringNotContainsString('▶', \implode("\n", $this->editor->render(new RenderContext(80, 8))), 'the debug-line marker clears on resume');
        $this->assertStringContainsString('running', $this->panel->statusText());

        // A stale stopped-state must not leak back in through a decoration re-apply.
        $controller->applyEditorDecorations('/p/prog.php');
        $this->assertStringNotContainsString('▶', \implode("\n", $this->editor->render(new RenderContext(80, 8))), 'a stale stopped line must not reappear via a decoration re-apply');
    }

    public function testStoppedWithATopFrameLackingAPathStillShowsTheFullFrameList(): void
    {
        $controller = $this->controller();
        $this->client->responses['stackTrace'] = [
            [
                'stackFrames' => [
                    ['id' => 1, 'name' => '{closure}', 'source' => ['path' => ''], 'line' => 0], // native/internal: no path
                    ['id' => 2, 'name' => 'main', 'source' => ['path' => '/p/prog.php'], 'line' => 3],
                ],
            ],
            null,
        ];
        $controller->startForCurrentFile();
        $this->client->respond('initialize', []);
        $this->client->respond('launch');
        $statusEventsBefore = $this->statusEvents;
        $requested = $this->requestedRenderSince(function (): void {
            $this->client->emit('stopped', ['reason' => 'breakpoint', 'threadId' => 1]);
        });

        $this->assertTrue($controller->isPaused());
        $this->assertSame([], $this->opened, 'no usable path on the top frame — nothing opens');
        $this->assertTrue($requested);
        $this->assertGreaterThan($statusEventsBefore, $this->statusEvents, 'the pause still announces itself on the bus');
        $panelScreen = \implode("\n", \array_map(AnsiUtils::stripAnsiCodes(...), $this->panel->render(new RenderContext(100, 14))));
        $this->assertStringContainsString('{closure}', $panelScreen, 'the frame list still renders even without a usable top frame');
        $this->assertStringContainsString('main', $panelScreen);

        // Clicking the pathless frame itself is a no-op — nothing to jump to.
        $this->panel->handleClick(1); // Frames → rel 1 is #0 ({closure}, no header row)
        $this->assertSame([], $this->opened, 'a frame with no path is never opened');
    }

    public function testScopeWithoutAVariablesReferenceIsNotFetched(): void
    {
        $controller = $this->controller();
        $this->client->responses['stackTrace'] = [
            ['stackFrames' => [['id' => 1, 'name' => '{main}', 'source' => ['path' => '/p/prog.php'], 'line' => 3]]],
            null,
        ];
        // The skippable scope (no variablesReference) comes FIRST: skipping it
        // must not abort the loop before the real scope after it is reached.
        $this->client->responses['scopes'] = [
            [
                'scopes' => [
                    ['name' => 'Registers', 'variablesReference' => 0],
                    ['name' => 'Locals', 'variablesReference' => 1000],
                ],
            ],
            null,
        ];
        $this->client->responses['variables'] = [['variables' => [['name' => '$x', 'value' => '42', 'variablesReference' => 0]]], null];

        $controller->startForCurrentFile();
        $this->client->respond('initialize', ['supportsConfigurationDoneRequest' => true]);
        $this->client->respond('launch');
        $requested = $this->requestedRenderSince(function (): void {
            $this->client->emit('stopped', ['reason' => 'breakpoint', 'threadId' => 1]);
        });

        $this->assertTrue($controller->isPaused());
        $this->assertTrue($requested, 'the scopes callback still requests a render');
        $variableRequests = \array_filter(
            $this->client->requests,
            static fn (array $r): bool => 'variables' === $r['command'],
        );
        $this->assertCount(1, $variableRequests, 'only the ref>0 scope requested its variables');
        $this->assertSame(1000, \array_first($variableRequests)['arguments']['variablesReference']);
    }

    public function testBreakpointTogglesSyncTheLiveSessionAndOtherFiles(): void
    {
        $controller = $this->controller();
        $this->pauseAtBreakpoint($controller);

        $controller->toggleBreakpoint('/p/prog.php', 1);
        $this->assertSame([['line' => 2]], $this->client->argumentsOf('setBreakpoints')['breakpoints'], 'live session got the 1-based line');
        $this->assertStringContainsString('●', \implode("\n", $this->editor->render(new RenderContext(80, 8))));

        $controller->toggleBreakpoint('/p/elsewhere.php', 5);
        $this->assertSame('/p/elsewhere.php', $this->client->argumentsOf('setBreakpoints')['source']['path'], 'other files sync without touching the editor');

        // Re-applying decorations for another file clears this file's marks.
        $controller->applyEditorDecorations('/p/clean.php');
        $this->assertStringNotContainsString('●', \implode("\n", $this->editor->render(new RenderContext(80, 8))));
    }

    public function testEditBreakpointPromptsStoreConditionAndSyncLive(): void
    {
        $controller = $this->controller();
        $this->pauseAtBreakpoint($controller);
        $this->editor->jumpTo(1, 0); // off the stopped line — its ▶ outranks the breakpoint glyphs

        $controller->editBreakpointAtCaret();
        $this->assertSame($this->editor, $this->tui->getFocus(), 'the prompt is drivable even from a focused dock panel');
        foreach (\str_split('$x > 1') as $ch) {
            $this->editor->handleInput($ch);
        }
        $this->editor->handleInput("\r"); // condition submitted → hit-count prompt chains
        $this->editor->handleInput('3');
        $this->editor->handleInput("\r"); // hit-count submitted → log-message prompt chains
        $this->editor->handleInput("\r"); // empty log message → a plain conditional breakpoint

        $this->assertSame('/p/prog.php', $this->client->argumentsOf('setBreakpoints')['source']['path']);
        $this->assertStringContainsString('◆', \implode("\n", $this->editor->render(new RenderContext(80, 8))), 'the conditional breakpoint renders a diamond');

        // Re-editing prefills the real stored values, not blanks. Focus is
        // already on the editor here, so setFocus() below is a no-op — this
        // isolates the method's own trailing requestRender() call.
        $opened = $this->requestedRenderSince(static function () use ($controller): void {
            $controller->editBreakpointAtCaret();
        });
        $this->assertTrue($opened, 're-opening the condition prompt requests a render');
        $conditionPrompt = \implode("\n", \array_map(AnsiUtils::stripAnsiCodes(...), $this->editor->layoutPickers([0, 0, 20, 100])[0]['rows']));
        $this->assertStringContainsString('$x > 1', $conditionPrompt, 'the condition prompt prefills the stored condition');
        foreach (\range(1, end: 12) as $_) {
            $this->editor->handleInput("\x7f");
        }
        $this->editor->handleInput("\r");
        $hitConditionPrompt = \implode("\n", \array_map(AnsiUtils::stripAnsiCodes(...), $this->editor->layoutPickers([0, 0, 20, 100])[0]['rows']));
        $this->assertStringContainsString('3', $hitConditionPrompt, 'the hit-count prompt prefills the stored hit condition');
        foreach (\range(1, end: 6) as $_) {
            $this->editor->handleInput("\x7f");
        }
        $this->editor->handleInput("\r");
        $this->editor->handleInput("\r"); // the (empty) log-message prompt
        $screen = \implode("\n", $this->editor->render(new RenderContext(80, 8)));
        $this->assertStringNotContainsString('◆', $screen, 'cleared conditions drop the diamond');
        $this->assertStringContainsString('●', $screen, 'the breakpoint itself survives');

        $controllerWithoutFile = $this->controller(withFile: Presence::Without);
        $controllerWithoutFile->editBreakpointAtCaret();
        $this->assertStringContainsString('Nothing to debug', $this->flashed());
    }

    public function testLogpointRendersAHollowDiamondAndShipsTheLogMessage(): void
    {
        $controller = $this->controller();
        $this->pauseAtBreakpoint($controller);
        $this->editor->jumpTo(1, 0); // off the stopped line

        $controller->editBreakpointAtCaret();
        $this->editor->handleInput("\r"); // no condition
        $this->editor->handleInput("\r"); // no hit-count
        foreach (\str_split('x={$x}') as $ch) {
            $this->editor->handleInput($ch);
        }
        $this->editor->handleInput("\r"); // a log message → this is a logpoint

        $screen = \implode("\n", $this->editor->render(new RenderContext(80, 8)));
        $this->assertStringContainsString('◇', $screen, 'a logpoint renders a hollow diamond');
        $this->assertStringNotContainsString('◆', $screen, 'and not the conditional diamond');

        // Re-editing prefills the stored log message, not a blank.
        $controller->editBreakpointAtCaret();
        $this->editor->handleInput("\r"); // no condition
        $this->editor->handleInput("\r"); // no hit-count
        $logPrompt = \implode("\n", \array_map(AnsiUtils::stripAnsiCodes(...), $this->editor->layoutPickers([0, 0, 20, 100])[0]['rows']));
        $this->assertStringContainsString('x={$x}', $logPrompt, 'the log-message prompt prefills the stored message');
        $this->editor->handleInput("\x1b"); // leave it as-is
    }

    public function testBreakpointConditionFieldsAreTrimmedBeforeStorage(): void
    {
        $controller = $this->controller();
        $this->pauseAtBreakpoint($controller, capabilities: [
            'supportsConditionalBreakpoints' => true,
            'supportsHitConditionalBreakpoints' => true,
            'supportsLogPoints' => true,
        ]);
        $this->editor->jumpTo(1, 0);

        // Padded-but-meaningful values must be stored trimmed.
        $controller->editBreakpointAtCaret();
        foreach (\str_split('  $x > 1  ') as $ch) {
            $this->editor->handleInput($ch);
        }
        $this->editor->handleInput("\r");
        foreach (\str_split('   ') as $ch) { // whitespace-only hit-count: must clear to null, not ''
            $this->editor->handleInput($ch);
        }
        $this->editor->handleInput("\r");
        foreach (\str_split('  hit  ') as $ch) {
            $this->editor->handleInput($ch);
        }
        $this->editor->handleInput("\r");

        $wire = $this->client->argumentsOf('setBreakpoints')['breakpoints'];
        $this->assertSame('$x > 1', $wire[0]['condition'] ?? null, 'surrounding whitespace is trimmed, not stored verbatim');
        $this->assertArrayNotHasKey('hitCondition', $wire[0], 'a whitespace-only hit condition clears the field, it is not stored as an empty string');
        $this->assertSame('hit', $wire[0]['logMessage'] ?? null, 'surrounding whitespace is trimmed, not stored verbatim');

        // Whitespace-only values must clear the field entirely (null), not store ''.
        $controller->editBreakpointAtCaret();
        foreach (\range(1, end: 6) as $_) { // clear the '$x > 1' prefill
            $this->editor->handleInput("\x7f");
        }
        foreach (\str_split('   ') as $ch) {
            $this->editor->handleInput($ch);
        }
        $this->editor->handleInput("\r"); // whitespace-only condition
        foreach (\str_split(' 5 ') as $ch) {
            $this->editor->handleInput($ch);
        }
        $this->editor->handleInput("\r"); // padded hit-count
        foreach (\range(1, end: 3) as $_) { // clear the 'hit' prefill
            $this->editor->handleInput("\x7f");
        }
        foreach (\str_split('   ') as $ch) { // whitespace-only log message: must clear to null, not ''
            $this->editor->handleInput($ch);
        }
        $this->editor->handleInput("\r");

        $wire = $this->client->argumentsOf('setBreakpoints')['breakpoints'];
        $this->assertArrayNotHasKey('condition', $wire[0], 'a whitespace-only condition clears the field, it is not stored as an empty string');
        $this->assertSame('5', $wire[0]['hitCondition'] ?? null, 'surrounding whitespace is trimmed on the hit condition too');
        $this->assertArrayNotHasKey('logMessage', $wire[0], 'a whitespace-only log message clears the field, it is not stored as an empty string');
    }

    public function testEditingAVariableFromThePanelSendsSetVariable(): void
    {
        $controller = $this->controller();
        $this->pauseAtBreakpoint($controller, capabilities: ['supportsSetVariable' => true]);
        $this->client->responses['setVariable'] = [['value' => '99'], null];

        // Panel rows: 0 Frames, 1 #0, 2 #1, 3 Variables, 4 Locals, 5 $x — step to $x.
        for ($i = 0; $i < 5; ++$i) {
            $this->panel->handleInput("\x1b[B");
        }
        $openRequested = $this->requestedRenderSince(function (): void {
            $this->panel->handleInput('e'); // edit → a value prompt opens, prefilled with '42'
        });
        $this->assertTrue($openRequested, 'opening the prompt requests a render');
        $this->assertSame($this->editor, $this->tui->getFocus(), 'the prompt is drivable: focus moved to the editor');
        $promptScreen = \implode("\n", \array_map(AnsiUtils::stripAnsiCodes(...), $this->editor->layoutPickers([0, 0, 20, 100])[0]['rows']));
        $this->assertStringContainsString('Set $x to', $promptScreen, 'the prompt title names the variable');

        $this->editor->handleInput("\x7f"); // clear the prefill
        $this->editor->handleInput("\x7f");
        foreach (\str_split('99') as $ch) {
            $this->editor->handleInput($ch);
        }

        // The refetch response differs from the prefill so a missing re-fetch is visible in the panel.
        $this->client->responses['variables'] = [['variables' => [['name' => '$x', 'value' => '99', 'variablesReference' => 0]]], null];
        $submitRequested = $this->requestedRenderSince(function (): void {
            $this->editor->handleInput("\r");
        });

        $this->assertSame(
            ['variablesReference' => 1000, 'name' => '$x', 'value' => '99'],
            $this->client->argumentsOf('setVariable'),
            'the edit reaches the adapter with the container reference, name and new value',
        );
        $this->assertTrue($submitRequested, 'delivering the new value requests a render');
        $panelScreen = \implode("\n", \array_map(AnsiUtils::stripAnsiCodes(...), $this->panel->render(new RenderContext(100, 14))));
        $this->assertStringContainsString('$x = 99', $panelScreen, 'the panel re-fetched the container to reflect the adapter-stored value');

        // Editing again: focus is already on the editor from the submission
        // above, so setFocus() is a no-op — this isolates editVariable()'s own
        // trailing requestRender() (fired on open, before any further input).
        $this->assertSame($this->editor, $this->tui->getFocus(), 'precondition: focus is still on the editor after submitting');
        $reopenRequested = $this->requestedRenderSince(function (): void {
            $this->panel->handleInput('e');
        });
        $this->assertTrue($reopenRequested, 're-opening the prompt requests a render');
    }

    public function testRestartUsesTheAdapterNativeRestartWhenSupported(): void
    {
        $controller = $this->controller();
        $this->pauseAtBreakpoint($controller, capabilities: ['supportsRestartRequest' => true]);

        $requested = $this->requestedRenderSince(static function () use ($controller): void {
            $controller->restart();
        });
        $this->assertContains('restart', $this->client->commands(), 'the adapter restarts in place');
        $this->assertTrue($requested);
        $this->assertNotContains('disconnect', $this->client->commands(), 'the native path does not also fall through to stop+relaunch');
        $this->assertCount(1, \array_filter($this->client->commands(), static fn (string $c): bool => 'initialize' === $c), 'no second session was spun up');
    }

    public function testRestartFallsBackToStopAndRelaunchWithoutNativeSupport(): void
    {
        $controller = $this->controller();
        $this->pauseAtBreakpoint($controller); // no supportsRestartRequest

        $controller->restart();
        // The old session disconnected and a fresh launch began.
        $this->assertContains('disconnect', $this->client->commands(), 'the old session was ended');
        $this->assertCount(2, \array_filter($this->client->commands(), static fn (string $c): bool => 'initialize' === $c), 'a new session was launched');
    }

    public function testRestartWithoutASessionFlashes(): void
    {
        $controller = $this->controller();
        $controller->restart();
        $this->assertStringContainsString('No debug session to restart', $this->flashed());
    }

    public function testStepAndStopCommandsAreInertWithoutASession(): void
    {
        // None of these guard on isActive()/isPaused() in the controller —
        // they delegate straight to the (possibly null) session. A session
        // that was never started (or a fresh controller) must not crash.
        $controller = $this->controller();

        $controller->stepOver();
        $controller->stepIn();
        $controller->stepOut();
        $controller->continueRun();
        $controller->stop();

        $this->assertSame([], $this->client->requests, 'nothing was ever sent — there is no session to send to');
    }

    public function testEditingAVariableFlashesTheAdapterError(): void
    {
        $controller = $this->controller();
        $this->pauseAtBreakpoint($controller, capabilities: ['supportsSetVariable' => true]);
        $this->client->responses['setVariable'] = [[], 'invalid value'];

        for ($i = 0; $i < 5; ++$i) {
            $this->panel->handleInput("\x1b[B");
        }
        $this->panel->handleInput('e');
        foreach (\str_split('bad') as $ch) {
            $this->editor->handleInput($ch);
        }
        $variablesRequestsBefore = \count(\array_filter($this->client->commands(), static fn (string $c): bool => 'variables' === $c));
        $this->editor->handleInput("\r");

        $this->assertStringContainsString('invalid value', $this->flashed(), 'the adapter error reaches the status bar');
        $this->assertCount(
            $variablesRequestsBefore,
            \array_filter($this->client->commands(), static fn (string $c): bool => 'variables' === $c),
            'an error does not re-fetch the container',
        );
    }

    public function testEditingAVariableFlashesWhenTheAdapterCannot(): void
    {
        $controller = $this->controller();
        $this->pauseAtBreakpoint($controller); // no supportsSetVariable

        for ($i = 0; $i < 5; ++$i) {
            $this->panel->handleInput("\x1b[B");
        }
        $this->panel->handleInput('e');

        $this->assertStringContainsString('cannot edit variables', $this->flashed());
        $this->assertSame([], $this->client->argumentsOf('setVariable'), 'nothing is sent');
        $this->assertFalse(EditorState::promptOpen($this->editor), 'the guard stops before a prompt ever opens');
    }

    public function testWatchesEvaluatePerStopAndRemoveThroughThePanel(): void
    {
        $controller = $this->controller();
        $this->client->responses['evaluate'] = [['result' => '42', 'variablesReference' => 0], null];
        $this->pauseAtBreakpoint($controller);

        $controller->addWatch();
        $this->assertSame($this->editor, $this->tui->getFocus(), 'the prompt is drivable from a focused dock panel too');
        foreach (\str_split('  $x  ') as $ch) { // padded: trim() must strip it before it reaches evaluate()
            $this->editor->handleInput($ch);
        }
        $this->editor->handleInput("\r");
        $this->assertSame(['expression' => '$x', 'context' => 'watch', 'frameId' => 1], $this->client->argumentsOf('evaluate'), 'a new watch evaluates against the paused frame, trimmed');
        $panelScreen = \implode("\n", \array_map(AnsiUtils::stripAnsiCodes(...), $this->panel->render(new RenderContext(100, 14))));
        $this->assertStringContainsString('$x = 42', $panelScreen);

        // An empty submission adds nothing; the blank prompt is a no-op. Focus
        // is already on the editor here, so setFocus() below is a no-op —
        // this isolates the method's own trailing requestRender() call.
        $openRequested = $this->requestedRenderSince(static function () use ($controller): void {
            $controller->addWatch();
        });
        $this->assertTrue($openRequested, 're-opening the watch prompt requests a render');
        $this->editor->handleInput("\r");

        // A frame jump re-evaluates against the new frame; a different result
        // proves the panel actually re-fetched, not just re-rendered stale state.
        $this->client->responses['evaluate'] = [['result' => '7', 'variablesReference' => 0], null];
        $evaluatesBefore = \count(\array_filter($this->client->commands(), static fn (string $c): bool => 'evaluate' === $c));
        $requested = $this->requestedRenderSince(function (): void {
            $this->panel->handleClick(2); // the #1 frame row (no header row)
        });
        $this->assertCount($evaluatesBefore + 1, \array_filter($this->client->commands(), static fn (string $c): bool => 'evaluate' === $c));
        $this->assertSame(['expression' => '$x', 'context' => 'watch', 'frameId' => 2], $this->client->argumentsOf('evaluate'));
        $this->assertTrue($requested, 'the re-evaluated watch result requests a render');
        $panelScreen = \implode("\n", \array_map(AnsiUtils::stripAnsiCodes(...), $this->panel->render(new RenderContext(100, 14))));
        $this->assertStringContainsString('$x = 7', $panelScreen, 'the panel reflects the re-evaluated result');

        // A second watch, so removing the first still leaves one to re-evaluate.
        $controller->addWatch();
        foreach (\str_split('$z') as $ch) {
            $this->editor->handleInput($ch);
        }
        $this->editor->handleInput("\r");

        // Removing $x through the panel: $z must be re-evaluated too, proving
        // the removal re-runs reevaluateWatches() rather than just re-rendering.
        $this->client->responses['evaluate'] = [['result' => '99', 'variablesReference' => 0], null];
        $evaluatesBefore = \count(\array_filter($this->client->commands(), static fn (string $c): bool => 'evaluate' === $c));
        // Frame click left the selection on row 2 (#1) — two downs reach the
        // $x row (3 Watches, 4 $x, 5 $z).
        for ($i = 0; $i < 2; ++$i) {
            $this->panel->handleInput("\x1b[B");
        }
        $this->panel->handleInput("\x1b[3~");
        $this->assertGreaterThan($evaluatesBefore, \count(\array_filter($this->client->commands(), static fn (string $c): bool => 'evaluate' === $c)), 'removing one watch re-evaluates the ones left');
        $panelScreen = \implode("\n", \array_map(AnsiUtils::stripAnsiCodes(...), $this->panel->render(new RenderContext(100, 14))));
        $this->assertStringContainsString('$z = 99', $panelScreen, 'the remaining watch reflects the fresh evaluation');

        // Removing the last watch drops the section entirely.
        $this->panel->handleInput("\x1b[3~");
        $panelScreen = \implode("\n", \array_map(AnsiUtils::stripAnsiCodes(...), $this->panel->render(new RenderContext(100, 14))));
        $this->assertStringNotContainsString('Watches', $panelScreen, 'the last watch removed drops the section');
    }

    public function testAStopSurfacesTheDebugTabAgain(): void
    {
        $controller = $this->controller();
        $this->pauseAtBreakpoint($controller);
        $controller->addWatch();
        $this->editor->handleInput('$');
        $this->editor->handleInput('x');
        $this->editor->handleInput("\r");

        // Concealed behind another dock tab: the next stop reveals it.
        $this->panel->conceal();
        $this->client->emit('stopped', ['reason' => 'step', 'threadId' => 1]);
        $this->assertTrue($this->panel->isVisible(), 'a stop reveals the concealed Debug tab');

        // Closed outright (the strip ×): the next stop re-opens it, watches reseeded.
        $this->panel->close();
        $this->client->emit('stopped', ['reason' => 'step', 'threadId' => 1]);
        $this->assertTrue($this->panel->isVisible(), 'a stop re-opens a closed Debug tab');
        $screen = \implode("\n", \array_map(AnsiUtils::stripAnsiCodes(...), $this->panel->render(new RenderContext(100, 14))));
        $this->assertStringContainsString('Watches', $screen, 'the stored watches reseed the re-opened panel');
        $this->assertStringContainsString('$x', $screen);
        $this->assertSame('prog.php', $this->panel->getTitle(), 'the re-opened tab keeps the launched file title');
    }

    public function testOutputRefreshesTheVisibleTerminalPanelOnlyWhenItsSessionIsActive(): void
    {
        $controller = $this->controller();
        $this->pauseAtBreakpoint($controller);
        $debugSession = $this->sessions->find('debug');
        $this->assertInstanceOf(TerminalSession::class, $debugSession);
        $linesBefore = $debugSession->buffer->lineCount();

        Probe::set($this->terminalPanel, 'scrollBack', 3);
        $requested = $this->requestedRenderSince(function (): void {
            $this->client->emit('output', ['category' => 'stdout', 'output' => "more\n"]);
        });
        $this->assertTrue($requested);
        $this->assertGreaterThan($linesBefore, $debugSession->buffer->lineCount(), 'the output landed in the debug session buffer');
        $this->assertSame(0, Probe::get($this->terminalPanel, 'scrollBack'), 'output while the debug session is active snaps the panel back to the bottom');

        // Switch to another session: further output for the (still running, but
        // no longer active) debug session must not touch the visible panel.
        $shell = $this->sessions->create('shell:1', 'shell');
        $this->sessions->makeActive($shell);
        Probe::set($this->terminalPanel, 'scrollBack', 3);
        $this->client->emit('output', ['category' => 'stdout', 'output' => "ignored\n"]);
        $this->assertSame(3, Probe::get($this->terminalPanel, 'scrollBack'), 'a background session refreshing does not move the visible panel');

        // Closing the debug tab outright: output must still be harmless (no session to feed).
        $this->sessions->makeActive($debugSession);
        $index = \array_search($debugSession, $this->sessions->sessions, strict: true);
        $this->sessions->close($index);
        $this->client->emit('output', ['category' => 'stdout', 'output' => "gone\n"]);
        $this->assertSame(3, Probe::get($this->terminalPanel, 'scrollBack'), 'no session — the panel is untouched');
    }

    public function testAddingAWatchWhileNotPausedDefersEvaluation(): void
    {
        $controller = $this->controller();
        $controller->addWatch();
        foreach (\str_split('$x') as $ch) {
            $this->editor->handleInput($ch);
        }
        $this->editor->handleInput("\r");
        $this->assertNotContains('evaluate', $this->client->commands(), 'no session — nothing to evaluate against');
    }

    public function testEvaluatePromptFlashesResultOrError(): void
    {
        $controller = $this->controller();
        $this->client->responses['evaluate'] = [['result' => "'porta'", 'variablesReference' => 0], null];
        $this->pauseAtBreakpoint($controller);

        $controller->evaluatePrompt();
        $this->assertSame($this->editor, $this->tui->getFocus(), 'the prompt is drivable from a focused dock panel too');
        foreach (\str_split('  $name  ') as $ch) { // padded: trim() must strip it before it reaches evaluate()
            $this->editor->handleInput($ch);
        }
        $this->editor->handleInput("\r");
        $this->assertSame(['expression' => '$name', 'context' => 'repl', 'frameId' => 1], $this->client->argumentsOf('evaluate'), 'trimmed');
        $this->assertStringContainsString("\$name = 'porta'", $this->flashed());

        $this->client->responses['evaluate'] = [[], 'oops'];
        $controller->evaluatePrompt();
        foreach (\str_split('$bad') as $ch) {
            $this->editor->handleInput($ch);
        }
        $this->editor->handleInput("\r");
        $this->assertStringContainsString('$bad = oops', $this->flashed());

        $controller->evaluatePrompt();
        $this->editor->handleInput("\r"); // empty expression: no request
        $this->assertStringContainsString('$bad = oops', $this->flashed(), 'flash unchanged');
    }

    public function testRunToCursorGuardsAndDelegation(): void
    {
        $controller = $this->controller();
        $controller->runToCursor();
        $this->assertStringContainsString('Run to cursor needs a paused debugger', $this->flashed());

        $this->pauseAtBreakpoint($controller, line: 2);
        $this->editor->getDocument()->setCursor(3, 0);
        $controller->runToCursor();
        $this->assertSame([['line' => 4]], $this->client->argumentsOf('setBreakpoints')['breakpoints'], 'the one-shot went to the wire');
        $this->assertContains('continue', $this->client->commands());
    }

    public function testRunToCursorWhilePausedWithNoOpenFileFlashes(): void
    {
        $controller = $this->controller();
        $this->pauseAtBreakpoint($controller);
        // The buffers were all closed while paused: the editor has no path.
        Probe::set($this->editor, 'filePath', null);

        $controller->runToCursor();
        $this->assertStringContainsString('Nothing to debug', $this->flashed());
        $this->assertNotContains('continue', $this->client->commands(), 'nothing ran');
    }

    public function testExceptionBreakpointsOverlayTogglesAndResyncs(): void
    {
        $controller = $this->controller();
        $controller->exceptionBreakpointsOverlay();
        $this->assertStringContainsString('No debugger session', $this->flashed());

        $this->pauseAtBreakpoint($controller);
        $controller->exceptionBreakpointsOverlay();
        $this->assertSame($this->editor, $this->tui->getFocus(), 'drivable from a focused dock panel too');
        $screen = \implode("\n", \array_map(AnsiUtils::stripAnsiCodes(...), $this->editor->layoutPickers([0, 0, 20, 100])[0]['rows']));
        $this->assertStringContainsString('[ ] Warnings', $screen);
        $this->assertStringContainsString('[✓] Uncaught Exceptions', $screen, 'the default-on filter renders checked');

        // Focus is already on the editor from the open above, so the re-open's
        // own setFocus() is a no-op — this isolates its trailing requestRender().
        $requested = $this->requestedRenderSince(function (): void {
            $this->editor->handleInput("\r"); // toggle Warnings on → re-sync + re-open
        });
        $this->assertTrue($requested, 're-opening the overlay after a toggle requests a render');
        $this->assertSame(['filters' => ['exception', 'warning']], $this->client->argumentsOf('setExceptionBreakpoints'));
        $screen = \implode("\n", \array_map(AnsiUtils::stripAnsiCodes(...), $this->editor->layoutPickers([0, 0, 20, 100])[0]['rows']));
        $this->assertStringContainsString('[✓] Warnings', $screen, 'the overlay re-opened with the new state');

        $this->editor->handleInput("\x1b[B");
        $this->editor->handleInput(' '); // Space, a checkbox's key: toggle Uncaught Exceptions off
        $this->assertSame(['filters' => ['warning']], $this->client->argumentsOf('setExceptionBreakpoints'));

        $this->editor->handleInput("\x1b"); // Escape leaves the loop
        $this->assertFalse(EditorState::pickerOpen($this->editor));
        $this->assertSame(['warning'], $this->filters->selection(), 'the selection persisted in the store');
    }

    public function testExceptionOverlayWithAFilterlessAdapterFlashes(): void
    {
        $controller = $this->controller();
        $this->client->responses['stackTrace'] = [['stackFrames' => []], null];
        $controller->startForCurrentFile();
        $this->client->respond('initialize', ['supportsConfigurationDoneRequest' => true]); // no filters advertised
        $this->client->respond('launch');
        $controller->exceptionBreakpointsOverlay();
        $this->assertStringContainsString('no exception breakpoint filters', $this->flashed());
        $this->assertFalse(EditorState::pickerOpen($this->editor), 'the guard stops before an empty overlay opens');
    }

    /**
     * The four step verbs say why they will not act.
     *
     * They were `$this->session?->stepOver()` and nothing more, so Run ▸ Step
     * Over with no debugger did absolutely nothing, in silence — while
     * `runToCursor()` and `restart()`, two rows away on the same menu, both
     * flashed. The KEY is `when`-gated and correctly falls through to the
     * focused widget; it is the MENU and the palette that reach the handler.
     *
     * @param \Closure(DebugController): void $step
     */
    #[DataProvider('stepVerbs')]
    public function testAStepVerbWithNoPausedDebuggerSaysSo(\Closure $step, string $command): void
    {
        $controller = $this->controller();

        $step($controller);

        $this->assertStringContainsString('No paused debugger to step', $this->flashed());
        $this->assertNotContains($command, $this->client->commands(), 'nothing reached the wire');
    }

    /** @return iterable<string, array{\Closure(DebugController): void, string}> */
    public static function stepVerbs(): iterable
    {
        yield 'step over' => [static fn (DebugController $c) => $c->stepOver(), 'next'];
        yield 'step into' => [static fn (DebugController $c) => $c->stepIn(), 'stepIn'];
        yield 'step out' => [static fn (DebugController $c) => $c->stepOut(), 'stepOut'];
        yield 'continue' => [static fn (DebugController $c) => $c->continueRun(), 'continue'];
    }

    /** And the control: paused, the same four verbs reach the adapter. */
    public function testTheSameStepVerbsActWhenTheDebuggerIsPaused(): void
    {
        $controller = $this->controller();
        $this->pauseAtBreakpoint($controller);

        $controller->stepOver();

        $this->assertContains('next', $this->client->commands(), 'paused, the step goes through');
        $this->assertStringNotContainsString('No paused debugger to step', $this->flashed());
    }

    /**
     * Evaluate refuses BEFORE it prompts.
     *
     * Find Action lists a command whether or not its `when` holds, and this
     * guard used to sit on the far side of the input: the prompt opened, the
     * user typed an expression, and the callback dropped it on the floor
     * without a word. The assertion that matters is the prompt never opening —
     * a guard that still prompts and then discards passes a flash-only check.
     */
    public function testEvaluateWithNoPausedDebuggerRefusesBeforeOpeningThePrompt(): void
    {
        $controller = $this->controller();

        $controller->evaluatePrompt();

        $this->assertStringContainsString('Evaluate needs a paused debugger', $this->flashed());
        $this->assertFalse(EditorState::promptOpen($this->editor), 'the guard stops before a prompt ever opens');
        $this->assertNotContains('evaluate', $this->client->commands());
    }

    public function testAPluginReadsAndStepsThePausedSessionThroughTheDebuggerFacade(): void
    {
        $controller = $this->controller();
        $debugger = TestRuns::create(debug: $controller)->debugger(); // as a plugin reaches it
        $delivered = null;
        $collect = static function (array $scopes) use (&$delivered): void {
            $delivered = $scopes;
        };
        $this->assertSame(['idle', null, []], [$debugger->status(), $debugger->stoppedAt(), $debugger->frames()]);
        $debugger->variables(1, $collect);
        $this->assertSame([], $delivered, 'no session: nothing to read');

        $this->pauseAtBreakpoint($controller);
        $this->assertSame('paused', $debugger->status());
        $this->assertSame(['path' => '/p/prog.php', 'line' => 2], $debugger->stoppedAt());
        $this->assertSame(
            [['id' => 1, 'name' => '{main}', 'path' => '/p/prog.php', 'line' => 2], ['id' => 2, 'name' => 'require', 'path' => '/p/other.php', 'line' => 0]],
            $debugger->frames(),
            'innermost first, absolute paths, 0-based lines',
        );

        // Every scope, in order, delivered once the LAST of them has arrived.
        $this->client->responses['scopes'] = [
            ['scopes' => [['name' => 'Locals', 'variablesReference' => 1000], ['name' => 'Registers', 'variablesReference' => 0], ['name' => 'Globals', 'variablesReference' => 2000]]],
            null,
        ];
        unset($this->client->responses['variables']);
        $delivered = null;
        $debugger->variables(1, $collect);
        $this->client->respond('variables', ['variables' => [['name' => '$x', 'value' => '42', 'variablesReference' => 1001]]]);
        $this->assertNull($delivered, 'one scope still to come');
        $this->client->respond('variables', ['variables' => [['name' => '$argv', 'value' => 'array(1)', 'variablesReference' => 7]]]);
        $this->assertSame(
            [
                ['scope' => 'Locals', 'variables' => [['name' => '$x', 'value' => '42', 'reference' => 1001]]],
                ['scope' => 'Registers', 'variables' => []],
                ['scope' => 'Globals', 'variables' => [['name' => '$argv', 'value' => 'array(1)', 'reference' => 7]]],
            ],
            $delivered,
        );
        // What a variable holds, by its reference; a reference of 0 holds nothing, asked of nobody.
        $held = null;
        $hold = static function (array $variables) use (&$held): void {
            $held = $variables;
        };
        $debugger->children(7, $hold);
        $this->client->respond('variables', ['variables' => [['name' => '0', 'value' => "'a'", 'variablesReference' => 0]]]);
        $this->assertSame([['name' => '0', 'value' => "'a'", 'reference' => 0]], $held);
        $asked = \count($this->client->requests);
        $debugger->children(0, $hold);
        $this->assertSame([], $held);
        $this->assertCount($asked, $this->client->requests, 'nothing to open: the adapter not asked');

        // Nothing to fetch: at once.
        $this->client->responses['scopes'] = [['scopes' => [['name' => 'Registers', 'variablesReference' => 0]]], null];
        $debugger->variables(1, $collect);
        $this->assertSame([['scope' => 'Registers', 'variables' => []]], $delivered);

        // The step verbs are the Run menu's: each resumes, so each waits for a stop.
        foreach ([$debugger->stepOver(...), $debugger->stepIn(...), $debugger->stepOut(...), $debugger->continue(...)] as $verb) {
            $verb();
            $this->client->emit('stopped', ['reason' => 'step', 'threadId' => 1]);
        }
        $this->assertSame(['next', 'stepIn', 'stepOut', 'continue'], \array_values(\array_filter($this->client->commands(), static fn (string $c): bool => \in_array(
            $c,
            ['next', 'stepIn', 'stepOut', 'continue'],
            strict: true,
        ))));
        $this->client->emit('continued', []);
        $this->assertSame(['running', null, []], [$debugger->status(), $debugger->stoppedAt(), $debugger->frames()]);
        $delivered = null;
        $debugger->variables(1, $collect);
        $this->assertSame([], $delivered, 'running: no frame to read, the adapter not asked');
        $held = null;
        $debugger->children(7, $hold);
        $this->assertSame([], $held, 'running: nothing held to open');
        $debugger->stop();
        $this->assertContains('disconnect', $this->client->commands());
    }

    public function testPauseStopsARunningProgramWhereItIsAndOnlyThen(): void
    {
        $controller = $this->controller();
        $debugger = TestRuns::create(debug: $controller)->debugger();
        $debugger->pause();
        $this->assertStringContainsString('No running debugger to pause', $this->flashed(), 'no session');

        // Starting: not running yet, so nothing is asked — and nothing said.
        $this->client->responses['stackTrace'] = [['stackFrames' => [['id' => 1, 'name' => '{main}', 'source' => ['path' => '/p/prog.php'], 'line' => 3]]], null];
        $this->client->responses['scopes'] = [['scopes' => []], null];
        $controller->startForCurrentFile();
        $debugger->pause();
        $this->assertNotContains('pause', $this->client->commands(), 'still starting');

        $this->client->respond('initialize', ['supportsConfigurationDoneRequest' => true]);
        $this->client->respond('launch');
        $this->assertSame('running', $debugger->status());
        $debugger->pause();
        $this->assertContains('pause', $this->client->commands());
        $this->client->emit('stopped', ['reason' => 'pause', 'threadId' => 1]);
        $this->assertSame(['paused', ['path' => '/p/prog.php', 'line' => 2]], [$debugger->status(), $debugger->stoppedAt()]);

        // The first refusal taken down, so this one is seen to be said again.
        $this->statusBar->withdrawFlash('No running debugger to pause');
        $this->assertStringNotContainsString('No running debugger to pause', $this->flashed());
        $asked = \count($this->client->requests);
        $debugger->pause();
        $this->assertCount($asked, $this->client->requests, 'paused already: nothing asked');
        $this->assertStringContainsString('No running debugger to pause', $this->flashed());
    }
}
