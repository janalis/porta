<?php

declare(strict_types=1);

namespace Porta\Tests\E2e;

use PHPUnit\Framework\TestCase;
use Porta\Editor\OpenFocus;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\TempProject;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * Full-app debugger scenarios against the scripted mock DAP adapter:
 * breakpoints, launch, stepping, the debug panel and teardown.
 */
final class IdeDebugTest extends TestCase
{
    use RunsIde;

    private string $originalPath;

    protected function setUp(): void
    {
        $this->originalPath = (string) getenv('PATH');
        putenv('PORTA_LSP');
    }

    protected function tearDown(): void
    {
        putenv('PATH='.$this->originalPath);
    }

    /** A .porta.json wiring php files to the scripted mock adapter. */
    private function mockDapConfig(): string
    {
        return (string) json_encode([
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
        putenv('PATH=/nonexistent');
        $project = new TempProject(['prog.php' => $this->program(), 'other.php' => "<?php\n"]);

        $this->runIde(
            $project,
            $project->path('prog.php'),
            [
                200 => function ($tui, $terminal): void {
                    $tui->getById('editor')->getDocument()->setCursor(2, 0);
                    $tui->handleInput("\x1b[19;5~"); // ctrl+f8 → set
                    $tui->tick();
                    $this->assertStringContainsString('●', $terminal->getOutput(), 'gutter dot rendered');
                },
                340 => function ($tui, $terminal, $ide) use ($project): void {
                    $editorScreen = static fn (): string => implode("\n", $tui->getById('editor')->render(new \Symfony\Component\Tui\Render\RenderContext(120, 20)));
                    new \ReflectionProperty($ide, 'bufferService')
                        ->getValue($ide)
                        ->openFile($project->path('other.php'), OpenFocus::Editor);
                    $this->assertStringNotContainsString('●', $editorScreen(), 'no dot in the other file');
                    $tui->handleInput("\x1b[1;5D"); // ctrl+left back to prog.php
                    $this->assertStringContainsString('●', $editorScreen(), 'the mark survived the tab switch');
                    $tui->handleInput("\x1b[19;5~"); // ctrl+f8 again → clear
                    $this->assertStringNotContainsString('●', $editorScreen());
                },
                480 => function ($tui, $terminal, $ide): void {
                    // A plain click in the gutter toggles a breakpoint on that line.
                    $tui->tick();
                    $renderer = new \ReflectionProperty($ide, 'renderer')->getValue($ide);
                    $rect = $renderer->getWidgetRect($tui->getById('editor'));
                    $editorScreen = static fn (): string => implode("\n", $tui->getById('editor')->render(new \Symfony\Component\Tui\Render\RenderContext(120, 20)));
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
        putenv('PATH=/nonexistent');
        $project = new TempProject(['.porta.json' => $this->mockDapConfig(), 'prog.php' => $this->program()]);

        $this->runIde(
            $project,
            $project->path('prog.php'),
            [
                200 => static function ($tui): void {
                    $tui->getById('editor')->getDocument()->setCursor(4, 0); // 0-based line 4 → wire line 5
                    $tui->handleInput("\x1b[19;5~"); // ctrl+f8 breakpoint
                    $tui->handleInput("\x1b[20;2~"); // shift+f9 debug
                },
                700 => function ($tui, $terminal): void {
                    $tui->tick();
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('Debug: prog.php', $screen, 'the debug panel opened');
                    $this->assertStringContainsString('paused at prog.php:5', $screen, 'stopped at the breakpoint');
                    $this->assertStringContainsString('#0 {main}  prog.php:5', $screen, 'the stack renders');
                    $this->assertStringContainsString('$x = 42', $screen, 'locals render');
                    $this->assertStringContainsString('⏸ prog.php:5', $screen, 'status-bar segment');
                    $this->assertStringContainsString("\x1b[48;5;52m", $terminal->getOutput(), 'stopped-line band painted');
                    $this->assertStringContainsString('hello from debuggee', $screen, 'debuggee output reached the terminal panel');
                    $tui->handleInput("\x1b[19~"); // f8 step over → line 6
                },
                1000 => function ($tui, $terminal): void {
                    $tui->tick();
                    $this->assertStringContainsString('paused at prog.php:6', AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'the step advanced one line');
                    $tui->handleInput("\x1b[20~"); // f9 resume → no more breakpoints → terminated
                },
                1300 => function ($tui, $terminal): void {
                    $tui->tick();
                    $this->assertStringContainsString('terminated', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                    $editorScreen = implode("\n", $tui->getById('editor')->render(new \Symfony\Component\Tui\Render\RenderContext(120, 20)));
                    $this->assertStringNotContainsString("\x1b[48;5;52m", $editorScreen, 'the band cleared');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testGuardsFallThroughAndAdapterlessLaunchFlashes(): void
    {
        putenv('PATH=/nonexistent');
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
                    $tui->tick();
                    $this->assertStringContainsString('No debug adapter configured for php', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testStopMidPauseCleansUpAndPanelMouseWorks(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['.porta.json' => $this->mockDapConfig(), 'prog.php' => $this->program()]);

        $this->runIde(
            $project,
            $project->path('prog.php'),
            [
                200 => static function ($tui): void {
                    $tui->getById('editor')->getDocument()->setCursor(2, 0);
                    $tui->handleInput("\x1b[19;5~");
                    $tui->handleInput("\x1b[20;2~");
                },
                700 => function ($tui, $terminal, $ide): void {
                    $tui->tick();
                    $this->assertStringContainsString('paused at prog.php:3', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                    // Drag the debug panel's top edge up: all three bottom panels resize.
                    $renderer = new \ReflectionProperty($ide, 'renderer')->getValue($ide);
                    $rect = $renderer->getWidgetRect($tui->getById('debug'));
                    $this->assertNotNull($rect);
                    $top = $rect->row + 1;
                    $tui->handleInput(\sprintf("\x1b[<0;5;%dM", $top));
                    $tui->handleInput(\sprintf("\x1b[<32;5;%dM", $top - 2));
                    $tui->handleInput(\sprintf("\x1b[<0;5;%dm", $top - 2));
                    $tui->tick();
                    $expected = $tui->getById('debug')->visibleRows();
                    $this->assertSame($expected, $tui->getById('terminal')->visibleRows(), 'bottom panels stay in sync');
                    $this->assertSame($expected, $tui->getById('results')->visibleRows());
                    // Click the second frame row: Frames header +2 = frame #1 at line 1.
                    $rect = $renderer->getWidgetRect($tui->getById('debug'));
                    $tui->handleInput(\sprintf("\x1b[<0;5;%dM", $rect->row + 4)); // header,Frames,#0,#1 → rel 3
                    $tui->handleInput(\sprintf("\x1b[<0;5;%dm", $rect->row + 4));
                },
                1000 => function ($tui, $terminal, $ide): void {
                    $tui->tick();
                    $this->assertSame(0, $tui->getById('editor')->getDocument()->getCursorLine(), 'clicking frame #1 jumped to its line 1');
                    $this->assertSame('debug', $tui->getFocus()?->getId(), 'the click focused the panel');
                    // Hover + wheel route to the panel; Escape closes it and restores focus.
                    $renderer = new \ReflectionProperty($ide, 'renderer')->getValue($ide);
                    $rect = $renderer->getWidgetRect($tui->getById('debug'));
                    $tui->handleInput(\sprintf("\x1b[<35;8;%dM", $rect->row + 3)); // hover a row
                    $tui->handleInput(\sprintf("\x1b[<64;8;%dM", $rect->row + 3)); // wheel scrolls the list
                    $tui->handleInput("\x1b"); // Escape → close, focus back to the editor area
                    $tui->tick();
                    $this->assertFalse($tui->getById('debug')->isOpen());
                    $this->assertSame('editor', $tui->getFocus()?->getId(), 'panel close restored editor focus');
                    $tui->handleInput("\x1b[12;5~"); // ctrl+f2 stop mid-pause
                },
                1300 => function ($tui, $terminal, $ide): void {
                    $tui->tick();
                    $debug = new \ReflectionProperty($ide, 'debug')->getValue($ide);
                    $this->assertFalse($debug->isActive(), 'ctrl+f2 disconnected the session');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testConditionalBreakpointsAndWatchesEndToEnd(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['.porta.json' => $this->mockDapConfig(), 'prog.php' => $this->program()]);

        $this->runIde(
            $project,
            $project->path('prog.php'),
            [
                200 => function ($tui): void {
                    $tui->getById('editor')->getDocument()->setCursor(4, 0);
                    $tui->handleInput("\x1b[19;6~"); // ctrl+shift+f8: edit → creates the breakpoint too
                    foreach (str_split('$x > 1') as $ch) {
                        $tui->handleInput($ch);
                    }
                    $tui->handleInput("\r"); // condition submitted
                    $tui->handleInput("\r"); // hit count left empty
                    $tui->tick();
                    $editorScreen = implode("\n", $tui->getById('editor')->render(new \Symfony\Component\Tui\Render\RenderContext(120, 20)));
                    $this->assertStringContainsString('◆', $editorScreen, 'the conditional breakpoint renders a diamond');
                    $tui->handleInput("\x1b[20;2~"); // shift+f9 debug
                },
                700 => function ($tui, $terminal): void {
                    $tui->tick();
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('paused at prog.php:5', $screen, 'stopped at the conditional breakpoint');
                    $this->assertStringContainsString('cond@5: $x > 1', $screen, 'the condition reached the adapter and echoed back');
                    $tui->handleInput("\x1b[20;6~"); // ctrl+shift+f9: add watch
                    foreach (str_split('$x') as $ch) {
                        $tui->handleInput($ch);
                    }
                    $tui->handleInput("\r");
                },
                1000 => function ($tui, $terminal): void {
                    $tui->tick();
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('Watches', $screen);
                    $this->assertStringContainsString('$x = 42#1', $screen, 'the watch evaluated against the paused frame');
                    $tui->handleInput("\x1b[19~"); // f8 step over → stop again → watches re-evaluate
                },
                1300 => function ($tui, $terminal): void {
                    $tui->tick();
                    $this->assertStringContainsString('$x = 42#2', AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'the step re-evaluated the watch');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testRunToCursorStopsAtTheCaretOnce(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['.porta.json' => $this->mockDapConfig(), 'prog.php' => $this->program()]);

        $this->runIde(
            $project,
            $project->path('prog.php'),
            [
                200 => static function ($tui): void {
                    $tui->getById('editor')->getDocument()->setCursor(2, 0); // breakpoint at wire line 3
                    $tui->handleInput("\x1b[19;5~"); // ctrl+f8
                    $tui->handleInput("\x1b[20;2~"); // shift+f9 debug
                },
                600 => function ($tui, $terminal): void {
                    $tui->tick();
                    $this->assertStringContainsString('paused at prog.php:3', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                    $tui->getById('editor')->getDocument()->setCursor(6, 0);
                    $tui->handleInput("\x1b[20;3~"); // alt+f9 run to cursor
                },
                1000 => function ($tui, $terminal): void {
                    $tui->tick();
                    $this->assertStringContainsString('paused at prog.php:7', AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'stopped at the one-shot');
                    $tui->handleInput("\x1b[20~"); // f9 resume: the one-shot is gone → termination
                },
                1400 => function ($tui, $terminal): void {
                    $tui->tick();
                    $this->assertStringContainsString('terminated', AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'no ghost breakpoint stopped the resume');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testExceptionFiltersReachTheAdapterAndToggleLive(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['.porta.json' => $this->mockDapConfig(), 'prog.php' => $this->program()]);

        $palette = static function ($tui, string $label): void {
            $tui->handleInput("\x1b[97;6u");
            foreach (str_split($label) as $ch) {
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
                    $tui->tick();
                    // The defaults went out during configuration.
                    $this->assertStringContainsString('xfilters: exception', AnsiUtils::stripAnsiCodes($terminal->getOutput()));

                    $palette($tui, label: 'Debug: Exception Breakpoints');
                    $this->await(static fn (): bool => $tui->getById('editor')->isOverlayOpen());
                    $tui->handleInput("\r"); // toggle Warnings on → re-sync + re-open
                    $this->await(static fn (): bool => str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'xfilters: exception,warning'));
                    $tui->handleInput("\x1b"); // leave the chooser
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testDebugStatePersistsAcrossRelaunch(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['.porta.json' => $this->mockDapConfig(), 'prog.php' => $this->program()]);

        $this->runIde(
            $project,
            $project->path('prog.php'),
            [
                200 => static function ($tui): void {
                    $tui->getById('editor')->getDocument()->setCursor(4, 0);
                    $tui->handleInput("\x1b[19;6~"); // ctrl+shift+f8: conditional breakpoint
                    foreach (str_split('$x > 1') as $ch) {
                        $tui->handleInput($ch);
                    }
                    $tui->handleInput("\r");
                    $tui->handleInput("\r"); // no hit count
                    $tui->handleInput("\x1b[20;6~"); // ctrl+shift+f9: add a watch
                    foreach (str_split('$x') as $ch) {
                        $tui->handleInput($ch);
                    }
                    $tui->handleInput("\r");
                },
            ],
        );

        $saved = json_decode((string) file_get_contents($project->path('.porta/debug.json')), associative: true);
        $this->assertSame('$x > 1', array_values($saved['breakpoints'])[0][0]['condition'], 'the condition persisted');
        $this->assertSame(4, array_values($saved['breakpoints'])[0][0]['line']);
        $this->assertSame(['$x'], $saved['watches']);

        // Second boot on the same project: the gutter shows the restored
        // conditional breakpoint, and a session evaluates the restored watch.
        $this->runIde(
            $project,
            $project->path('prog.php'),
            [
                300 => function ($tui): void {
                    $editorScreen = static fn (): string => implode("\n", $tui->getById('editor')->render(new \Symfony\Component\Tui\Render\RenderContext(120, 20)));
                    $this->assertStringContainsString('◆', $editorScreen(), 'the restored conditional breakpoint paints at boot');
                    $tui->handleInput("\x1b[20;2~"); // shift+f9 debug
                },
                800 => function ($tui, $terminal): void {
                    $tui->tick();
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('paused at prog.php:5', $screen, 'the restored breakpoint stopped the program');
                    $this->assertStringContainsString('cond@5: $x > 1', $screen, 'its condition reached the adapter');
                    $this->assertStringContainsString('$x = 42#1', $screen, 'the restored watch evaluated');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }
}
