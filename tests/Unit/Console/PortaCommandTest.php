<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Console;

use PHPUnit\Framework\TestCase;
use Porta\Console\PortaCommand;
use Porta\Console\Workspace;
use Porta\Container\Ansi;
use Porta\Container\LspMode;
use Porta\Tests\Support\Presence;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\TempProject;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

final class PortaCommandTest extends TestCase
{
    /** @var array{0: Workspace, 1: ?string, 2: LspMode, 3: Ansi}|null captured launch arguments */
    private ?array $launched = null;

    private function command(Presence $interactive = Presence::With): PortaCommand
    {
        $this->launched = null;

        return new PortaCommand(
            launch: function (Workspace $workspace, ?string $lsp, LspMode $noLsp, Ansi $ansi, \Closure $onBooted): void {
                $onBooted();
                $this->launched = [$workspace, $lsp, $noLsp, $ansi];
            },
            isInteractive: static fn (): bool => Presence::With === $interactive,
        );
    }

    public function testLaunchesADirectory(): void
    {
        $project = new TempProject(['a.php' => '']);
        $tester = new CommandTester($this->command());

        $this->assertSame(Command::SUCCESS, $tester->execute(['path' => $project->dir]));
        [$workspace, $lsp, $noLsp] = $this->launched;
        $this->assertSame(\realpath($project->dir), $workspace->rootDir);
        $this->assertNull($workspace->initialFile);
        $this->assertNull($lsp);
        $this->assertSame(LspMode::Enabled, $noLsp);
    }

    public function testFileLineShorthandOpensAtLine(): void
    {
        $project = new TempProject(['composer.json' => '{}', 'src/Foo.php' => "<?php\n"]);
        $tester = new CommandTester($this->command());

        $tester->execute(['path' => $project->path('src/Foo.php').':17']);
        [$workspace] = $this->launched;
        $this->assertSame(\realpath($project->path('src/Foo.php')), $workspace->initialFile);
        $this->assertSame(17, $workspace->line);
    }

    public function testLineOptionOverridesShorthand(): void
    {
        $project = new TempProject(['a.php' => '']);
        $tester = new CommandTester($this->command());

        $tester->execute(['path' => $project->path('a.php').':5', '--line' => '99']);
        $this->assertSame(99, $this->launched[0]->line, '--line wins over file:line');
    }

    public function testTheLspOptionReachesTheLauncher(): void
    {
        $project = new TempProject(['a.php' => '']);
        $tester = new CommandTester($this->command());

        $tester->execute(['path' => $project->dir, '--lsp' => 'my-ls --stdio']);
        [, $lsp, $mode] = $this->launched;
        $this->assertSame('my-ls --stdio', $lsp);
        $this->assertSame(LspMode::Enabled, $mode);
    }

    public function testNoLspDisablesTheLanguageServer(): void
    {
        $project = new TempProject(['a.php' => '']);
        $tester = new CommandTester($this->command());

        $tester->execute(['path' => $project->dir, '--no-lsp' => true]);
        [, $lsp, $mode] = $this->launched;
        $this->assertNull($lsp);
        $this->assertSame(LspMode::Disabled, $mode);
    }

    public function testLspTogetherWithNoLspIsInvalid(): void
    {
        $project = new TempProject(['a.php' => '']);
        $tester = new CommandTester($this->command());

        $status = $tester->execute(['path' => $project->dir, '--lsp' => 'my-ls', '--no-lsp' => true]);

        $this->assertSame(Command::INVALID, $status);
        $this->assertNull($this->launched, 'a contradictory invocation never launches');
        $this->assertStringContainsString('--lsp and --no-lsp contradict each other', $tester->getDisplay());
    }

    public function testANonNumericLineIsInvalid(): void
    {
        $project = new TempProject(['a.php' => '']);
        $tester = new CommandTester($this->command());

        $status = $tester->execute(['path' => $project->path('a.php'), '--line' => 'abc']);

        $this->assertSame(Command::INVALID, $status);
        $this->assertNull($this->launched, 'the old cast silently opened line 1 instead');
        $this->assertStringContainsString('--line expects a line number, got "abc"', $tester->getDisplay());
    }

    public function testFailuresSpeakOnStderrWhenTheOutputHasOne(): void
    {
        $tester = new CommandTester($this->command());

        $status = $tester->execute(['path' => '/does/not/exist'], ['capture_stderr_separately' => true]);

        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString('no such file or directory', $tester->getErrorOutput());
        $this->assertSame('', $tester->getDisplay(), 'a piped stdout stays clean');
    }

    public function testOutputDecorationDrivesTheColorMode(): void
    {
        $project = new TempProject(['a.php' => '']);

        $tester = new CommandTester($this->command());
        $tester->execute(['path' => $project->dir], ['decorated' => false]);
        $this->assertSame(Ansi::Disabled, $this->launched[3], '--no-ansi (undecorated output) launches in monochrome');

        $tester = new CommandTester($this->command());
        $tester->execute(['path' => $project->dir], ['decorated' => true]);
        $this->assertSame(Ansi::Enabled, $this->launched[3], '--ansi (decorated output) launches in color');
    }

