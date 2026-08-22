<?php

declare(strict_types=1);

namespace Porta\Tests\E2e;

use PHPUnit\Framework\TestCase;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\EditorState;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\PreservesShell;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\ScreenReads;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Porta\Ui\Glyphs;
use Porta\Workbench\Dock\BottomDock;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\Renderer;

/**
 * The integrated terminal and run configurations: sessions,
 * scrollback, key encoding, the Run menu.
 * Split out of the oversized IdeEdgeCasesTest.
 */
final class IdeTerminalRunTest extends TestCase
{
    use ClearsPortaLsp;
    use PreservesPath;
    use PreservesShell;
    use RunsIde;
    use ScreenReads;

    public function testClickingTerminalPaneRefocusesIt(): void
    {
        putenv('PATH='.PORTA_TEST_PRISTINE_PATH); // needs /bin/sh
        putenv('SHELL=/bin/sh');
        $project = new TempProject(['A.php' => "<?php\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                300 => function ($tui): void {
                    $tui->handleInput("\x1b[96;5u"); // ctrl+` opens the terminal (and focuses it)
                    $this->assertSame('terminal', $tui->getFocus()?->getId());
                    // Click into the editor: focus leaves the terminal.
                    $tui->handleInput("\x1b[<0;40;3M");
                    $this->assertSame('editor', $tui->getFocus()?->getId(), 'clicking the editor moved focus off the terminal');
                },
                360 => function ($tui, $terminal, $ide): void {
                    $tui->tick();
                    // Click the centre of the terminal pane → focus returns to it.
                    $renderer = Services::resolve($ide, Renderer::class);
                    $rect = $renderer->getWidgetRect($tui->getById('terminal'));
                    $this->assertNotNull($rect, 'terminal pane is laid out');
                    $row = $rect->row + intdiv($rect->rows, num2: 2);
                    $col = $rect->col + intdiv($rect->columns, num2: 2);
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $col + 1, $row + 1)); // SGR is 1-based
                    $this->assertSame('terminal', $tui->getFocus()?->getId(), 'clicking the terminal pane refocused it');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testMouseWheelScrollsTheTerminal(): void
    {
        putenv('PATH='.PORTA_TEST_PRISTINE_PATH); // needs /bin/sh + seq
        putenv('SHELL=/bin/sh');
        $project = new TempProject(['A.php' => "<?php\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                300 => static function ($tui): void {
                    $tui->handleInput("\x1b[96;5u"); // open the terminal
                    foreach (str_split("seq 30\n") as $ch) {
                        $tui->handleInput($ch); // produce more than a screenful of output
                    }
                },
                900 => function ($tui, $terminal, $ide): void {
                    // Wait for the PTY to actually deliver seq 30's output. Until
                    // more lines exist than the pane shows, scroll() clamps
                    // scrollBack to 0 and there is nothing to scroll into.
                    $panel = $tui->getById('terminal');
                    $buffer = Probe::get($panel, 'buffer');
                    $this->await(static fn (): bool => $buffer->lineCount() >= 30, what: 'seq 30 output to fill the scrollback');

                    // Force a full frame so the terminal pane has a live WidgetRect.
                    $this->frame($tui);
                    $renderer = Services::resolve($ide, Renderer::class);
                    $rect = $renderer->getWidgetRect($panel);
                    $row = $rect->row + intdiv($rect->rows, num2: 2);
                    $col = $rect->col + intdiv($rect->columns, num2: 2);
                    // Wheel up (SGR code 64) over the terminal pane → scroll into history.
                    $tui->handleInput(\sprintf("\x1b[<64;%d;%dM", $col + 1, $row + 1));
                    $tui->tick();
                    $grid = $this->screenGrid($tui, $terminal);
                    $this->assertNotNull($this->rowOf($grid, 'scrolled'), 'the wheel scrolled the terminal back');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testKittyKeyInputIsTranslatedForTheShell(): void
    {
        putenv('PATH='.PORTA_TEST_PRISTINE_PATH); // needs /bin/sh
        putenv('SHELL=/bin/sh');
        $project = new TempProject(['A.php' => "<?php\n"]);

        $terminal = $this->runIde(
            $project,
            $project->path('A.php'),
            [
                300 => function ($tui, $terminal): void {
                    $terminal->setKittyProtocolActive(true); // pretend the host terminal speaks kitty
                    $tui->handleInput("\x1b[96;5u"); // ctrl+` opens + focuses the terminal
                    $this->assertSame('terminal', $tui->getFocus()?->getId());
                    foreach (str_split('printf hi') as $ch) {
                        $tui->handleInput($ch);
                    }
                    $tui->handleInput("\x1b[104;1:3u"); // a stray key-release event → must be dropped
                    $tui->handleInput("\x1b[13u"); // kitty Enter → must become "\r" so the command runs
                },
                900 => function ($tui, $terminal): void {
                    // Await the shell's echo: spawning /bin/sh and round-tripping
                    // the command is real subprocess latency, and a fixed delay
                    // races it under pcov + machine load.
                    $this->await(static fn (): bool => str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'hi'), what: 'the shell echo');
                    // Only reachable if the release was dropped and Enter was translated.
                    $this->assertStringContainsString('hi', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testIntegratedTerminalOpensRunsAndCloses(): void
    {
        // Force a predictable shell so the panel spawns deterministically.
        putenv('PATH='.PORTA_TEST_PRISTINE_PATH); // needs /bin/sh
        putenv('SHELL=/bin/sh');
        $project = new TempProject(['A.php' => "<?php\n"]);

        $terminal = $this->runIde(
            $project,
            $project->path('A.php'),
            [
                300 => function ($tui): void {
                    $tui->handleInput("\x1b[96;5u"); // ctrl+` toggle terminal
                    $this->assertSame('terminal', $tui->getFocus()?->getId());
                    $this->assertTrue($tui->getById('terminal')->isOpen());
                    // Type a command into the shell.
                    foreach (str_split("echo hello-term\n") as $ch) {
                        $tui->handleInput($ch);
                    }
                },
                700 => function ($tui, $terminal): void {
                    // Await the shell's echo: spawning /bin/sh and round-tripping
                    // the command is real subprocess latency, and a fixed delay
                    // races it under pcov + machine load.
                    $this->await(static fn (): bool => str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'hello-term'), what: 'the shell echo');
                    // Hide the dock; the session stays alive and the editor regains focus.
                    $tui->handleInput("\x1b[96;5u");
                    $this->assertFalse($tui->getById('terminal')->isVisible());
                    $this->assertTrue($tui->getById('terminal')->isOpen(), 'the terminal tab survives the hide');
                    $this->assertSame('editor', $tui->getFocus()?->getId());
                },
                750 => function ($tui): void {
                    // Re-open: the still-running shell's panel comes back (no respawn).
                    $tui->handleInput("\x1b[96;5u");
                    $this->assertTrue($tui->getById('terminal')->isVisible());
                    // Now exit the shell so onExit updates the title.
                    foreach (str_split("exit\n") as $ch) {
                        $tui->handleInput($ch);
                    }
                },
                1000 => function ($tui, $terminal, $ide): void {
                    // The shell's exit ripples through PTY EOF + reap; await
                    // the MODEL (the session's own running flag), then force a
                    // frame and assert the paint — one indirection fewer than
                    // awaiting the rendered glyph, and a genuinely stuck exit
                    // still throws.
                    $sessions = \Porta\Tests\Support\Probe::get(Services::resolve($ide, \Porta\Run\RunController::class), 'sessions');
                    $this->await(static fn (): bool => false === ($sessions->active()?->isRunning() ?? true), timeout: 20.0, what: 'the shell exit to be reaped');
                    // Assert the label on the MODEL, not on the painted strip.
                    // aliveTabs() returns [] whenever no dock panel is visible,
                    // so a screen scrape here is asserting the marker AND the
                    // panel's visibility at one instant — and under parallel
                    // load that second half is what fails (500 forced frames,
                    // no glyph). The strip's rendering of labels is pinned by
                    // the DockTabsWidget tests; what this test owns is that an
                    // exited session is LABELLED stopped, with its code.
                    $labels = implode(' | ', array_column(Services::resolve($ide, BottomDock::class)->aliveTabs(), 'label'));
                    $this->assertStringContainsString(Glyphs::SESSION_STOPPED, $labels, 'the exited session is marked ○ stopped');
                    $this->assertStringContainsString('(0)', $labels, 'and carries its exit code — without the redundant "exited" prose');
                    $this->assertStringNotContainsString('exited', $labels, 'the "exited" word is gone; the glyph carries the state');
                },
            ],
        );

        $this->addToAssertionCount(1);
        putenv('SHELL');
    }

    public function testTerminalScrollAndPanelKeys(): void
    {
        putenv('SHELL=/bin/sh');
        $project = new TempProject(['A.php' => "<?php\n"]);

        $terminal = $this->runIde(
            $project,
            $project->path('A.php'),
            [
                300 => static function ($tui): void {
                    $tui->handleInput("\x1b[96;5u"); // open terminal
                    // Emit clearly more lines than the 12-row panel: the scroll
                    // clamp is lineCount − visibleRows, so a shorter transcript
                    // can leave nothing to scroll into.
                    foreach (str_split("for i in 1 2 3 4 5 6 7 8 9 10 11 12 13 14 15 16 17 18 19 20; do echo row-\$i; done\n") as $ch) {
                        $tui->handleInput($ch);
                    }
                },
                700 => function ($tui, $terminal): void {
                    // Await the shell's echo loop; a fixed delay races under load.
                    $this->await(static fn (): bool => str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'row-20'));
                    // Page up inside the await: late shell output (the trailing
                    // prompt) resets the scrollback to the live tail, so a
                    // single press can lose the race under load — keep pressing
                    // until the shell is quiet and the scrolled state sticks.
                    $this->await(static function () use ($tui, $terminal): bool {
                        $tui->handleInput("\x1b[5~"); // page up: scroll back
                        $tui->tick();

                        return str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'scrolled');
                    });
                    $this->assertStringContainsString('scrolled', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                    $tui->handleInput("\x1b[6~"); // page down
                    $tui->handleInput("\x1b"); // escape hides the dock; the session stays alive
                    $this->assertFalse($tui->getById('terminal')->isVisible());
                    $this->assertTrue($tui->getById('terminal')->isOpen(), 'the terminal tab survives the hide');
                },
            ],
        );

        $this->addToAssertionCount(1);
        putenv('SHELL');
    }

    public function testTerminalViaCommandPalette(): void
    {
        putenv('SHELL=/bin/sh');
        $project = new TempProject(['A.php' => "<?php\n"]);

        $terminal = $this->runIde(
            $project,
            $project->path('A.php'),
            [
                300 => static function ($tui): void {
                    $tui->handleInput("\x1b[97;6u"); // ctrl+shift+a palette
                    foreach (str_split('terminal') as $ch) {
                        $tui->handleInput($ch);
                    }
                },
                500 => function ($tui, $terminal): void {
                    $this->awaitPainted($tui, $terminal, 'Open Terminal');
                    $this->assertStringContainsString('Open Terminal', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                    $tui->handleInput("\r"); // run it
                },
                700 => function ($tui): void {
                    $this->assertTrue($tui->getById('terminal')->isOpen());
                },
            ],
        );

        $this->addToAssertionCount(1);
        putenv('SHELL');
    }

    public function testRunMenuRunsCurrentFileInTerminal(): void
    {
        putenv('PATH='.PORTA_TEST_PRISTINE_PATH); // needs php + a shell
        $project = new TempProject(['hello.php' => "<?php\necho \"run-output-ok\\n\";\n"]);

        $terminal = $this->runIde(
            $project,
            $project->path('hello.php'),
            [
                300 => function ($tui): void {
                    $tui->handleInput("\x1b[114;6u"); // ctrl+shift+r run menu
                    $this->assertTrue(EditorState::pickerOpen($tui->getById('editor')));
                },
                400 => function ($tui, $terminal): void {
                    $this->awaitPainted($tui, $terminal, 'Run current file: hello.php');
                    $this->assertStringContainsString('Run current file: hello.php', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                    $tui->handleInput("\r"); // run it -> terminal panel
                },
                450 => function ($tui): void {
                    $this->assertTrue($tui->getById('terminal')->isOpen());
                },
                900 => function ($tui, $terminal): void {
                    $this->awaitPainted($tui, $terminal, 'run-output-ok');
                    $this->assertStringContainsString('run-output-ok', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testRunLastRepeatsAndEmptyGuards(): void
    {
        $project = new TempProject(['hello.php' => "<?php\necho \"second-run\\n\";\n"]);

        $terminal = $this->runIde(
            $project,
            $project->path('hello.php'),
            [
                300 => function ($tui, $terminal): void {
                    // Run Last with no prior run flashes a hint.
                    $tui->handleInput("\x1b[21;2~"); // shift+f10
                    $this->awaitPainted($tui, $terminal, 'No previous run');
                    $this->assertStringContainsString('No previous run', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                    // Pick a run via the menu.
                    $tui->handleInput("\x1b[114;6u");
                },
                450 => static function ($tui): void {
                    $tui->handleInput("\r"); // run current file
                },
                700 => static function ($tui): void {
                    $tui->getById('terminal')->close();
                    $tui->handleInput("\x1b[21;2~"); // Run Last repeats it
                },
                750 => function ($tui): void {
                    $this->assertTrue($tui->getById('terminal')->isOpen());
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testRunMenuEmptyWhenNothingToRun(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['notes.txt' => "plain\n"]);

        $terminal = $this->runIde(
            $project,
            $project->path('notes.txt'),
            [
                300 => function ($tui, $terminal): void {
                    $tui->handleInput("\x1b[114;6u"); // ctrl+shift+r
                    $tui->tick();
                    $this->assertFalse(EditorState::pickerOpen($tui->getById('editor')));
                    $this->assertStringContainsString('Nothing to run', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                },
            ],
        );

        $this->addToAssertionCount(1);
    }
}
