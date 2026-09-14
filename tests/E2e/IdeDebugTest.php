<?php

declare(strict_types=1);

namespace Porta\Tests\E2e;

use PHPUnit\Framework\TestCase;
use Porta\Debug\DebugController;
use Porta\Editor\BufferService;
use Porta\Editor\OpenFocus;
use Porta\Ide;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\EditorState;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Render\WidgetRect;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * Full-app debugger scenarios against the scripted mock DAP adapter:
 * breakpoints, launch, stepping, the debug panel and teardown.
 */
final class IdeDebugTest extends TestCase
{
    use ClearsPortaLsp;
    use PreservesPath;
    use RunsIde;

    /** A .porta.json wiring php files to the scripted mock adapter. */
    private function mockDapConfig(): string
    {
        return (string) \json_encode([
            'languages' => [
                'php' => ['debug' => [
                    'adapter' => \PHP_BINARY.' '.__DIR__.'/../Support/mock-dap-adapter.php',
                ]],
            ],
        ]);
    }

    /** @return string the program fixture: 8 comment lines so breakpoints have targets */
    private function program(): string
    {
        return "<?php\n// l2\n// l3\n// l4\n// l5\n// l6\n// l7\n// l8\n";
    }

    public function testBreakpointsToggleAndSurviveTabSwitches(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['prog.php' => $this->program(), 'other.php' => "<?php\n"]);

