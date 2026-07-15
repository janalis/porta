<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Console;

use PHPUnit\Framework\TestCase;
use Porta\Console\PortaCommand;
use Porta\Console\Workspace;
use Porta\Container\Ansi;
use Porta\Container\LspMode;
use Porta\Tests\Support\Presence;
use Porta\Tests\Support\TempProject;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class PortaCommandTest extends TestCase
{
    /** @var array{0: Workspace, 1: ?string, 2: LspMode, 3: Ansi}|null captured launch arguments */
    private ?array $launched = null;

    private function command(Presence $interactive = Presence::With): PortaCommand
    {
        $this->launched = null;

        return new PortaCommand(
            launch: function (Workspace $workspace, ?string $lsp, LspMode $noLsp, Ansi $ansi): void {
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
        $this->assertSame(realpath($project->dir), $workspace->rootDir);
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
        $this->assertSame(realpath($project->path('src/Foo.php')), $workspace->initialFile);
        $this->assertSame(17, $workspace->line);
    }

    public function testLineOptionOverridesShorthand(): void
    {
        $project = new TempProject(['a.php' => '']);
        $tester = new CommandTester($this->command());

        $tester->execute(['path' => $project->path('a.php').':5', '--line' => '99']);
        $this->assertSame(99, $this->launched[0]->line, '--line wins over file:line');
    }

    public function testLspOptionsReachTheLauncher(): void
    {
        $project = new TempProject(['a.php' => '']);
        $tester = new CommandTester($this->command());

        $tester->execute(['path' => $project->dir, '--lsp' => 'my-ls --stdio', '--no-lsp' => true]);
        [, $lsp, $noLsp] = $this->launched;
        $this->assertSame('my-ls --stdio', $lsp);
        $this->assertSame(LspMode::Disabled, $noLsp);
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

    public function testProductionDefaultsAreWiredWithoutBootingTheIde(): void
    {
        // Construct with the real defaults, then call only the interactivity
        // check — never execute(), so the real IDE launcher is not invoked.
        $command = new PortaCommand();
        $isInteractive = new \ReflectionProperty($command, 'isInteractive')->getValue($command);
        $this->assertIsBool($isInteractive());
        $this->assertIsCallable(new \ReflectionProperty($command, 'launch')->getValue($command));
    }
}
