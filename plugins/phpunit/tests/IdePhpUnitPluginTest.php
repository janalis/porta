<?php

declare(strict_types=1);

namespace Porta\PhpUnit\Tests;

use PHPUnit\Framework\TestCase;
use Porta\Config\CommandRegistry;
use Porta\Editor\BufferManager;
use Porta\Editor\BufferService;
use Porta\Ide;
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
                    $this->assertStringContainsString('✓ passed', $screen, 'the passing method is marked');
                    $this->assertStringContainsString('✗ failed', $screen, 'the failing method is marked');
                    $this->assertStringContainsString('✗ 1 test failing', $screen, 'the status bar keeps count');
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
                    $this->awaitPainted($tui, $terminal, '✓ passed');
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
                    $this->assertStringNotContainsString('✗ failed', $screen);
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
                    $this->assertStringNotContainsString('✗ failed', $screen);
                    $this->assertStringContainsString('✗ 2 tests failing', $screen);
                },
            ],
            rows: 40,
        );
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
                400 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use ($project): void {
                    // Its tab closed under it, a run reports no code at all.
                    $project->write('vendor/bin/phpunit', '#!'.\PHP_BINARY."\n<?php\nsleep(30);\n");
                    $this->command($ide, $terminal, 'run_all');
                    $runs = Services::resolve($ide, RunController::class);
                    $sessions = Probe::get($runs, 'sessions');
                    $this->assertInstanceOf(TerminalSessions::class, $sessions);
                    $index = \array_search($sessions->find('run:PHPUnit'), $sessions->sessions, strict: true);
                    $this->assertIsInt($index, 'the run has a tab of its own');
                    $runs->closeSession($index);
                    $this->awaitPainted($tui, $terminal, 'PHPUnit was stopped');
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