        $this->runIde(
            $project,
            $project->path('prog.php'),
            [
                200 => function (Tui $tui, $terminal): void {
                    $tui->getById('editor')->getDocument()->setCursor(2, 0);
                    $tui->handleInput("\x1b[19;5~"); // ctrl+f8 → set
                    $this->frame($tui);
                    $this->assertStringContainsString('●', $terminal->getOutput(), 'gutter dot rendered');
                },
                340 => function ($tui, $terminal, Ide $ide) use ($project): void {
                    $editorScreen = static fn (): string => \implode("\n", $tui->getById('editor')->render(new RenderContext(120, 20)));
                    Services::resolve($ide, BufferService::class)
                        ->openFile($project->path('other.php'), OpenFocus::Editor);
                    $this->assertStringNotContainsString('●', $editorScreen(), 'no dot in the other file');
                    $tui->handleInput("\x1b[1;5D"); // ctrl+left back to prog.php
                    $this->assertStringContainsString('●', $editorScreen(), 'the mark survived the tab switch');
                    $tui->handleInput("\x1b[19;5~"); // ctrl+f8 again → clear
                    $this->assertStringNotContainsString('●', $editorScreen());
                },
                480 => function ($tui, $terminal, Ide $ide): void {
                    // A plain click in the gutter toggles a breakpoint on that line.
                    $tui->tick();
                    $renderer = Services::resolve($ide, Renderer::class);
                    $rect = $renderer->getWidgetRect($tui->getById('editor'));
                    $editorScreen = static fn (): string => \implode("\n", $tui->getById('editor')->render(new RenderContext(120, 20)));
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $rect->col + 2, $rect->row + 2)); // gutter, row 1
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dm", $rect->col + 2, $rect->row + 2));
                    $this->assertStringContainsString('●', $editorScreen(), 'gutter click set a breakpoint');
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $rect->col + 2, $rect->row + 2));
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dm", $rect->col + 2, $rect->row + 2));
                    $this->assertStringNotContainsString('●', $editorScreen(), 'a second click cleared it');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testDebugSessionStopsStepsAndTerminates(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['.porta.json' => $this->mockDapConfig(), 'prog.php' => $this->program()]);

        $this->runIde(
            $project,
            $project->path('prog.php'),
            [
                100 => static function ($tui): void {
                    $tui->getById('editor')->getDocument()->setCursor(4, 0); // 0-based line 4 → wire line 5
                    $tui->handleInput("\x1b[19;5~"); // ctrl+f8 breakpoint
                    $tui->handleInput("\x1b[20;2~"); // shift+f9 debug
                },
                200 => function (Tui $tui, VirtualTerminal $terminal): void {
                    // Locals are their own DAP round trip behind the stop event
                    // and the stack — await the LAST of the three to land, not
                    // just "paused", or the panel can still be mid-populate.
                    $this->awaitPainted($tui, $terminal, '$x = 42');
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('Debug: prog.php', $screen, 'the debug panel opened');
                    $this->assertStringContainsString('paused at prog.php:5', $screen, 'stopped at the breakpoint');
                    $this->assertStringContainsString('#0 {main}  prog.php:5', $screen, 'the stack renders');
                    $this->assertStringContainsString('$x = 42', $screen, 'locals render');
                    $this->assertStringContainsString('⏸ prog.php:5', $screen, 'status-bar segment');
                    $this->assertStringContainsString("\x1b[48;5;52m", $terminal->getOutput(), 'stopped-line band painted');
                    $this->assertStringNotContainsString('hello from debuggee', $screen, 'the debuggee output sits behind the Terminal tab, not stacked');
                    // The output is one dock tab away: alt+page_down cycles Debug → Terminal → Debug.
                    $tui->handleInput("\x1b[6;3~"); // alt+page_down → the Terminal tab
                    $this->awaitPainted($tui, $terminal, 'hello from debuggee');
                    $this->assertStringContainsString('hello from debuggee', AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'debuggee output reached the debug terminal session');
                    $tui->handleInput("\x1b[6;3~"); // alt+page_down wraps back to the Debug tab
                    $this->awaitPainted($tui, $terminal, '#0 {main}  prog.php:5');
                    $this->assertStringContainsString('#0 {main}  prog.php:5', AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'the debug panel is back');
                    $tui->handleInput("\x1b[19~"); // f8 step over → line 6
                },
                300 => function (Tui $tui, VirtualTerminal $terminal): void {
                    $this->awaitPainted($tui, $terminal, 'paused at prog.php:6');
                    $this->assertStringContainsString('paused at prog.php:6', AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'the step advanced one line');
                    // Park the dock on the Terminal tab: the next stop must
                    // auto-reveal the Debug tab.
                    $tui->handleInput("\x1b[6;3~"); // alt+page_down → Terminal
                    $tui->tick();
                    $this->assertFalse($tui->getById('debug')->isVisible());
                    $tui->handleInput("\x1b[19~"); // f8 step over → line 7 → stop
                },
                400 => function (Tui $tui, VirtualTerminal $terminal): void {
                    // The f8 above is a DAP round trip; 150 ms was a bet that
                    // the stop had already come back. Await the stop, THEN ask
                    // whether it revealed the tab — the reveal is the claim
                    // under test and cannot be read before the stop lands.
                    $this->awaitPainted($tui, $terminal, 'paused at prog.php:7');
                    $this->assertTrue($tui->getById('debug')->isVisible(), 'the stop auto-revealed the Debug tab');
                    $tui->handleInput("\x1b[20~"); // f9 resume → no more breakpoints → terminated
                },
                500 => function (Tui $tui, VirtualTerminal $terminal): void {
                    $this->awaitPainted($tui, $terminal, 'terminated');
                    $this->assertStringContainsString('terminated', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                    $editorScreen = \implode("\n", $tui->getById('editor')->render(new RenderContext(120, 20)));
                    $this->assertStringNotContainsString("\x1b[48;5;52m", $editorScreen, 'the band cleared');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testGuardsFallThroughAndAdapterlessLaunchFlashes(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['prog.php' => "<?php\n<<<<<<< HEAD\n=======\n>>>>>>> x\n"]);

        $this->runIde(
            $project,
            $project->path('prog.php'),
            [
                200 => function ($tui, $terminal): void {
                    // F7 while idle falls through to the editor's next_conflict.
                    $this->assertSame(0, $tui->getById('editor')->getDocument()->getCursorLine());
                    $tui->handleInput("\x1b[18~");
                    $this->assertGreaterThan(0, $tui->getById('editor')->getDocument()->getCursorLine(), 'F7 reached next_conflict while no session runs');

                    $tui->handleInput("\x1b[20;2~"); // shift+f9 with no adapter configured
                    $this->await(static fn (): bool => \str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'No debug adapter configured for php'), what: 'the adapterless-launch flash');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testStopMidPauseCleansUpAndPanelMouseWorks(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['.porta.json' => $this->mockDapConfig(), 'prog.php' => $this->program()]);

        $this->runIde(
            $project,
            $project->path('prog.php'),
            [
                100 => static function ($tui): void {
                    $tui->getById('editor')->getDocument()->setCursor(2, 0);
                    $tui->handleInput("\x1b[19;5~");
                    $tui->handleInput("\x1b[20;2~");
                },
                200 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    $this->awaitPainted($tui, $terminal, 'paused at prog.php:3');
                    $this->assertStringContainsString('paused at prog.php:3', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                    // Drag the debug panel's top edge up: all three bottom panels resize.
                    $renderer = Services::resolve($ide, Renderer::class);
                    $rect = $renderer->getWidgetRect($tui->getById('debug'));
                    $this->assertInstanceOf(WidgetRect::class, $rect);
                    $top = $rect->row; // 1-based screen row of the dock strip — the resize grab
                    $tui->handleInput(\sprintf("\x1b[<0;60;%dM", $top)); // col 60: the strip's empty tail
                    $tui->handleInput(\sprintf("\x1b[<32;60;%dM", $top - 2));
                    $tui->handleInput(\sprintf("\x1b[<0;60;%dm", $top - 2));
                    $tui->tick();
                    $expected = Probe::get($tui->getById('debug'), 'visibleRows');
                    $this->assertSame($expected, Probe::get($tui->getById('terminal'), 'visibleRows'), 'bottom panels stay in sync');
                    $this->assertSame($expected, Probe::get($tui->getById('results'), 'visibleRows'));
                    // Click the second frame row: Frames header +2 = frame #1 at line 1.
                    $rect = $renderer->getWidgetRect($tui->getById('debug'));
                    $tui->handleInput(\sprintf("\x1b[<0;5;%dM", $rect->row + 3)); // Frames,#0,#1 → rel 2 (no header row)
                    $tui->handleInput(\sprintf("\x1b[<0;5;%dm", $rect->row + 3));
                },
                300 => function ($tui, $terminal, Ide $ide): void {
                    // The frame click above moves the caret; await that rather
                    // than betting 300 ms it already has. The caret starts on
                    // the stopped line (2), so reaching 0 is a real transition.
                    $editor = $tui->getById('editor');
                    $this->await(
                        static fn (): bool => 0 === $editor->getDocument()->getCursorLine(),
                        what: 'the frame #1 click to jump to its line',
                    );
                    $this->assertSame('debug', $tui->getFocus()?->getId(), 'the click focused the panel');
                    // Hover + wheel route to the panel; Escape closes it and restores focus.
                    $renderer = Services::resolve($ide, Renderer::class);
                    $rect = $renderer->getWidgetRect($tui->getById('debug'));
                    $tui->handleInput(\sprintf("\x1b[<35;8;%dM", $rect->row + 3)); // hover a row
                    $tui->handleInput(\sprintf("\x1b[<64;8;%dM", $rect->row + 3)); // wheel scrolls the list
                    // Strip clicks switch tools through the real mouse path:
                    // ' Debug: prog.php × │ Debug: prog.php × ' (the debuggee's
                    // output session, then the debug panel) — refetch the strip
                    // rect per click, since its row shifts with panel height.
                    $stripRow = static fn (): int => $renderer->getWidgetRect($tui->getById('dock-tabs'))->row + 1;
                    $tui->handleInput(\sprintf("\x1b[<0;4;%dM", $stripRow())); // the debug session's tab
                    $tui->handleInput(\sprintf("\x1b[<0;4;%dm", $stripRow()));
                    $tui->tick();
                    $this->assertTrue($tui->getById('terminal')->isVisible(), 'clicking the session tab revealed the debug session output');
                    $debugCol = static function () use ($tui, $renderer): int {
                        $rect = $renderer->getWidgetRect($tui->getById('dock-tabs'));
                        $strip = AnsiUtils::stripAnsiCodes($tui->getById('dock-tabs')->render(new RenderContext($rect->columns, 1))[0]);
                        // The 2nd 'Debug' occurrence is the debug panel's tab.
                        $second = (int) \mb_strpos($strip, needle: 'Debug', offset: (int) \mb_strpos($strip, needle: 'Debug') + 1);

                        return $rect->col + $second + 1;
                    };
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $debugCol(), $stripRow())); // the Debug tab
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dm", $debugCol(), $stripRow()));
                    $tui->tick();
                    $this->assertTrue($tui->getById('debug')->isVisible(), 'clicking the Debug tab brought the frames back');
                    $tui->handleInput("\x1b"); // Escape → the dock hides, focus back to the editor area
                    $tui->tick();
                    $this->assertFalse($tui->getById('debug')->isVisible());
                    $this->assertTrue($tui->getById('debug')->isOpen(), 'the debug tab stays alive behind the hidden dock');
                    $this->assertSame('editor', $tui->getFocus()?->getId(), 'hiding the dock restored editor focus');
                    $tui->handleInput("\x1b[12;5~"); // ctrl+f2 stop mid-pause
                },
                400 => function ($tui, $terminal, Ide $ide): void {
                    // Disconnecting is a DAP round trip, not a keypress effect.
                    $debug = Services::resolve($ide, DebugController::class);
                    $this->await(
                        static fn (): bool => !$debug->isActive(),
                        what: 'ctrl+f2 to disconnect the session',
                    );
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testConditionalBreakpointsAndWatchesEndToEnd(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['.porta.json' => $this->mockDapConfig(), 'prog.php' => $this->program()]);

        $this->runIde(
            $project,
            $project->path('prog.php'),
            [
                100 => function ($tui): void {
                    $tui->getById('editor')->getDocument()->setCursor(4, 0);
                    $tui->handleInput("\x1b[19;6~"); // ctrl+shift+f8: edit → creates the breakpoint too
                    foreach (\str_split('$x > 1') as $ch) {
                        $tui->handleInput($ch);
                    }
                    $tui->handleInput("\r"); // condition submitted
                    $tui->handleInput("\r"); // hit count left empty
                    $tui->handleInput("\r"); // log message left empty
                    $tui->tick();
                    $editorScreen = \implode("\n", $tui->getById('editor')->render(new RenderContext(120, 20)));
                    $this->assertStringContainsString('◆', $editorScreen, 'the conditional breakpoint renders a diamond');
                    $tui->handleInput("\x1b[20;2~"); // shift+f9 debug
                },
                200 => function (Tui $tui, VirtualTerminal $terminal): void {
                    $this->awaitPainted($tui, $terminal, 'paused at prog.php:5');
                    $this->assertStringContainsString('paused at prog.php:5', AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'stopped at the conditional breakpoint');
                    $tui->handleInput("\x1b[6;3~"); // alt+page_down → the adapter echo lives in the debug terminal session
                    $this->awaitPainted($tui, $terminal, 'cond@5: $x > 1');
                    $this->assertStringContainsString('cond@5: $x > 1', AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'the condition reached the adapter and echoed back');
                    $tui->handleInput("\x1b[6;3~"); // alt+page_down wraps back to the Debug tab
                    $tui->tick();
                    $tui->handleInput("\x1b[20;6~"); // ctrl+shift+f9: add watch
                    foreach (\str_split('$x') as $ch) {
                        $tui->handleInput($ch);
                    }
                    $tui->handleInput("\r");
                },
                300 => function (Tui $tui, VirtualTerminal $terminal): void {
                    $this->awaitPainted($tui, $terminal, '$x = 42#1');
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('Watches', $screen);
                    $this->assertStringContainsString('$x = 42#1', $screen, 'the watch evaluated against the paused frame');
                    $tui->handleInput("\x1b[19~"); // f8 step over → stop again → watches re-evaluate
                },
                400 => function (Tui $tui, VirtualTerminal $terminal): void {
                    $this->awaitPainted($tui, $terminal, '$x = 42#2');
                    $this->assertStringContainsString('$x = 42#2', AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'the step re-evaluated the watch');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testRunToCursorStopsAtTheCaretOnce(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['.porta.json' => $this->mockDapConfig(), 'prog.php' => $this->program()]);

        $this->runIde(
            $project,
            $project->path('prog.php'),
            [
                100 => static function ($tui): void {
                    $tui->getById('editor')->getDocument()->setCursor(2, 0); // breakpoint at wire line 3
                    $tui->handleInput("\x1b[19;5~"); // ctrl+f8
                    $tui->handleInput("\x1b[20;2~"); // shift+f9 debug
                },
                200 => function (Tui $tui, VirtualTerminal $terminal): void {
                    $this->awaitPainted($tui, $terminal, 'paused at prog.php:3');
                    $this->assertStringContainsString('paused at prog.php:3', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                    $tui->getById('editor')->getDocument()->setCursor(6, 0);
                    $tui->handleInput("\x1b[20;3~"); // alt+f9 run to cursor
                },
                300 => function (Tui $tui, VirtualTerminal $terminal): void {
                    $this->awaitPainted($tui, $terminal, 'paused at prog.php:7');
                    $this->assertStringContainsString('paused at prog.php:7', AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'stopped at the one-shot');
                    $tui->handleInput("\x1b[20~"); // f9 resume: the one-shot is gone → termination
                },
                400 => function (Tui $tui, VirtualTerminal $terminal): void {
                    $this->awaitPainted($tui, $terminal, 'terminated');
                    $this->assertStringContainsString('terminated', AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'no ghost breakpoint stopped the resume');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testExceptionFiltersReachTheAdapterAndToggleLive(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['.porta.json' => $this->mockDapConfig(), 'prog.php' => $this->program()]);

        $palette = static function ($tui, string $label): void {
            $tui->handleInput("\x1b[97;6u");
            foreach (\str_split($label) as $ch) {
                $tui->handleInput($ch);
            }
            $tui->handleInput("\r");
        };

        $this->runIde(
            $project,
            $project->path('prog.php'),
            [
                200 => static function ($tui): void {
                    $tui->getById('editor')->getDocument()->setCursor(2, 0);
                    $tui->handleInput("\x1b[19;5~"); // a breakpoint keeps the session paused
                    $tui->handleInput("\x1b[20;2~"); // shift+f9 debug
                },
                600 => function ($tui, $terminal) use ($palette): void {
                    $tui->handleInput("\x1b[96;5u"); // ctrl+` → the adapter echo lives in the debug terminal session
                    // The defaults go out during the adapter handshake — a
                    // whole php subprocess spawn; await, never a fixed gap.
                    $this->await(static fn (): bool => \str_contains(
                        AnsiUtils::stripAnsiCodes($terminal->getOutput()),
                        'xfilters: exception',
                    ), what: 'the default exception filters to reach the adapter');

                    $palette($tui, label: 'Debug: Exception Breakpoints');
                    $this->await(static fn (): bool => EditorState::pickerOpen($tui->getById('editor')));
                    $tui->handleInput("\r"); // toggle Warnings on → re-sync + re-open
                    $this->await(static fn (): bool => \str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'xfilters: exception,warning'));
                    $tui->handleInput("\x1b"); // leave the chooser
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testDebugStatePersistsAcrossRelaunch(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['.porta.json' => $this->mockDapConfig(), 'prog.php' => $this->program()]);

        $this->runIde(
            $project,
            $project->path('prog.php'),
            [
                200 => static function ($tui): void {
                    $tui->getById('editor')->getDocument()->setCursor(4, 0);
                    $tui->handleInput("\x1b[19;6~"); // ctrl+shift+f8: conditional breakpoint
                    foreach (\str_split('$x > 1') as $ch) {
                        $tui->handleInput($ch);
                    }
                    $tui->handleInput("\r");
                    $tui->handleInput("\r"); // no hit count
                    $tui->handleInput("\r"); // no log message
                    $tui->handleInput("\x1b[20;6~"); // ctrl+shift+f9: add a watch
                    foreach (\str_split('$x') as $ch) {
                        $tui->handleInput($ch);
                    }
                    $tui->handleInput("\r");
                },
            ],
        );

        $saved = \json_decode((string) \file_get_contents($project->path('.porta/debug.json')), associative: true);
        $this->assertSame('$x > 1', \array_first($saved['breakpoints'])[0]['condition'], 'the condition persisted');
        $this->assertSame(4, \array_first($saved['breakpoints'])[0]['line']);
        $this->assertSame(['$x'], $saved['watches']);

        // Second boot on the same project: the gutter shows the restored
        // conditional breakpoint, and a session evaluates the restored watch.
        $this->runIde(
            $project,
            $project->path('prog.php'),
            [
                300 => function ($tui): void {
                    $editorScreen = static fn (): string => \implode("\n", $tui->getById('editor')->render(new RenderContext(120, 20)));
                    $this->assertStringContainsString('◆', $editorScreen(), 'the restored conditional breakpoint paints at boot');
                    $tui->handleInput("\x1b[20;2~"); // shift+f9 debug
                },
                800 => function (Tui $tui, VirtualTerminal $terminal): void {
                    // The restored watch's evaluation is its own DAP round trip
                    // behind the stop event — await it, not just "paused".
                    $this->awaitPainted($tui, $terminal, '$x = 42#1');
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('paused at prog.php:5', $screen, 'the restored breakpoint stopped the program');
                    $this->assertStringContainsString('$x = 42#1', $screen, 'the restored watch evaluated');
                    $tui->handleInput("\x1b[96;5u"); // ctrl+` → the adapter echo lives in the debug terminal session
                    $this->awaitPainted($tui, $terminal, 'cond@5: $x > 1');
                    $this->assertStringContainsString('cond@5: $x > 1', AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'its condition reached the adapter');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }
}
