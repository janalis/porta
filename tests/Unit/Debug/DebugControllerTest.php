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
use Porta\Debug\ExceptionFilterStore;
use Porta\Debug\WatchStore;
use Porta\Editor\Document;
use Porta\Editor\FileOpener;
use Porta\Event\DebugStatusChanged;
use Porta\I18n\Translator;
use Porta\Terminal\TerminalSessions;
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
    private ExceptionFilterStore $filters;
    private TerminalSessions $sessions;
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
            new WatchStore(),
            $this->filters = new ExceptionFilterStore(),
            $this->sessions = new TerminalSessions(),
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
        $this->client->respond('initialize', [
            'supportsConfigurationDoneRequest' => true,
            'exceptionBreakpointFilters' => [
                ['filter' => 'warning', 'label' => 'Warnings', 'default' => false],
                ['filter' => 'exception', 'label' => 'Uncaught Exceptions', 'default' => true],
            ],
        ]);
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

    public function testScopeWithoutAVariablesReferenceIsNotFetched(): void
    {
        $controller = $this->controller();
        $this->client->responses['stackTrace'] = [
            ['stackFrames' => [['id' => 1, 'name' => '{main}', 'source' => ['path' => '/p/prog.php'], 'line' => 3]]],
            null,
        ];
        // A real scope plus a synthetic one with no variablesReference (<= 0):
        // the latter is skipped — no variables request is issued for it.
        $this->client->responses['scopes'] = [
            [
                'scopes' => [
                    ['name' => 'Locals', 'variablesReference' => 1000],
                    ['name' => 'Registers', 'variablesReference' => 0],
                ],
            ],
            null,
        ];
        $this->client->responses['variables'] = [['variables' => [['name' => '$x', 'value' => '42', 'variablesReference' => 0]]], null];

        $controller->startForCurrentFile();
        $this->client->respond('initialize', ['supportsConfigurationDoneRequest' => true]);
        $this->client->respond('launch');
        $this->client->emit('stopped', ['reason' => 'breakpoint', 'threadId' => 1]);

        $this->assertTrue($controller->isPaused());
        $variableRequests = array_filter(
            $this->client->requests,
            static fn (array $r): bool => 'variables' === $r['command'],
        );
        $this->assertCount(1, $variableRequests, 'only the ref>0 scope requested its variables');
        $this->assertSame(1000, $variableRequests[array_key_first($variableRequests)]['arguments']['variablesReference']);
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

    public function testEditBreakpointPromptsStoreConditionAndSyncLive(): void
    {
        $controller = $this->controller();
        $this->pauseAtBreakpoint($controller);
        $this->editor->jumpTo(1, 0); // off the stopped line — its ▶ outranks the breakpoint glyphs

        $controller->editBreakpointAtCaret();
        foreach (str_split('$x > 1') as $ch) {
            $this->editor->handleInput($ch);
        }
        $this->editor->handleInput("\r"); // condition submitted → hit-count prompt chains
        $this->editor->handleInput('3');
        $this->editor->handleInput("\r");

        $this->assertSame('/p/prog.php', $this->client->argumentsOf('setBreakpoints')['source']['path']);
        $this->assertStringContainsString('◆', implode("\n", $this->editor->render(new RenderContext(80, 8))), 'the conditional breakpoint renders a diamond');

        // Re-editing prefills; submitting both prompts empty clears the conditions.
        $controller->editBreakpointAtCaret();
        foreach (range(1, end: 12) as $_) {
            $this->editor->handleInput("\x7f");
        }
        $this->editor->handleInput("\r");
        foreach (range(1, end: 6) as $_) {
            $this->editor->handleInput("\x7f");
        }
        $this->editor->handleInput("\r");
        $screen = implode("\n", $this->editor->render(new RenderContext(80, 8)));
        $this->assertStringNotContainsString('◆', $screen, 'cleared conditions drop the diamond');
        $this->assertStringContainsString('●', $screen, 'the breakpoint itself survives');

        $controllerWithoutFile = $this->controller(withFile: false);
        $controllerWithoutFile->editBreakpointAtCaret();
        $this->assertStringContainsString('Nothing to debug', $this->flashed());
    }

    public function testWatchesEvaluatePerStopAndRemoveThroughThePanel(): void
    {
        $controller = $this->controller();
        $this->client->responses['evaluate'] = [['result' => '42', 'variablesReference' => 0], null];
        $this->pauseAtBreakpoint($controller);

        $controller->addWatch();
        foreach (str_split('$x') as $ch) {
            $this->editor->handleInput($ch);
        }
        $this->editor->handleInput("\r");
        $this->assertSame(['expression' => '$x', 'context' => 'watch', 'frameId' => 1], $this->client->argumentsOf('evaluate'), 'a new watch evaluates against the paused frame');
        $panelScreen = implode("\n", array_map(AnsiUtils::stripAnsiCodes(...), $this->panel->render(new RenderContext(100, 14))));
        $this->assertStringContainsString('$x = 42', $panelScreen);

        // An empty submission adds nothing; the blank prompt is a no-op.
        $controller->addWatch();
        $this->editor->handleInput("\r");

        // A frame jump re-evaluates against the new frame.
        $evaluatesBefore = \count(array_filter($this->client->commands(), static fn (string $c): bool => 'evaluate' === $c));
        $this->panel->handleClick(3); // the #1 frame row
        $this->assertSame($evaluatesBefore + 1, \count(array_filter($this->client->commands(), static fn (string $c): bool => 'evaluate' === $c)));
        $this->assertSame(['expression' => '$x', 'context' => 'watch', 'frameId' => 2], $this->client->argumentsOf('evaluate'));

        // Removing through the panel drops the expression and its row. The
        // frame click left the selection on row 2 (#1) — two downs reach the
        // watch row (3 Watches, 4 $x).
        for ($i = 0; $i < 2; ++$i) {
            $this->panel->handleInput("\x1b[B");
        }
        $this->panel->handleInput("\x1b[3~");
        $panelScreen = implode("\n", array_map(AnsiUtils::stripAnsiCodes(...), $this->panel->render(new RenderContext(100, 14))));
        $this->assertStringNotContainsString('Watches', $panelScreen, 'the last watch removed drops the section');
    }

    public function testAddingAWatchWhileNotPausedDefersEvaluation(): void
    {
        $controller = $this->controller();
        $controller->addWatch();
        foreach (str_split('$x') as $ch) {
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
        foreach (str_split('$name') as $ch) {
            $this->editor->handleInput($ch);
        }
        $this->editor->handleInput("\r");
        $this->assertSame(['expression' => '$name', 'context' => 'repl', 'frameId' => 1], $this->client->argumentsOf('evaluate'));
        $this->assertStringContainsString("\$name = 'porta'", $this->flashed());

        $this->client->responses['evaluate'] = [[], 'oops'];
        $controller->evaluatePrompt();
        foreach (str_split('$bad') as $ch) {
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
        new \ReflectionProperty($this->editor, 'filePath')->setValue($this->editor, null);

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
        $screen = implode("\n", array_map(AnsiUtils::stripAnsiCodes(...), $this->editor->render(new RenderContext(100, 20))));
        $this->assertStringContainsString('[ ] Warnings', $screen);
        $this->assertStringContainsString('[x] Uncaught Exceptions', $screen, 'the default-on filter renders checked');

        $this->editor->handleInput("\r"); // toggle Warnings on → re-sync + re-open
        $this->assertSame(['filters' => ['exception', 'warning']], $this->client->argumentsOf('setExceptionBreakpoints'));
        $screen = implode("\n", array_map(AnsiUtils::stripAnsiCodes(...), $this->editor->render(new RenderContext(100, 20))));
        $this->assertStringContainsString('[x] Warnings', $screen, 'the overlay re-opened with the new state');

        $this->editor->handleInput("\x1b[B");
        $this->editor->handleInput("\r"); // toggle Uncaught Exceptions off
        $this->assertSame(['filters' => ['warning']], $this->client->argumentsOf('setExceptionBreakpoints'));

        $this->editor->handleInput("\x1b"); // Escape leaves the loop
        $this->assertFalse($this->editor->isOverlayOpen());
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
    }
}