    public function testNonInteractiveTerminalRefusesToLaunch(): void
    {
        $project = new TempProject(['a.php' => '']);
        $tester = new CommandTester($this->command(interactive: Presence::Without));

        $this->assertSame(Command::FAILURE, $tester->execute(['path' => $project->dir]));
        $this->assertNull($this->launched, 'the IDE is never launched');
        $this->assertStringContainsString('an interactive terminal is required', $tester->getDisplay());
    }

    public function testMissingPathFails(): void
    {
        $tester = new CommandTester($this->command());

        $this->assertSame(Command::FAILURE, $tester->execute(['path' => '/does/not/exist']));
        $this->assertNull($this->launched);
        $this->assertStringContainsString('no such file or directory', $tester->getDisplay());
    }

    public function testACrashedLaunchReportsTheRemedyInsteadOfATrace(): void
    {
        $project = new TempProject(['a.php' => '']);
        $command = new PortaCommand(
            launch: static function (Workspace $workspace, ?string $lsp, LspMode $mode, Ansi $ansi, \Closure $onBooted): void {
                throw new \RuntimeException('You have requested a synthetic service ("renderer").');
            },
            isInteractive: static fn (): bool => true,
        );
        $tester = new CommandTester($command);

        $this->assertSame(Command::FAILURE, $tester->execute(['path' => $project->dir]));
        $this->assertStringContainsString('porta: fatal: You have requested a synthetic service', $tester->getDisplay());
        $this->assertStringContainsString('delete ~/.cache/porta', $tester->getDisplay(), 'the remedy is named, not just the failure');
        $this->assertStringNotContainsString('porta crashed', $tester->getDisplay(), 'a boot failure never falls through to the mid-session arm');
    }

    public function testACrashAfterBootBlamesTheSessionNotTheCache(): void
    {
        $project = new TempProject(['a.php' => '']);
        $command = new PortaCommand(
            launch: static function (Workspace $workspace, ?string $lsp, LspMode $mode, Ansi $ansi, \Closure $onBooted): void {
                $onBooted(); // the IDE constructed fine — the session died later
                throw new \RuntimeException('poll tick exploded');
            },
            isInteractive: static fn (): bool => true,
        );
        $tester = new CommandTester($command);

        $this->assertSame(Command::FAILURE, $tester->execute(['path' => $project->dir]));
        $this->assertStringContainsString('porta: fatal: poll tick exploded', $tester->getDisplay());
        $this->assertStringNotContainsString('delete ~/.cache/porta', $tester->getDisplay(), 'a mid-session crash must not send the user to delete a healthy cache');
        $this->assertStringContainsString('.porta/error.log', $tester->getDisplay());
        $this->assertStringContainsString('poll tick exploded', (string) \file_get_contents($project->dir.'/.porta/error.log'), 'the trace survives the crash');
    }

    public function testVerboseModeRethrowsForTheFullTrace(): void
    {
        $project = new TempProject(['a.php' => '']);
        $command = new PortaCommand(
            launch: static function (Workspace $workspace, ?string $lsp, LspMode $mode, Ansi $ansi, \Closure $onBooted): void {
                throw new \RuntimeException('boom');
            },
            isInteractive: static fn (): bool => true,
        );
        $tester = new CommandTester($command);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('boom');
        $tester->execute(['path' => $project->dir], ['verbosity' => OutputInterface::VERBOSITY_VERBOSE]);
    }

    public function testProductionDefaultsAreWiredWithoutBootingTheIde(): void
    {
        // Construct with the real defaults, then call only the interactivity
        // check — never execute(), so the real IDE launcher is not invoked.
        // Under a normal (non-TTY) test run both STDIN and STDOUT are
        // non-interactive, so the real `&&` check must return exactly false —
        // a plain assertIsBool() doesn't distinguish it from a mutated `||`
        // or a full negation, both of which would also return a bool, just
        // the wrong one.
        $command = new PortaCommand();
        $isInteractive = Probe::get($command, 'isInteractive');
        $this->assertFalse($isInteractive(), 'neither stream is a TTY under phpunit');
        $this->assertIsCallable(Probe::get($command, 'launch'));
    }

    public function testTheErrorMessageIsPrefixedWithPortaNotJustContainingTheReason(): void
    {
        $project = new TempProject(['a.php' => '']);
        $tester = new CommandTester($this->command());

        $status = $tester->execute(['path' => $project->path('does-not-exist')], ['capture_stderr_separately' => true]);

        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString('porta: no such file or directory', $tester->getErrorOutput(), 'the prefix comes first, not appended after the reason');
    }

    public function testAZeroLineOptionClampsUpToLineOneNotZero(): void
    {
        $project = new TempProject(['a.php' => '']);
        $tester = new CommandTester($this->command());

        $tester->execute(['path' => $project->dir, '--line' => '0']);

        $this->assertSame(1, $this->launched[0]->line, 'max(1, ...) floors at exactly 1, not 0 or 2');
    }
}
