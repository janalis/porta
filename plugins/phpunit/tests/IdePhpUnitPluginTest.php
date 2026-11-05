<?php

declare(strict_types=1);

namespace Porta\PhpUnit\Tests;

use PHPUnit\Framework\TestCase;
use Porta\Config\CommandRegistry;
use Porta\Editor\BufferManager;
use Porta\Editor\BufferService;
use Porta\Editor\CoverageMark;
use Porta\Editor\OpenFocus;
use Porta\Ide;
use Porta\Plugin\Runtime\PluginHost;
use Porta\Run\RunController;
use Porta\Terminal\TerminalSessions;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\ScreenReads;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\UsesBundledPlugins;
use Porta\Tests\Support\Widgets;
use Porta\Workbench\Menu\MenuFileContext;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * The plugin through a real IDE and a real PHPUnit — this repository's own,
 * behind a `vendor/bin/phpunit` that names the PHP binary outright, since PATH
 * is /nonexistent.
 */
final class IdePhpUnitPluginTest extends TestCase
{
    use ClearsPortaLsp;
    use PreservesPath;
    use RunsIde;
    use ScreenReads;
    use UsesBundledPlugins;

    private const string DEMO = <<<'PHP_WRAP'
        <?php

        namespace App\Tests;

        final class DemoTest extends \PHPUnit\Framework\TestCase
        {
            public function testPasses(): void
            {
                $this->assertTrue(true);
            }

            public function testFails(): void
            {
                $this->assertSame(1, 2);
            }
        }

        PHP_WRAP;

    private const string OTHER = <<<'PHP_WRAP'
        <?php

        namespace App\Tests;

        final class OtherTest extends \PHPUnit\Framework\TestCase
        {
            public function testBroken(): void
            {
                $this->assertTrue(false);
            }
        }

        PHP_WRAP;

    protected function setUp(): void
    {
        \putenv('PATH=/nonexistent');
    }

    /** @param array<string, string> $files */
    private static function project(array $files, string $phpunit = "require '%s/vendor/phpunit/phpunit/phpunit';"): TempProject
    {
        $project = new TempProject([
            ...$files,
            '.porta.json' => '{"enabledPlugins":["phpunit"],"settings":{"diagnosticMessages":"inline"}}',
            'vendor/bin/phpunit' => \sprintf("#!%s\n<?php\n".$phpunit, \PHP_BINARY, \dirname(__DIR__, levels: 3)),
        ]);
        \chmod($project->path('vendor/bin/phpunit'), permissions: 0o755);

        return $project;
    }

    /**
     * A `vendor/bin/phpunit` that writes a report of $cases where it is asked
     * to, notes in `log-path` where that was, and exits $code.
     */
    private static function reporter(TempProject $project, string $cases, int $code): void
    {
        $project->write(
            'vendor/bin/phpunit',
            \sprintf(
                "#!%s\n<?php\n".'$at = array_search(\'--log-junit\', $argv, true); file_put_contents($argv[$at + 1], %s); file_put_contents(\'log-path\', $argv[$at + 1]); exit(%d);',
                \PHP_BINARY,
                \var_export('<testsuites><testsuite name="x">'.$cases.'</testsuite></testsuites>', return: true),
                $code,
            ),
        );
    }

    /**
     * A `vendor/bin/phpunit` that reports one passing test and, asked for
     * coverage, writes $clover there — `{root}` standing for the directory it
     * runs in, by its real path as PHPUnit names files. Each call's arguments
     * are appended to `calls`.
     */
    private static function measurer(TempProject $project, ?string $clover): void
    {
        $project->write('vendor/bin/phpunit', \sprintf(
            "#!%s\n<?php\n".<<<'PHP'
                file_put_contents('calls', implode(' ', $argv)."\n", FILE_APPEND);
                $at = array_search('--log-junit', $argv, true);
                file_put_contents($argv[$at + 1], '<testsuites><testsuite name="x"><testcase name="testA" file="/x/ATest.php" line="3" class="ATest"/></testsuite></testsuites>');
                $clover = %s;
                $to = array_search('--coverage-clover', $argv, true);
                if (false !== $to && null !== $clover) {
                    file_put_contents($argv[$to + 1], str_replace('{root}', getcwd(), $clover));
                }

                PHP,
            \PHP_BINARY,
            \var_export($clover, return: true),
        ));
    }

