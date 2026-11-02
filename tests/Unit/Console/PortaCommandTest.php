<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\Config\WorkspaceTrust;
use Porta\Console\PortaCommand;
use Porta\Console\Workspace;
use Porta\Container\Ansi;
use Porta\Container\LspMode;
use Porta\Container\ToolMode;
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

    /** What the fake IDE exits with. */
    private int $exitCode = 0;

    private function command(Presence $interactive = Presence::With, ?WorkspaceTrust $trust = null): PortaCommand
    {
        $this->launched = null;

        return new PortaCommand(
            launch: function (Workspace $workspace, ?string $lsp, LspMode $noLsp, Ansi $ansi, \Closure $onBooted): int {
                $onBooted();
                $this->launched = [$workspace, $lsp, $noLsp, $ansi];

                return $this->exitCode;
            },
            isInteractive: static fn (): bool => Presence::With === $interactive,
            trust: $trust,
        );
    }

    public function testLaunchesADirectory(): void
    {
        $project = new TempProject(['a.php' => '']);
        $tester = new CommandTester($this->command());

        $this->assertSame(Command::SUCCESS, $tester->execute(['path' => [$project->dir]]));
        [$workspace, $lsp, $noLsp] = $this->launched;
        $this->assertSame(\realpath($project->dir), $workspace->rootDir);
        $this->assertNull($workspace->initialFile);
        $this->assertNull($lsp);
        $this->assertSame(LspMode::Enabled, $noLsp);
        $this->assertSame([], $workspace->plugins);
    }

    /** --plugin turns bundled plugins on for the project it is given with — `porta assist`'s copy opens with assist so. */
    public function testPluginOptionsReachTheWorkspace(): void
    {
        $project = new TempProject(['a.php' => '']);
        $tester = new CommandTester($this->command());

        $this->assertSame(Command::SUCCESS, $tester->execute(['path' => [$project->dir], '--plugin' => ['assist', 'todo']]));
        [$workspace] = $this->launched;
        $this->assertSame(['assist', 'todo'], $workspace->plugins);
    }

    public function testFileLineShorthandOpensAtLine(): void
    {
        $project = new TempProject(['composer.json' => '{}', 'src/Foo.php' => "<?php\n"]);
        $tester = new CommandTester($this->command());

        $tester->execute(['path' => [$project->path('src/Foo.php').':17']]);
        [$workspace] = $this->launched;
        $this->assertSame(\realpath($project->path('src/Foo.php')), $workspace->initialFile);
        $this->assertSame(17, $workspace->line);
    }

    public function testLineOptionOverridesShorthand(): void
    {
        $project = new TempProject(['a.php' => '']);
        $tester = new CommandTester($this->command());

        $tester->execute(['path' => [$project->path('a.php').':5'], '--line' => '99']);
        $this->assertSame(99, $this->launched[0]->line, '--line wins over file:line');
    }

    public function testTheLspOptionReachesTheLauncher(): void
    {
        $project = new TempProject(['a.php' => '']);
        $tester = new CommandTester($this->command());

        $tester->execute(['path' => [$project->dir], '--lsp' => 'my-ls --stdio']);
        [, $lsp, $mode] = $this->launched;
        $this->assertSame('my-ls --stdio', $lsp);
        $this->assertSame(LspMode::Enabled, $mode);
    }

    public function testNoLspDisablesTheLanguageServer(): void
    {
        $project = new TempProject(['a.php' => '']);
        $tester = new CommandTester($this->command());

        $tester->execute(['path' => [$project->dir], '--no-lsp' => true]);
        [, $lsp, $mode] = $this->launched;
        $this->assertNull($lsp);
        $this->assertSame(LspMode::Disabled, $mode);
    }

    public function testLspTogetherWithNoLspIsInvalid(): void
    {
        $project = new TempProject(['a.php' => '']);
        $tester = new CommandTester($this->command());

        $status = $tester->execute(['path' => [$project->dir], '--lsp' => 'my-ls', '--no-lsp' => true]);

        $this->assertSame(Command::INVALID, $status);
        $this->assertNull($this->launched, 'a contradictory invocation never launches');
        $this->assertStringContainsString('--lsp and --no-lsp contradict each other', $tester->getDisplay());
    }

    public function testANonNumericLineIsInvalid(): void
    {
        $project = new TempProject(['a.php' => '']);
        $tester = new CommandTester($this->command());

        $status = $tester->execute(['path' => [$project->path('a.php')], '--line' => 'abc']);

        $this->assertSame(Command::INVALID, $status);
        $this->assertNull($this->launched, 'the old cast silently opened line 1 instead');
        $this->assertStringContainsString('--line expects a line number, got "abc"', $tester->getDisplay());
    }

    public function testFailuresSpeakOnStderrWhenTheOutputHasOne(): void
    {
        $tester = new CommandTester($this->command());

        $status = $tester->execute(['path' => ['/does/not/exist']], ['capture_stderr_separately' => true]);

        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString('no such file or directory', $tester->getErrorOutput());
        $this->assertSame('', $tester->getDisplay(), 'a piped stdout stays clean');
    }

    public function testOutputDecorationDrivesTheColorMode(): void
    {
        $project = new TempProject(['a.php' => '']);

        $tester = new CommandTester($this->command());
        $tester->execute(['path' => [$project->dir]], ['decorated' => false]);
        $this->assertSame(Ansi::Disabled, $this->launched[3], '--no-ansi (undecorated output) launches in monochrome');

        $tester = new CommandTester($this->command());
        $tester->execute(['path' => [$project->dir]], ['decorated' => true]);
        $this->assertSame(Ansi::Enabled, $this->launched[3], '--ansi (decorated output) launches in color');
    }

    public function testNonInteractiveTerminalRefusesToLaunch(): void
    {
        $project = new TempProject(['a.php' => '']);
        $tester = new CommandTester($this->command(interactive: Presence::Without));

        $this->assertSame(Command::FAILURE, $tester->execute(['path' => [$project->dir]]));
        $this->assertNull($this->launched, 'the IDE is never launched');
        $this->assertStringContainsString('an interactive terminal is required', $tester->getDisplay());
    }

    public function testMissingPathFails(): void
    {
        $tester = new CommandTester($this->command());

        $this->assertSame(Command::FAILURE, $tester->execute(['path' => ['/does/not/exist']]));
        $this->assertNull($this->launched);
        $this->assertStringContainsString('no such file or directory', $tester->getDisplay());
    }

    public function testACrashedLaunchReportsTheRemedyInsteadOfATrace(): void
    {
        $project = new TempProject(['a.php' => '']);
        $command = new PortaCommand(
            launch: static function (Workspace $workspace, ?string $lsp, LspMode $mode, Ansi $ansi, \Closure $onBooted): never {
                throw new \RuntimeException('You have requested a synthetic service ("renderer").');
            },
            isInteractive: static fn (): bool => true,
        );
        $tester = new CommandTester($command);

        $this->assertSame(Command::FAILURE, $tester->execute(['path' => [$project->dir]]));
        $this->assertStringContainsString('porta: fatal: You have requested a synthetic service', $tester->getDisplay());
        $this->assertStringContainsString('delete ~/.cache/porta', $tester->getDisplay(), 'the remedy is named, not just the failure');
        $this->assertStringNotContainsString('porta crashed', $tester->getDisplay(), 'a boot failure never falls through to the mid-session arm');
    }

    public function testACrashAfterBootBlamesTheSessionNotTheCache(): void
    {
        $project = new TempProject(['a.php' => '']);
        $command = new PortaCommand(
            launch: static function (Workspace $workspace, ?string $lsp, LspMode $mode, Ansi $ansi, \Closure $onBooted): never {
                $onBooted(); // the IDE constructed fine — the session died later
                throw new \RuntimeException('poll tick exploded');
            },
            isInteractive: static fn (): bool => true,
        );
        $tester = new CommandTester($command);

        $this->assertSame(Command::FAILURE, $tester->execute(['path' => [$project->dir]]));
        $this->assertStringContainsString('porta: fatal: poll tick exploded', $tester->getDisplay());
        $this->assertStringNotContainsString('delete ~/.cache/porta', $tester->getDisplay(), 'a mid-session crash must not send the user to delete a healthy cache');
        $this->assertStringContainsString('.porta/error.log', $tester->getDisplay());
        $this->assertStringContainsString('poll tick exploded', (string) \file_get_contents($project->dir.'/.porta/error.log'), 'the trace survives the crash');
    }

    public function testVerboseModeRethrowsForTheFullTrace(): void
    {
        $project = new TempProject(['a.php' => '']);
        $command = new PortaCommand(
            launch: static function (Workspace $workspace, ?string $lsp, LspMode $mode, Ansi $ansi, \Closure $onBooted): never {
                throw new \RuntimeException('boom');
            },
            isInteractive: static fn (): bool => true,
        );
        $tester = new CommandTester($command);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('boom');
        $tester->execute(['path' => [$project->dir]], ['verbosity' => OutputInterface::VERBOSITY_VERBOSE]);
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

        $status = $tester->execute(['path' => [$project->path('does-not-exist')]], ['capture_stderr_separately' => true]);

        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString('porta: no such file or directory', $tester->getErrorOutput(), 'the prefix comes first, not appended after the reason');
    }

    public function testAZeroLineOptionClampsUpToLineOneNotZero(): void
    {
        $project = new TempProject(['a.php' => '']);
        $tester = new CommandTester($this->command());

        $tester->execute(['path' => [$project->dir], '--line' => '0']);

        $this->assertSame(1, $this->launched[0]->line, 'max(1, ...) floors at exactly 1, not 0 or 2');
    }

    /** Run $body with the working directory at $dir, as git runs its tools from the work-tree top. */
    private function inDirectory(string $dir, \Closure $body): mixed
    {
        $previous = (string) \getcwd();
        \chdir($dir);
        try {
            return $body();
        } finally {
            \chdir($previous);
        }
    }

    public function testDiffLaunchesAToolRootedWhereGitRunsIt(): void
    {
        $project = new TempProject(['composer.json' => '{}', 'a.php' => '']);
        $tester = new CommandTester($this->command());

        $status = $this->inDirectory($project->dir, static fn (): int => $tester->execute(['path' => ['/tmp/x_LOCAL_1.php', 'a.php'], '--diff' => true]));

        $this->assertSame(Command::SUCCESS, $status);
        [$workspace] = $this->launched;
        $this->assertSame(\realpath($project->dir), $workspace->rootDir, 'the work-tree top, never the temp blob\'s folder');
        $this->assertNull($workspace->initialFile);
        $this->assertSame(ToolMode::Diff, $workspace->tool?->mode);
        $this->assertSame('/tmp/x_LOCAL_1.php', $workspace->tool->paths[0], 'an absolute path is kept as it is');
        $this->assertSame(\realpath($project->dir).'/a.php', \realpath($workspace->tool->paths[1]), 'a relative one is taken from where git ran');
    }

    public function testMergeHandsGitsFourFilesOverInOrder(): void
    {
        $project = new TempProject(['m.php' => '']);
        $tester = new CommandTester($this->command());

        $this->inDirectory($project->dir, static fn (): int => $tester->execute(['path' => ['l', 'r', 'b', 'm.php'], '--merge' => true]));

        [$workspace] = $this->launched;
        $root = (string) \realpath($project->dir);
        $this->assertSame(ToolMode::Merge, $workspace->tool?->mode);
        $this->assertSame([$root.'/l', $root.'/r', $root.'/b', $root.'/m.php'], $workspace->tool->paths);
        $this->assertSame($root.'/m.php', $workspace->tool->editable());
    }

    public function testTheIdesExitCodeIsTheCommands(): void
    {
        // A merge left unresolved exits 1, which is what trustExitCode reads.
        $project = new TempProject(['m.php' => '']);
        $tester = new CommandTester($this->command());
        $this->exitCode = 1;

        $status = $this->inDirectory($project->dir, static fn (): int => $tester->execute(['path' => ['l', 'r', 'b', 'm.php'], '--merge' => true]));

        $this->assertSame(1, $status);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidLines(): iterable
    {
        yield 'diff with one file' => [['path' => ['a'], '--diff' => true], '--diff compares two files'];
        yield 'merge with three' => [['path' => ['a', 'b', 'c'], '--merge' => true], '--merge takes four files'];
        yield 'two paths to edit' => [['path' => ['a', 'b']], 'one file or directory at a time'];
        yield 'two modes' => [['path' => ['a', 'b'], '--diff' => true, '--merge' => true], '--diff, --merge, --git-setup and --self-update are separate modes'];
        yield 'setup and update at once' => [['--git-setup' => true, '--self-update' => true], '--diff, --merge, --git-setup and --self-update are separate modes'];
        yield 'an update with a path' => [['path' => ['a'], '--self-update' => true], '--git-setup and --self-update take no path'];
        yield 'setup with a path' => [['path' => ['a'], '--git-setup' => true], '--git-setup and --self-update take no path'];
        yield 'a dry run of nothing' => [['--dry-run' => true], '--dry-run only goes with --git-setup or --self-update'];
        yield 'a line in a diff' => [['path' => ['a', 'b'], '--diff' => true, '--line' => '3'], '--line opens a file for editing'];
    }

    /** @param array<string, mixed> $line */
    #[DataProvider('invalidLines')]
    public function testAnImpossibleCommandLineIsRefused(array $line, string $says): void
    {
        $tester = new CommandTester($this->command());

        $this->assertSame(Command::INVALID, $tester->execute($line));
        $this->assertNull($this->launched, 'nothing was launched');
        $this->assertStringContainsString('porta: '.$says, \preg_replace('/\s+/', replacement: ' ', subject: $tester->getDisplay()) ?? '');
    }

    public function testGitSetupDryRunPrintsTheConfigNamingThisBinary(): void
    {
        $ran = [];
        $tester = new CommandTester(new PortaCommand(git: static function (array $args) use (&$ran): array {
            $ran[] = $args;

            return [0, ''];
        }, self: '/opt/my porta/bin/porta'));

        $this->assertSame(Command::SUCCESS, $tester->execute(['--git-setup' => true, '--dry-run' => true]));

        $this->assertSame([], $ran, 'a dry run writes nothing');
        $this->assertStringContainsString("git config --global difftool.porta.cmd ''\\''/opt/my porta/bin/porta'\\'' --diff \"\$LOCAL\" \"\$REMOTE\"'", $tester->getDisplay());
        $this->assertStringContainsString("git config --global mergetool.porta.trustExitCode 'true'", $tester->getDisplay());
    }

    public function testGitSetupWritesTheGlobalConfig(): void
    {
        $ran = [];
        $tester = new CommandTester(new PortaCommand(git: static function (array $args) use (&$ran): array {
            $ran[] = $args;

            return [0, ''];
        }, self: '/usr/local/bin/porta'));

        $this->assertSame(Command::SUCCESS, $tester->execute(['--git-setup' => true]));

        $this->assertSame(
            [
                ['config', '--global', 'difftool.porta.cmd',            "'/usr/local/bin/porta' --diff \"\$LOCAL\" \"\$REMOTE\""],
                ['config', '--global', 'mergetool.porta.cmd',           "'/usr/local/bin/porta' --merge \"\$LOCAL\" \"\$REMOTE\" \"\$BASE\" \"\$MERGED\""],
                ['config', '--global', 'mergetool.porta.trustExitCode', 'true'],
                ['config', '--global', 'diff.tool',                     'porta'],
                ['config', '--global', 'merge.tool',                    'porta'],
            ],
            $ran,
        );
        $this->assertStringContainsString('git difftool', $tester->getDisplay());
    }

    public function testGitSetupStopsAtTheFirstWriteThatFails(): void
    {
        $ran = 0;
        $tester = new CommandTester(new PortaCommand(git: static function (array $args) use (&$ran): array {
            ++$ran;

            return [1 === $ran ? 0 : 255, ''];
        }));

        $this->assertSame(Command::FAILURE, $tester->execute(['--git-setup' => true]));
        $this->assertSame(2, $ran, 'nothing after the failed key is tried');
        $this->assertStringContainsString('could not write mergetool.porta.cmd', $tester->getDisplay());
    }

    /**
     * A command whose --self-update talks to a scripted `curl`: the API says
     * $latest, and serves the two assets it lists. It runs as the phar $phar.
     */
    private function updater(string $version, string $latest, ?string $phar, int $newPharRuns = 0): PortaCommand
    {
        return new PortaCommand(
            process: static function (array $command, ?string $stdin = null) use ($latest, $newPharRuns): array {
                $url = (string) \end($command);
                $out = 'curl' === $command[0] ? \array_search('-o', $command, strict: true) : false;
                if (false !== $out) {
                    $file = $command[$out + 1];
                    \file_put_contents($file, \str_ends_with($file, '.sha256') ? \hash('sha256', data: 'NEW')."  porta.phar\n" : 'NEW');
                }

                return match (true) {
                    \str_ends_with($url, '/releases/latest') => [0, (string) \json_encode(['tag_name' => $latest], \JSON_THROW_ON_ERROR)],
                    \str_contains($url, '/releases/tags/') => [
                        0,
                        (string) \json_encode(['assets' => [['name' => 'porta.phar', 'url' => 'https://api/1'], ['name' => 'porta.phar.sha256', 'url' => 'https://api/2']]], \JSON_THROW_ON_ERROR),
                    ],
                    'curl' === $command[0] => [0, ''],
                    default => [$newPharRuns, ''],
                };
            },
            version: $version,
            phar: $phar ?? '',
        );
    }

    public function testSelfUpdateReplacesTheRunningPharWithANewerRelease(): void
    {
        $dir = new TempProject(['porta.phar' => 'OLD']);
        $tester = new CommandTester($this->updater('v1.2.0', 'v1.3.0', $dir->path('porta.phar')));

        $this->assertSame(Command::SUCCESS, $tester->execute(['--self-update' => true]));

        $this->assertStringContainsString('porta updated: v1.2.0 → v1.3.0', $tester->getDisplay());
        $this->assertSame('NEW', \file_get_contents($dir->path('porta.phar')));
    }

    public function testSelfUpdateDryRunOnlySaysWhatIsThere(): void
    {
        $dir = new TempProject(['porta.phar' => 'OLD']);
        $tester = new CommandTester($this->updater('v1.2.0', 'v1.3.0', $dir->path('porta.phar')));

        $this->assertSame(Command::SUCCESS, $tester->execute(['--self-update' => true, '--dry-run' => true]));

        $this->assertStringContainsString('porta v1.2.0 can be updated to v1.3.0', $tester->getDisplay());
        $this->assertSame('OLD', \file_get_contents($dir->path('porta.phar')));
    }

    public function testSelfUpdateOfTheLatestReleaseChangesNothing(): void
    {
        $dir = new TempProject(['porta.phar' => 'OLD']);
        $tester = new CommandTester($this->updater('v1.3.0', 'v1.3.0', $dir->path('porta.phar')));

        $this->assertSame(Command::SUCCESS, $tester->execute(['--self-update' => true]));

        $this->assertStringContainsString('porta v1.3.0 is up to date', $tester->getDisplay());
        $this->assertSame('OLD', \file_get_contents($dir->path('porta.phar')));
    }

    public function testAFailedSelfUpdateSaysWhyAndFails(): void
    {
        $dir = new TempProject(['porta.phar' => 'OLD']);
        $tester = new CommandTester($this->updater('v1.2.0', 'v1.3.0', $dir->path('porta.phar'), newPharRuns: 1));

        $this->assertSame(Command::FAILURE, $tester->execute(['--self-update' => true]));

        $this->assertStringContainsString('porta: self-update: the downloaded phar does not run here', \preg_replace('/\s+/', replacement: ' ', subject: $tester->getDisplay()) ?? '');
        $this->assertSame('OLD', \file_get_contents($dir->path('porta.phar')));
    }

    public function testOnlyAPharUpdatesItself(): void
    {
        $tester = new CommandTester($this->updater('0.1.0', 'v1.3.0', null));

        $this->assertSame(Command::FAILURE, $tester->execute(['--self-update' => true]));
        $this->assertStringContainsString('composer global update janalis/porta', \preg_replace('/\s+/', replacement: ' ', subject: $tester->getDisplay()) ?? '');
    }

    /** @return iterable<string, array{string, bool}> */
    public static function trustAnswers(): iterable
    {
        yield 'yes' => ['y', true];
        yield 'a stray Enter' => ['', false];
        yield 'no' => ['n', false];
    }

    /**
     * A project never answered for is asked about before the IDE takes the
     * terminal — and the answer is kept either way.
     */
    #[DataProvider('trustAnswers')]
    public function testAProjectNeverAnsweredForIsAskedAboutBeforeLaunch(string $answer, bool $trusted): void
    {
        $home = new TempProject();
        $trust = new WorkspaceTrust($home->dir);
        $project = new TempProject(['.porta.json' => '{}']);
        $tester = new CommandTester($this->command(trust: $trust));
        $tester->setInputs([$answer]);

        $this->assertSame(Command::SUCCESS, $tester->execute(['path' => [$project->dir]]));
        $this->assertStringContainsString('Trust this project?', $tester->getDisplay());
        $this->assertStringContainsString('can run code of its own', $tester->getDisplay(), 'what is being asked, said first');
        $this->assertStringContainsString('opens restricted', $tester->getDisplay());
        $this->assertNotNull($this->launched, 'launched either way');
        $this->assertSame($trusted, $trust->isTrusted($project->dir));
        $this->assertFalse($trust->shouldAsk($project->dir), 'answered: not asked again');
    }

    public function testAnAnswerThatCannotBeKeptSaysSoAndStillLaunches(): void
    {
        $project = new TempProject(['.porta.json' => '{}']);
        $tester = new CommandTester($this->command(trust: new WorkspaceTrust('/dev/null')));
        $tester->setInputs(['y']);

        $tester->execute(['path' => [$project->dir]]);

        $this->assertStringContainsString('could not save the answer', $tester->getDisplay());
        $this->assertNotNull($this->launched);
    }

    public function testAGitToolLaunchNeverAsks(): void
    {
        $project = new TempProject(['.porta.json' => '{}', 'a.php' => '']);
        $home = new TempProject();
        $tester = new CommandTester($this->command(trust: new WorkspaceTrust($home->dir)));

        $this->inDirectory($project->dir, static fn (): int => $tester->execute(['path' => ['/tmp/x_LOCAL_1.php', 'a.php'], '--diff' => true]));

        $this->assertStringNotContainsString('Trust this project?', $tester->getDisplay());
        $this->assertNotNull($this->launched);
    }

    /** A remote project's cache: no language server and no tool runs there, trusted or not. */
    public function testARemoteProjectsCacheIsNotAskedAbout(): void
    {
        $cache = new TempProject(['.porta/remote.json' => '{"url": "ftp://me@example.org/site"}', 'a.php' => '']);
        $home = new TempProject();
        $trust = new WorkspaceTrust($home->dir);
        $tester = new CommandTester($this->command(trust: $trust));

        $tester->execute(['path' => [$cache->dir]]);

        $this->assertStringNotContainsString('Trust this project?', $tester->getDisplay());
        $this->assertNotNull($this->launched);
        $this->assertTrue($trust->shouldAsk($cache->dir), 'nothing answered on its behalf either');
    }
}
