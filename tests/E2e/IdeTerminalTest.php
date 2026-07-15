<?php

declare(strict_types=1);

namespace Porta\Tests\E2e;

use PHPUnit\Framework\TestCase;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\TempProject;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * Terminal multiplexing end-to-end against a real shell: multiple sessions,
 * per-session input isolation, tab cycling, close-by-click, and a run
 * configuration getting its own tab beside the shell.
 */
final class IdeTerminalTest extends TestCase
{
    use RunsIde;

    private string $originalPath;

    protected function setUp(): void
    {
        $this->originalPath = (string) getenv('PATH');
    }

    protected function tearDown(): void
    {
        putenv('PATH='.$this->originalPath);
        putenv('SHELL');
    }

    /** @return list<\Porta\Terminal\TerminalSession> */
    private function sessions(mixed $ide): array
    {
        $run = new \ReflectionProperty($ide, 'run')->getValue($ide);
        $sessions = new \ReflectionProperty($run, 'sessions')->getValue($run);
        \assert($sessions instanceof \Porta\Terminal\TerminalSessions, description: 'run sessions property holds a TerminalSessions instance');

        return $sessions->all();
    }

    private function bufferText(\Porta\Terminal\TerminalSession $session): string
    {
        return implode("\n", array_map(AnsiUtils::stripAnsiCodes(...), $session->buffer->getLines()));
    }

    public function testTwoSessionsIsolateInputAndCycle(): void
    {
        putenv('PATH='.$this->originalPath);
        putenv('SHELL=/bin/sh');
        $project = new TempProject(['A.php' => "<?php\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                300 => function ($tui): void {
                    $tui->handleInput("\x1b[96;5u"); // ctrl+` opens the first shell
                    $this->assertSame('terminal', $tui->getFocus()?->getId());
                },
                500 => function ($tui, $terminal, $ide): void {
                    $this->assertCount(1, $this->sessions($ide));
                    // Type a marker into shell 1.
                    foreach (str_split("echo AAA-marker\n") as $ch) {
                        $tui->handleInput($ch);
                    }
                    $this->await(fn (): bool => str_contains($this->bufferText($this->sessions($ide)[0]), 'AAA-marker'));

                    // Ctrl+Shift+` opens a second shell (Ide-scope key).
                    $tui->handleInput("\x1b[96;6u");
                },
                800 => function ($tui, $terminal, $ide): void {
                    $tui->tick();
                    $sessions = $this->sessions($ide);
                    $this->assertCount(2, $sessions, 'a second shell session opened');

                    // Type into shell 2 — the marker must not appear in shell 1.
                    foreach (str_split("echo BBB-marker\n") as $ch) {
                        $tui->handleInput($ch);
                    }
                    $this->await(fn (): bool => str_contains($this->bufferText($this->sessions($ide)[1]), 'BBB-marker'));
                    $this->assertStringNotContainsString('BBB-marker', $this->bufferText($sessions[0]), 'input reached only the active shell');

                    // The tab strip shows both sessions.
                    $strip = AnsiUtils::stripAnsiCodes($tui->getById('terminal')->render(new \Symfony\Component\Tui\Render\RenderContext(100, 14))[0]);
                    $this->assertSame(2, substr_count($strip, needle: 'sh'), 'both shell tabs render');
                },
                1000 => function ($tui, $terminal, $ide): void {
                    $tui->tick();
                    // Ctrl+Page Up cycles back to shell 1 (widget-scope, terminal focused).
                    $tui->handleInput("\x1b[5^");
                    $run = new \ReflectionProperty($ide, 'run')->getValue($ide);
                    $sessionsOf = static fn () => new \ReflectionProperty($run, 'sessions')->getValue($run);
                    $this->assertSame('shell:1', $sessionsOf()->active()?->id, 'cycled to the first shell');

                    // Click shell 2's tab label to activate it (mouse select path).
                    $renderer = new \ReflectionProperty($ide, 'renderer')->getValue($ide);
                    $rect = $renderer->getWidgetRect($tui->getById('terminal'));
                    $strip = AnsiUtils::stripAnsiCodes($tui->getById('terminal')->render(new \Symfony\Component\Tui\Render\RenderContext($rect->columns, 14))[0]);
                    // The second "sh" occurrence is shell 2's tab.
                    $secondTab = (int) mb_strpos($strip, needle: 'sh', offset: (int) mb_strpos($strip, needle: 'sh') + 1);
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $rect->col + $secondTab + 1, $rect->row + 1));
                    $this->assertSame('shell:2', $sessionsOf()->active()?->id, 'clicking the tab activated shell 2');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testCloseSessionByTabClickThenCloseLastClosesPanel(): void
    {
        putenv('PATH='.$this->originalPath);
        putenv('SHELL=/bin/sh');
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

                    // Click the × of the first (inactive) tab.
                    $renderer = new \ReflectionProperty($ide, 'renderer')->getValue($ide);
                    $rect = $renderer->getWidgetRect($tui->getById('terminal'));
                    $strip = AnsiUtils::stripAnsiCodes($tui->getById('terminal')->render(new \Symfony\Component\Tui\Render\RenderContext($rect->columns, 14))[0]);
                    $closeCol = (int) mb_strpos($strip, needle: '×');
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

    public function testRunConfigurationGetsItsOwnTabBesideTheShellAndReRunsInPlace(): void
    {
        putenv('PATH='.$this->originalPath);
        putenv('SHELL=/bin/sh');
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
                    $this->await(static fn (): bool => $tui->getById('editor')->isOverlayOpen());
                    $tui->handleInput("\r"); // "Run current file: run.php" leads the list
                },
                900 => function ($tui, $terminal, $ide): void {
                    $tui->tick();
                    $sessions = $this->sessions($ide);
                    $ids = array_map(static fn ($s) => $s->id, $sessions);
                    $this->assertContains('shell:1', $ids, 'the shell survived the run');
                    $this->assertContains('run:Run current file: run.php', $ids, 'the run got its own session');

                    $run = null;
                    foreach ($sessions as $session) {
                        if (!str_starts_with($session->id, 'run:')) {
                            continue;
                        }

                        $run = $session;
                    }
                    self::assertNotNull($run);
                    $this->await(fn (): bool => str_contains($this->bufferText($run), 'RAN-'));
                    $firstOutput = $this->bufferText($run);
                    $this->await(fn (): bool => str_contains($this->bufferText($run), '— exited (0)') || null === $run->process() || !$run->isRunning());

                    // Re-run: only this session's process is replaced (its buffer clears).
                    $countBefore = \count($this->sessions($ide));
                    $tui->handleInput("\x1b[21;2~"); // shift+f10 → run last
                    $this->await(fn (): bool => str_contains($this->bufferText($run), 'RAN-') && $this->bufferText($run) !== $firstOutput, 2.0);
                    $this->assertCount($countBefore, $this->sessions($ide), 're-run reused the run session, no new tab');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }
}
