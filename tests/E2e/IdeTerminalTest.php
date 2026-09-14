<?php

declare(strict_types=1);

namespace Porta\Tests\E2e;

use PHPUnit\Framework\TestCase;
use Porta\Ide;
use Porta\Run\RunController;
use Porta\Terminal\TerminalSession;
use Porta\Tests\Support\EditorState;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\PreservesShell;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Render\Renderer;

/**
 * Terminal multiplexing end-to-end against a real shell: multiple sessions,
 * per-session input isolation, tab cycling, close-by-click, and a run
 * configuration getting its own tab beside the shell.
 */
final class IdeTerminalTest extends TestCase
{
    use PreservesPath;
    use PreservesShell;
    use RunsIde;

    /** @return list<\Porta\Terminal\TerminalSession> */
    private function sessions(mixed $ide): array
    {
        $sessions = Probe::get(Services::resolve($ide, RunController::class), 'sessions');

        return $sessions->all();
    }

    private function bufferText(TerminalSession $session): string
    {
        return \implode("\n", \array_map(AnsiUtils::stripAnsiCodes(...), $session->buffer->lines));
    }

    public function testTwoSessionsIsolateInputAndCycle(): void
    {
        \putenv('SHELL=/bin/sh');
        $project = new TempProject(['A.php' => "<?php\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                100 => function ($tui, $terminal, $ide): void {
                    $editor = $tui->getById('editor');
                    $this->await(
                        static fn (): bool => null !== $editor->getFilePath(),
                        what: 'the editor to open its file',
                    );

                    $tui->handleInput("\x1b[96;5u"); // ctrl+` opens the first shell
                    $this->assertSame('terminal', $tui->getFocus()?->getId());

                    // The session is registered when the PTY spawns, not when
                    // the key is handled — 200 ms used to stand in for that.
                    $this->await(
                        fn (): bool => 1 === \count($this->sessions($ide)),
                        what: 'the first shell session to register',
                    );

                    // Type a marker into shell 1.
                    foreach (\str_split("echo AAA-marker\n") as $ch) {
                        $tui->handleInput($ch);
                    }
                    $this->await(fn (): bool => \str_contains($this->bufferText($this->sessions($ide)[0]), 'AAA-marker'));

                    // Ctrl+Shift+` opens a second shell (Ide-scope key).
                    $tui->handleInput("\x1b[96;6u");
                },
                200 => function ($tui, $terminal, $ide): void {
                    $this->await(
                        fn (): bool => 2 === \count($this->sessions($ide)),
                        what: 'the second shell session to register',
                    );
                    $sessions = $this->sessions($ide);

                    // Type into shell 2 — the marker must not appear in shell 1.
                    foreach (\str_split("echo BBB-marker\n") as $ch) {
                        $tui->handleInput($ch);
                    }
                    $this->await(fn (): bool => \str_contains($this->bufferText($this->sessions($ide)[1]), 'BBB-marker'));
                    $this->assertStringNotContainsString('BBB-marker', $this->bufferText($sessions[0]), 'input reached only the active shell');

                    // The dock strip shows both sessions as first-class tabs.
                    $renderer = Services::resolve($ide, Renderer::class);
                    $stripRect = $renderer->getWidgetRect($tui->getById('dock-tabs'));
                    $strip = AnsiUtils::stripAnsiCodes($tui->getById('dock-tabs')->render(new RenderContext($stripRect->columns, 1))[0]);
                    $this->assertSame(2, \substr_count($strip, needle: 'sh'), 'both shell tabs render on the dock strip');
                },
                300 => function ($tui, $terminal, Ide $ide): void {
                    // Ctrl+Page Up cycles back to shell 1 (widget-scope, terminal focused).
                    $tui->handleInput("\x1b[5^");
                    $run = Services::resolve($ide, RunController::class);
                    $sessionsOf = static fn (): mixed => Probe::get($run, 'sessions');
                    $this->assertSame('shell:1', $sessionsOf()->active()?->id, 'cycled to the first shell');

                    // Click shell 2's dock-strip tab label to activate it (mouse select path).
                    $renderer = Services::resolve($ide, Renderer::class);
                    $rect = $renderer->getWidgetRect($tui->getById('dock-tabs'));
                    $strip = AnsiUtils::stripAnsiCodes($tui->getById('dock-tabs')->render(new RenderContext($rect->columns, 1))[0]);
                    // The second "sh" occurrence is shell 2's tab.
                    $secondTab = (int) \mb_strpos($strip, needle: 'sh', offset: (int) \mb_strpos($strip, needle: 'sh') + 1);
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $rect->col + $secondTab + 1, $rect->row + 1));
                    $this->assertSame('shell:2', $sessionsOf()->active()?->id, 'clicking the tab activated shell 2');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testCloseSessionByTabClickThenCloseLastClosesPanel(): void
    {
        \putenv('SHELL=/bin/sh');
        $project = new TempProject(['A.php' => "<?php\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                300 => static function ($tui): void {
                    $tui->handleInput("\x1b[96;5u"); // shell 1
                    $tui->handleInput("\x1b[96;6u"); // shell 2
                },
                600 => function ($tui, $terminal, $ide): void {
                    $tui->tick();
                    $this->assertCount(2, $this->sessions($ide));
                    $sessionTwoProcess = $this->sessions($ide)[1]->process();

                    // Click the × of the first (inactive) session tab on the dock strip.
                    $renderer = Services::resolve($ide, Renderer::class);
                    $rect = $renderer->getWidgetRect($tui->getById('dock-tabs'));
                    $strip = AnsiUtils::stripAnsiCodes($tui->getById('dock-tabs')->render(new RenderContext($rect->columns, 1))[0]);
                    $closeCol = (int) \mb_strpos($strip, needle: '×');
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $rect->col + $closeCol + 1, $rect->row + 1));

                    $this->assertCount(1, $this->sessions($ide), 'the clicked tab closed');
                    $this->assertSame('shell:2', $this->sessions($ide)[0]->id, 'the survivor is shell 2');
                    $this->assertTrue($tui->getById('terminal')->isOpen(), 'the panel stays open with one session left');
                    self::assertNotNull($sessionTwoProcess);
                },
                900 => function ($tui, $terminal, $ide): void {
                    $tui->tick();
                    // Close the last session via ctrl+shift+w → the panel closes.
                    $tui->handleInput("\x1b[119;6u");
                    $this->assertCount(0, $this->sessions($ide));
                    $this->assertFalse($tui->getById('terminal')->isOpen(), 'closing the last session closed the panel');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testSessionTabDragReorders(): void
    {
        \putenv('SHELL=/bin/sh');
        $project = new TempProject(['A.php' => "<?php\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                300 => static function ($tui): void {
                    $tui->handleInput("\x1b[96;5u"); // shell 1
                    $tui->handleInput("\x1b[96;6u"); // shell 2
                },
                600 => function ($tui, $terminal, $ide): void {
                    $tui->tick();
                    $ids = fn (): array => \array_map(static fn (TerminalSession $s): string => $s->id, $this->sessions($ide));
                    $this->assertSame(['shell:1', 'shell:2'], $ids());

                    $renderer = Services::resolve($ide, Renderer::class);
                    $rect = $renderer->getWidgetRect($tui->getById('dock-tabs'));
                    $strip = AnsiUtils::stripAnsiCodes($tui->getById('dock-tabs')->render(new RenderContext($rect->columns, 1))[0]);
                    $firstTab = (int) \mb_strpos($strip, needle: 'sh');
                    $secondTab = (int) \mb_strpos($strip, needle: 'sh', offset: $firstTab + 1);

                    // Press shell 1's dock tab body, drag it over shell 2's slot, release.
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $rect->col + $firstTab + 1, $rect->row + 1));
                    $tui->handleInput(\sprintf("\x1b[<32;%d;%dM", $rect->col + $secondTab + 1, $rect->row + 1));
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dm", $rect->col + $secondTab + 1, $rect->row + 1));
                    $this->assertSame(['shell:2', 'shell:1'], $ids(), 'the drag reordered the session tabs on the dock strip');

                    $sessions = Probe::get(Services::resolve($ide, RunController::class), 'sessions');
                    $this->assertSame('shell:1', $sessions->active()?->id, 'the pressed session stays active through its move');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testSessionTabContextMenuOffersNewAndCloseSession(): void
    {
        \putenv('SHELL=/bin/sh');
        $project = new TempProject(['A.php' => "<?php\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                300 => static function ($tui): void {
                    $tui->handleInput("\x1b[96;5u"); // shell 1
                },
                600 => function ($tui, $terminal, Ide $ide): void {
                    $tui->tick();
                    $renderer = Services::resolve($ide, Renderer::class);
                    $rect = $renderer->getWidgetRect($tui->getById('dock-tabs'));
                    $strip = AnsiUtils::stripAnsiCodes($tui->getById('dock-tabs')->render(new RenderContext($rect->columns, 1))[0]);

                    $terminal->clearOutput();
                    $tui->handleInput(\sprintf("\x1b[<2;%d;%dM", $rect->col + (int) \mb_strpos($strip, needle: 'sh') + 1, $rect->row + 1));
                    $this->assertTrue($tui->getById('context-menu')->isOpen(), 'right-clicking a session tab opened its menu');
                    $this->await(static fn (): bool => \str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'New Session'));
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('New Session', $screen);
                    $this->assertStringContainsString('Close Session', $screen);

                    $tui->handleInput("\r"); // New Session leads the list
                },
                1000 => function ($tui, $terminal, $ide): void {
                    $tui->tick();
                    $this->assertCount(2, $this->sessions($ide), 'the menu spawned a sibling session');

                    // Keyboard parity: Ctrl+F10 while the terminal panel holds
                    // the focus anchors the same menu at the active session's tab.
                    $this->assertSame('terminal', $tui->getFocus()?->getId());
                    $terminal->clearOutput();
                    $tui->handleInput("\x1b[21;5~");
                    $this->assertTrue($tui->getById('context-menu')->isOpen(), 'Ctrl+F10 opened the session menu');
                    $this->await(static fn (): bool => \str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'Close Session'));
                    $tui->handleInput("\x1b");
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testRunConfigurationGetsItsOwnTabBesideTheShellAndReRunsInPlace(): void
    {
        \putenv('SHELL=/bin/sh');
        $project = new TempProject(['run.php' => "<?php\necho 'RAN-'.getmypid().PHP_EOL;\n"]);

        $this->runIde(
            $project,
            $project->path('run.php'),
            [
                300 => function ($tui): void {
                    $tui->handleInput("\x1b[96;5u"); // a shell session first
                    $this->assertSame('terminal', $tui->getFocus()?->getId());
                },
                500 => function ($tui, $terminal, $ide): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[114;6u"); // ctrl+shift+r → Run menu
                    $this->await(static fn (): bool => EditorState::pickerOpen($tui->getById('editor')));
                    $tui->handleInput("\r"); // "Run current file: run.php" leads the list
                },
                900 => function ($tui, $terminal, $ide): void {
                    $tui->tick();
                    $sessions = $this->sessions($ide);
                    $ids = \array_map(static fn (TerminalSession $s): string => $s->id, $sessions);
                    $this->assertContains('shell:1', $ids, 'the shell survived the run');
                    $this->assertContains('run:Run current file: run.php', $ids, 'the run got its own session');

                    $run = null;
                    foreach ($sessions as $session) {
                        if (!\str_starts_with($session->id, 'run:')) {
                            continue;
                        }

                        $run = $session;
                    }
                    self::assertNotNull($run);
                    $this->await(fn (): bool => \str_contains($this->bufferText($run), 'RAN-'));
                    $firstOutput = $this->bufferText($run);
                    $this->await(fn (): bool => \str_contains($this->bufferText($run), '(0)') || null === $run->process() || !$run->isRunning());

                    // Re-run: only this session's process is replaced (its buffer clears).
                    $countBefore = \count($this->sessions($ide));
                    $tui->handleInput("\x1b[21;2~"); // shift+f10 → run last
                    $this->await(fn (): bool => \str_contains($this->bufferText($run), 'RAN-') && $this->bufferText($run) !== $firstOutput, 2.0);
                    $this->assertCount($countBefore, $this->sessions($ide), 're-run reused the run session, no new tab');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }
}