    /** Run one of the plugin's commands, on a fresh output buffer so an await sees only what it caused. */
    private function command(Ide $ide, VirtualTerminal $terminal, string $id): void
    {
        $terminal->consumeOutput();
        Services::resolve($ide, CommandRegistry::class)->run('phpunit:'.$id);
    }

    /** The screen as it is now — the terminal's output is every frame since the last consume. */
    private function screen(Tui $tui, VirtualTerminal $terminal): string
    {
        return \implode("\n", $this->screenGrid($tui, $terminal));
    }

    public function testAFileRunsThenItsFailuresThenTheTestAtTheCaret(): void
    {
        $project = self::project([
            'tests/DemoTest.php' => self::DEMO,
            'tests/OtherTest.php' => self::OTHER,
            'phpunit.xml' => '<phpunit><testsuites><testsuite name="all"><directory>tests</directory></testsuite></testsuites></phpunit>',
            'README.md' => "# Demo\n",
        ]);

        $this->runIde(
            $project,
            $project->path('tests/DemoTest.php'),
            [
                100 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    $this->command($ide, $terminal, 'run_file');
                    $this->awaitPainted($tui, $terminal, '1 of 2 tests failed');
                    $this->awaitPainted($tui, $terminal, 'App\Tests\DemoTest::testFails: Failed asserting that 2 is identical to 1.');
                    $screen = $this->screen($tui, $terminal);
                    $this->assertStringContainsString('✗ App\Tests\DemoTest::testFails — Failed asserting that 2 is identical to 1.', $screen, 'the failure leads the dock');
                    $this->assertStringContainsString('✓ App\Tests\DemoTest::testPasses', $screen);
                    $this->assertStringNotContainsString('OtherTest', $screen, 'the file, not the suite');
                    $this->assertStringContainsString('✓ test passed', $screen, 'the passing method is marked');
                    $this->assertStringContainsString('✗ test failed', $screen, 'the failing method is marked');
                    $this->assertStringContainsString('✗ 1 test failing', $screen, 'the status bar keeps count');
                },
                120 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use ($project): void {
                    // Right-clicked in the tree, a file that is not open: it
                    // has no edits to save, and runs as it is on disk.
                    $terminal->consumeOutput();
                    Services::resolve($ide, MenuFileContext::class)->with(
                        $project->path('tests/OtherTest.php'),
                        static fn () => Services::resolve($ide, CommandRegistry::class)->run('phpunit:run_file'),
                    );
                    $this->awaitPainted($tui, $terminal, '1 of 1 tests failed');
                    $this->assertStringContainsString('✗ App\Tests\OtherTest::testBroken', $this->screen($tui, $terminal));
                },
                150 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use ($project): void {
                    $this->command($ide, $terminal, 'run_all');
                    $this->awaitPainted($tui, $terminal, '2 of 3 tests failed');

                    // A file opened after the run gets its failures then.
                    $terminal->consumeOutput();
                    Services::resolve($ide, BufferService::class)->openFile($project->path('tests/OtherTest.php'));
                    $this->awaitPainted($tui, $terminal, 'App\Tests\OtherTest::testBroken: Failed asserting that false is true.');

                    // Back on the first file, its marks are put back.
                    $terminal->consumeOutput();
                    Services::resolve($ide, BufferService::class)->openFile($project->path('tests/DemoTest.php'));
                    $this->awaitPainted($tui, $terminal, '✓ test passed');
                },
                175 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    $this->command($ide, $terminal, 'rerun_failed');
                    $this->awaitPainted($tui, $terminal, '2 of 2 tests failed');
                    $screen = $this->screen($tui, $terminal);
                    $this->assertStringContainsString('✗ App\Tests\OtherTest::testBroken', $screen, 'both files ran again');
                    $this->assertStringNotContainsString('✓ App\Tests\DemoTest::testPasses', $screen, 'only the failures did');
                },
                200 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use ($project): void {
                    Widgets::editor($tui)->jumpTo(8, 0); // inside testPasses
                    $this->command($ide, $terminal, 'run_at_caret');
                    $this->awaitPainted($tui, $terminal, '1 test passed');
                    $screen = $this->screen($tui, $terminal);
                    $this->assertStringNotContainsString('failing', $screen, 'a green run clears the count');
                    $this->assertStringNotContainsString('testFails: Failed asserting', $screen, 'and the failure it no longer ran');
                    $this->assertStringNotContainsString('✗ test failed', $screen);
                    $other = Services::resolve($ide, BufferManager::class)->get((string) \realpath($project->path('tests/OtherTest.php')));
                    $this->assertSame([], $other?->diagnostics, 'the file behind, failing no more as far as anyone knows, is cleared too');
                },
                300 => function (Tui $tui, VirtualTerminal $terminal) use ($project): void {
                    // F5 on the tab runs the last run again — the test at the
                    // caret, which now fails on disk.
                    $project->write('tests/DemoTest.php', \str_replace('assertTrue(true)', replace: 'assertTrue(false)', subject: self::DEMO));
                    $tui->setFocus($tui->getById('results'));
                    $terminal->consumeOutput();
                    $tui->handleInput("\x1b[15~");
                    $this->awaitPainted($tui, $terminal, '✗ App\Tests\DemoTest::testPasses');
                    $this->assertStringContainsString('1 result', $this->screen($tui, $terminal));
                },
                350 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    // Above every method, the caret means the whole file.
                    Widgets::editor($tui)->jumpTo(2, 0);
                    $this->command($ide, $terminal, 'run_at_caret');
                    $this->awaitPainted($tui, $terminal, '2 results');
                    $this->assertStringContainsString('✗ 2 tests failing', $this->screen($tui, $terminal));
                },
                400 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use ($project): void {
                    // Another file in front: none of its lines is a test, and
                    // the count stays, since it is about the project.
                    Services::resolve($ide, BufferService::class)->openFile($project->path('README.md'));
                    $screen = $this->screen($tui, $terminal);
                    $this->assertStringNotContainsString('✗ test failed', $screen);
                    $this->assertStringContainsString('✗ 2 tests failing', $screen);
                },
            ],
            rows: 40,
        );
    }

    public function testCoverageShowsInTheStripUntilItIsHiddenOrTheFileSaved(): void
    {
        $project = self::project([
            'src/Calc.php' => "<?php\n\nfinal class Calc\n{\n    public function add(int \$a, int \$b): int\n    {\n        return \$a + \$b;\n    }\n}\n",
            'src/Other.php' => "<?php\n\$x = 1;\n",
            'src/Third.php' => "<?php\n\$y = 2;\n",
        ]);
        $both =
            '<coverage><project><file name="{root}/src/Calc.php"><line num="5" type="method" count="1"/><line num="7" type="stmt" count="1"/><line num="9" type="stmt" count="0"/></file>'
            .'<file name="{root}/src/Other.php"><line num="2" type="stmt" count="1"/></file>'
            .'<file name="{root}/src/Third.php"><line num="2" type="stmt" count="0"/></file></project></coverage>';
        self::measurer($project, $both);
        $calc = [6 => CoverageMark::Covered, 8 => CoverageMark::Uncovered];
        $other = [1 => CoverageMark::Covered];

        $this->runIde(
            $project,
            $project->path('src/Calc.php'),
            [
                100 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use ($project, $calc, $other): void {
                    $decorations = Widgets::editor($tui)->decorations;
                    $this->assertFalse(self::hideOffered($ide), 'nothing to hide yet');
                    $this->command($ide, $terminal, 'run_with_coverage');
                    $this->await(static fn (): bool => $calc === $decorations->coverage, what: 'the file in front to show its coverage');
                    $this->assertStringContainsString('--coverage-clover', (string) \file_get_contents($project->path('calls')));
                    $this->assertTrue(self::hideOffered($ide));

                    Services::resolve($ide, BufferService::class)->openFile($project->path('src/Other.php'));
                    $this->assertSame($other, $decorations->coverage, 'a file opened after the run shows its own');
                },
                200 => function (Tui $tui, VirtualTerminal $terminal) use ($project): void {
                    // F5 measures again, as the run it repeats did — and this
                    // time measures Calc only, so Other's bars go.
                    self::measurer($project, '<coverage><project><file name="{root}/src/Calc.php"><line num="7" type="stmt" count="1"/></file></project></coverage>');
                    $results = $tui->getById('results');
                    $tui->setFocus($results);
                    $terminal->consumeOutput();
                    $tui->handleInput("\x1b[15~");
                    $this->assertTrue(Probe::get($results, 'searching'), 'the rerun is under way');
                    $this->await(static fn (): bool => !Probe::get($results, 'searching'), what: 'the rerun to finish');
                    $this->assertSame(2, \substr_count((string) \file_get_contents($project->path('calls')), needle: '--coverage-clover'), 'it measured again');
                    $this->assertSame([], Widgets::editor($tui)->decorations->coverage, 'a file the new run did not measure shows none of the old');
                },
                300 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use ($project): void {
                    $decorations = Widgets::editor($tui)->decorations;
                    $buffers = Services::resolve($ide, BufferService::class);
                    $buffers->openFile($project->path('src/Calc.php'), OpenFocus::Editor);
                    $this->assertSame([6 => CoverageMark::Covered], $decorations->coverage);

                    // Saved, the file is not what the run measured — for good,
                    // not until it is opened again.
                    Widgets::editor($tui)->getDocument()->insert('// edited ');
                    $tui->handleInput("\x13"); // ctrl+s
                    $this->await(static fn (): bool => [] === $decorations->coverage, what: 'the save to take the file\'s coverage away');
                    $buffers->closeTab((string) \realpath($project->path('src/Calc.php')));
                    $buffers->openFile($project->path('src/Calc.php'), OpenFocus::Editor);
                    $this->assertSame([], $decorations->coverage, 'reopened, it has none');
                    $this->assertFalse(self::hideOffered($ide), 'and with no file left to show, Hide is dimmed');
                },
                400 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use ($project, $both): void {
                    $decorations = Widgets::editor($tui)->decorations;
                    $buffers = Services::resolve($ide, BufferService::class);
                    self::measurer($project, $both);
                    // Calc, in front, has an unsaved edit: the run measures
                    // the disk, whose lines are not the buffer's.
                    $buffers->openFile($project->path('src/Calc.php'), OpenFocus::Editor);
                    Widgets::editor($tui)->getDocument()->insert("// more\n");
                    $this->command($ide, $terminal, 'run_with_coverage');
                    $results = $tui->getById('results');
                    $this->await(static fn (): bool => !Probe::get($results, 'searching'), what: 'the run to finish');
                    $this->assertSame([], $decorations->coverage, 'a buffer with unsaved edits gets none');

                    // Other changed on disk after the run, before it opened.
                    $buffers->closeTab((string) \realpath($project->path('src/Other.php')));
                    \touch($project->path('src/Other.php'), \time() + 5);
                    $buffers->openFile($project->path('src/Other.php'), OpenFocus::Editor);
                    $this->assertSame([], $decorations->coverage, 'a file changed since the run gets none');

                    $buffers->openFile($project->path('src/Third.php'), OpenFocus::Editor);
                    $this->assertSame([1 => CoverageMark::Uncovered], $decorations->coverage);
                    $this->assertTrue(self::hideOffered($ide));
                    $this->command($ide, $terminal, 'hide_coverage');
                    $this->assertSame([], $decorations->coverage, 'hidden');
                    $this->assertFalse(self::hideOffered($ide));
                    $buffers->closeTab((string) \realpath($project->path('src/Third.php')));
                    $buffers->openFile($project->path('src/Third.php'), OpenFocus::Editor);
                    $this->assertSame([], $decorations->coverage, 'and gone, not waiting for the file to open again');
                },
                450 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use ($project): void {
                    // Stopped before its tests reported, a run says so — not
                    // that coverage needs a driver.
                    $project->write('vendor/bin/phpunit', '#!'.\PHP_BINARY."\n<?php\nsleep(30);\n");
                    $this->command($ide, $terminal, 'run_with_coverage');
                    $runs = Services::resolve($ide, RunController::class);
                    $sessions = Probe::get($runs, 'sessions');
                    $this->assertInstanceOf(TerminalSessions::class, $sessions);
                    $runs->closeSession((int) \array_search($sessions->find('run:PHPUnit'), $sessions->sessions, strict: true));
                    $this->awaitPainted($tui, $terminal, 'PHPUnit was stopped');
                    $this->assertStringNotContainsString('No coverage', $this->screen($tui, $terminal));
                },
                500 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use ($project): void {
                    self::measurer($project, null); // no driver: PHPUnit writes no Clover report
                    $this->command($ide, $terminal, 'run_with_coverage');
                    $this->awaitPainted($tui, $terminal, 'No coverage');
                },
            ],
        );
    }

    /** Whether the Plugins menu offers Hide Coverage, or shows it dimmed. */
    private static function hideOffered(Ide $ide): bool
    {
        foreach (Services::resolve($ide, PluginHost::class)->manager->menuSpecs() as $spec) {
            if ('i:phpunit:hide_coverage' === $spec->spec) {
                return null !== $spec->when && ($spec->when)();
            }
        }

        return false;
    }

    public function testWhatItRefusesAndWhatItCannotRead(): void
    {
        $project = self::project([
            'tests/EmptyTest.php' => "<?php\nfinal class EmptyTest extends \\PHPUnit\\Framework\\TestCase {}\n",
            'helper.php' => "<?php\nfunction helper(): void {}\n",
            'notes.txt' => "plain\n",
        ]);

        $this->runIde(
            $project,
            $project->path('notes.txt'),
            [
                100 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use ($project): void {
                    $this->command($ide, $terminal, 'run_file');
                    $this->awaitPainted($tui, $terminal, 'Open a PHPUnit test file first');

                    $this->command($ide, $terminal, 'rerun_failed');
                    $this->awaitPainted($tui, $terminal, 'No failed tests to run again');

                    Services::resolve($ide, BufferService::class)->openFile($project->path('helper.php'));
                    $this->command($ide, $terminal, 'run_at_caret');
                    $this->awaitPainted($tui, $terminal, 'helper.php declares no test class');
                    $this->command($ide, $terminal, 'run_file');
                    $this->awaitPainted($tui, $terminal, 'helper.php declares no test class');
                },
                200 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use ($project): void {
                    Services::resolve($ide, BufferService::class)->openFile($project->path('tests/EmptyTest.php'));
                    $this->command($ide, $terminal, 'run_file'); // a class of no tests: a report of nothing
                    $this->awaitPainted($tui, $terminal, 'PHPUnit ran no tests');
                },
                300 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    // No configuration to say where the suite is: PHPUnit prints
                    // its usage and leaves the log empty.
                    $this->command($ide, $terminal, 'run_all');
                    $this->awaitPainted($tui, $terminal, 'PHPUnit exited with code 1');
                    $this->assertFalse(Probe::get($tui->getById('results'), 'searching'), 'the tab says the run is over');
                },
                350 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use ($project): void {
                    // Every test passed and PHPUnit still failed the run (a
                    // failOnWarning, say): the exit code has the last word.
                    self::reporter($project, '<testcase name="testA" file="/x/ATest.php" line="3" class="ATest"/>', 1);
                    $this->command($ide, $terminal, 'run_all');
                    $this->awaitPainted($tui, $terminal, '1 test passed, but');
                    $log = (string) \file_get_contents($project->path('log-path'));
                    $this->assertStringStartsWith(\sys_get_temp_dir().'/porta-phpunit-', $log, 'the report goes to a file of its own under the temp dir');
                    $this->assertFileDoesNotExist($log, 'and is removed once read');

                    self::reporter($project, '<testcase name="testA" file="/x/ATest.php" line="3" class="ATest"><failure>ATest::testA'."\n".'No.</failure></testcase>', 1);
                    $this->command($ide, $terminal, 'run_all');
                    $this->awaitPainted($tui, $terminal, '✗ 1 test failing');
                },
                400 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use ($project): void {
                    // Its tab closed under it, a run reports no code at all —
                    // and takes the last run's failures off the screen with it.
                    $project->write('vendor/bin/phpunit', '#!'.\PHP_BINARY."\n<?php\nsleep(30);\n");
                    $this->command($ide, $terminal, 'run_all');
                    $runs = Services::resolve($ide, RunController::class);
                    $sessions = Probe::get($runs, 'sessions');
                    $this->assertInstanceOf(TerminalSessions::class, $sessions);
                    $index = \array_search($sessions->find('run:PHPUnit'), $sessions->sessions, strict: true);
                    $this->assertIsInt($index, 'the run has a tab of its own');
                    $runs->closeSession($index);
                    $this->awaitPainted($tui, $terminal, 'PHPUnit was stopped');
                    $this->assertStringNotContainsString('test failing', $this->screen($tui, $terminal));
                },
                500 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use ($project): void {
                    \unlink($project->path('vendor/bin/phpunit'));
                    $this->command($ide, $terminal, 'run_all');
                    $this->awaitPainted($tui, $terminal, 'phpunit not found');
                    $this->assertStringNotContainsString('Plugin "phpunit" failed', $this->screen($tui, $terminal), 'and nothing tried to run without it');
                },
            ],
        );
    }
}
