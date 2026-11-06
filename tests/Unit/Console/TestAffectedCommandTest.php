<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Console;

use PHPUnit\Framework\TestCase;
use Porta\Console\TestAffectedCommand;
use Porta\Tests\Support\TempProject;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `test:affected` over a throwaway tree: which test files a change selects,
 * when it gives up and runs the whole suite instead, the process it hands the
 * selection to, and what a `--coverage` run reports per changed source file.
 *
 * git and the test process are scripted for everything but the last two
 * tests, which drive the real ones — a command whose real invocation is
 * malformed would otherwise pass here forever.
 */
final class TestAffectedCommandTest extends TestCase
{
    private const string PHPUNIT = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <phpunit bootstrap="tests/bootstrap.php" executionOrder="random">
            <testsuites>
                <testsuite name="Unit"><directory>tests/Unit</directory></testsuite>
            </testsuites>
            <extensions><bootstrap class="Some\Extension"/></extensions>
        </phpunit>
        XML;

    /** @var list<array{argv: list<string>, env: array<string, string>, configuration: string|null}> */
    private array $runs = [];

    private static function project(): TempProject
    {
        return new TempProject([
            'phpunit.xml.dist' => self::PHPUNIT,
            'src/Editor/Buffer.php' => '<?php final class Buffer {}',
            'src/Editor/BufferView.php' => '<?php final class BufferView {}',
            'tests/Unit/Editor/BufferTest.php' => '<?php new Buffer();',
            'tests/Unit/Editor/BufferViewTest.php' => '<?php new BufferView();',
            'tests/Unit/Editor/EditorTestCase.php' => '<?php abstract class EditorTestCase {}',
            'tests/Unit/Editor/CaretTest.php' => '<?php class CaretTest extends EditorTestCase {}',
            'tests/E2e/IdeEditingTest.php' => '<?php // drives a Buffer through the Ide',
            'plugins/git/plugin.php' => '<?php',
            'plugins/git/src/Blame.php' => '<?php final class Blame {}',
            'plugins/git/tests/BlameTest.php' => '<?php new Blame();',
            'plugins/git/tests/Process/GitRemoteTest.php' => '<?php',
            'plugins/viz/tests/GraphTest.php' => '<?php new Buffer();',
            'plugins/gitlab/tests/MergeRequestTest.php' => '<?php',
            // Outside the four suites: a dependency's own tests are never selected.
            'vendor/acme/tests/BufferTest.php' => '<?php new Buffer();',
        ]);
    }

    /**
     * @param list<string>     $diff      what `git diff --name-only` lists
     * @param list<string>     $untracked what `git ls-files --others` lists
     * @param array<int, mixed> $options
     */
    private function affected(TempProject $project, array $diff, array $untracked = [], array $options = [], int $exit = 0, ?\Closure $during = null): CommandTester
    {
        $tester = new CommandTester(new TestAffectedCommand(
            $project->dir,
            static fn (array $args): string => \implode("\n", 'diff' === $args[0] ? $diff : $untracked),
            function (array $argv, array $env, OutputInterface $output) use ($exit, $during): int {
                $configuration = null;
                foreach ($argv as $arg) {
                    if (!\str_starts_with($arg, '--configuration=')) {
                        continue;
                    }
                    $configuration = (string) \file_get_contents(\substr($arg, \strlen('--configuration=')));
                }
                $this->runs[] = ['argv' => $argv, 'env' => $env, 'configuration' => $configuration];
                $output->writeln('the test process ran');
                if (null !== $during) {
                    $during();
                }

                return $exit;
            },
        ));
        $tester->execute($options);

        return $tester;
    }

    /** @return list<string> the files the generated testsuite listed */
    private function listedFiles(): array
    {
        $this->assertCount(1, $this->runs);
        $configuration = $this->runs[0]['configuration'];
        $this->assertIsString($configuration, 'the run was handed a configuration');
        $document = new \DOMDocument();
        $document->loadXML($configuration);

        return \array_map(static fn (\DOMNode $file): string => (string) $file->textContent, \iterator_to_array($document->getElementsByTagName('file')));
    }

    public function testAChangedClassSelectsTheTestsThatNameItAndNoOthers(): void
    {
        $project = self::project();

        $tester = $this->affected($project, ['src/Editor/Buffer.php']);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertSame(
            ['plugins/viz/tests/GraphTest.php', 'tests/E2e/IdeEditingTest.php', 'tests/Unit/Editor/BufferTest.php'],
            $this->listedFiles(),
            'a whole-word match across every suite and every plugin: BufferViewTest names BufferView, not Buffer, and vendor/ is no suite',
        );
        $this->assertStringContainsString(
            "3 test file(s) reach the change:\n  plugins/viz/tests/GraphTest.php\n  tests/E2e/IdeEditingTest.php\n  tests/Unit/Editor/BufferTest.php",
            $tester->getDisplay(),
        );
    }

    public function testAChangedTestRunsItselfAndTheTestsThatExtendIt(): void
    {
        $project = self::project();

        $this->affected($project, ['tests/Unit/Editor/BufferViewTest.php', 'tests/Unit/Editor/EditorTestCase.php']);

        $this->assertSame(['tests/Unit/Editor/BufferViewTest.php', 'tests/Unit/Editor/CaretTest.php'], $this->listedFiles());
    }

    public function testADeletedTestIsNotSelectedButItsNameStillIs(): void
    {
        $project = self::project();
        $project->write('tests/Unit/Editor/GoneHelperTest.php', '<?php');
        \unlink($project->path('tests/Unit/Editor/GoneHelperTest.php'));
        $project->write('tests/Unit/Editor/UsesGoneTest.php', '<?php // GoneHelperTest');

        $this->affected($project, ['tests/Unit/Editor/GoneHelperTest.php']);

        $this->assertSame(['tests/Unit/Editor/UsesGoneTest.php'], $this->listedFiles());
    }

    public function testAPluginsOwnTopLevelFileSelectsThatPluginsTestsOnly(): void
    {
        $project = self::project();

        $this->affected($project, ['plugins/git/plugin.php', 'src/Editor/BufferView.php']);

        $this->assertSame(
            ['plugins/git/tests/BlameTest.php', 'plugins/git/tests/Process/GitRemoteTest.php', 'tests/Unit/Editor/BufferViewTest.php'],
            $this->listedFiles(),
            'not plugins/gitlab, whose name only starts like it — and the changes after it are still read',
        );
    }

    public function testAnUntrackedClassCountsAndADuplicateIsListedOnce(): void
    {
        $project = self::project();

        $this->affected($project, ['plugins/git/src/Blame.php'], ['plugins/git/src/Blame.php', 'tests/Unit/Editor/BufferTest.php']);

        $this->assertSame(['plugins/git/tests/BlameTest.php', 'tests/Unit/Editor/BufferTest.php'], $this->listedFiles());
    }

    public function testTheRunIsComposerTestsOwnOverACopyOfTheRealConfiguration(): void
    {
        $project = self::project();
        $leftover = null;

        $tester = $this->affected($project, ['src/Editor/Buffer.php'], options: ['--processes' => '6'], exit: 3, during: static function () use ($project, &$leftover): void {
            $leftover = \glob($project->dir.'/.phpunit.affected-*.xml');
        });

        $this->assertSame(3, $tester->getStatusCode(), 'the test process decides the exit');
        $this->assertStringContainsString('the test process ran', $tester->getDisplay());
        $argv = $this->runs[0]['argv'];
        $this->assertSame(\PHP_BINARY, $argv[0]);
        $this->assertSame('vendor/bin/paratest', $argv[1]);
        $this->assertSame('--configuration='.$project->dir.'/.phpunit.affected-'.\getmypid().'.xml', $argv[2], 'named by pid');
        $this->assertSame(['--processes=6', '--functional', '--enforce-time-limit', '--default-time-limit=10'], \array_slice($argv, offset: 3));
        $this->assertSame([], $this->runs[0]['env']);

        $configuration = (string) $this->runs[0]['configuration'];
        $this->assertStringContainsString('bootstrap="tests/bootstrap.php"', $configuration);
        $this->assertStringContainsString('<bootstrap class="Some\Extension"/>', $configuration, 'the extensions are the suite\'s');
        $this->assertStringContainsString('<testsuites><testsuite name="Affected"><file>plugins/viz/tests/GraphTest.php</file><file>tests/E2e/IdeEditingTest.php</file>', $configuration);
        $this->assertStringNotContainsString('<directory>tests/Unit</directory>', $configuration, 'the original suites are gone');

        $this->assertIsArray($leftover);
        $this->assertCount(1, $leftover, 'the configuration exists while the run reads it');
        $this->assertSame([], \glob($project->dir.'/.phpunit.affected-*.xml'), 'and is deleted after it');
    }

    public function testTheConfigurationIsDeletedEvenWhenTheRunThrows(): void
    {
        $project = self::project();

        try {
            $this->affected($project, ['src/Editor/Buffer.php'], during: static function (): never {
                throw new \RuntimeException('interrupted');
            });
            $this->fail('the run threw');
        } catch (\RuntimeException $e) {
            $this->assertSame('interrupted', $e->getMessage());
        }

        $this->assertSame([], \glob($project->dir.'/.phpunit.affected-*.xml'));
    }

    public function testWithoutAReadableConfigurationNothingRuns(): void
    {
        $project = self::project();
        \unlink($project->path('phpunit.xml.dist'));

        $tester = $this->affected($project, ['src/Editor/Buffer.php']);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringEndsWith("\ntest:affected: ".$project->dir."/phpunit.xml.dist has no <testsuites> to replace.\n", $tester->getDisplay());
        $this->assertSame([], $this->runs);
    }

    public function testAConfigurationWithNoSuitesToReplaceRunsNothing(): void
    {
        $project = self::project();
        $project->write('phpunit.xml.dist', '<?xml version="1.0"?><phpunit/>');

        $tester = $this->affected($project, ['src/Editor/Buffer.php']);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('phpunit.xml.dist has no <testsuites> to replace.', $tester->getDisplay());
        $this->assertSame([], $this->runs);
        $this->assertSame([], \glob($project->dir.'/.phpunit.affected-*.xml'), 'nothing was written');
    }

    public function testListPrintsTheSelectionAndRunsNothing(): void
    {
        $project = self::project();

        $tester = $this->affected($project, ['src/Editor/Buffer.php'], options: ['--list' => true]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('tests/Unit/Editor/BufferTest.php', $tester->getDisplay());
        $this->assertSame([], $this->runs);
    }

    public function testAChangeNoTestNamesRunsTheWholeSuiteRatherThanNothing(): void
    {
        $project = self::project();
        $project->write('src/Orphan.php', '<?php final class Orphan {}');

        $tester = $this->affected($project, ['src/Editor/Buffer.php', 'src/Orphan.php'], ['src/Orphan.php']);

        $this->assertStringContainsString('No test file names src/Orphan.php — the whole suite reaches the change.', $tester->getDisplay(), 'only the unreached one is named, once');
        $this->assertSame([\PHP_BINARY, 'vendor/bin/paratest', '--processes=auto', '--functional', '--enforce-time-limit', '--default-time-limit=10'], $this->runs[0]['argv']);
        $this->assertNull($this->runs[0]['configuration']);
    }

    public function testAPluginWithNoTestsReachesNothing(): void
    {
        $project = self::project();
        $project->write('plugins/bare/plugin.php', '<?php');

        $tester = $this->affected($project, ['plugins/bare/plugin.php'], options: ['--list' => true]);

        $this->assertStringContainsString('No test file names plugins/bare/plugin.php', $tester->getDisplay());
    }

    public function testCoverageOverAnUnreachedFileIsTheGatesJob(): void
    {
        $project = self::project();
        $project->write('src/Orphan.php', '<?php final class Orphan {}');

        $tester = $this->affected($project, ['src/Orphan.php'], options: ['--coverage' => true]);

        $this->assertSame(Command::INVALID, $tester->getStatusCode());
        $this->assertSame([], $this->runs);
    }

    public function testNothingChangedRunsNothing(): void
    {
        $project = self::project();

        $tester = $this->affected($project, [], options: ['--base' => 'main']);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('Nothing changed against main.', $tester->getDisplay());
        $this->assertSame([], $this->runs);
    }

    public function testAFileNoRuleCanPlaceRunsTheWholeSuiteAndSaysWhich(): void
    {
        $project = self::project();

        $tester = $this->affected($project, ['src/Editor/Buffer.php', 'docs/editor.md', 'config/services.php'], options: ['--processes' => 'auto'], exit: 1);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('No name search can place config/services.php, docs/editor.md — the whole suite reaches the change.', $tester->getDisplay());
        $this->assertSame([\PHP_BINARY, 'vendor/bin/paratest', '--processes=auto', '--functional', '--enforce-time-limit', '--default-time-limit=10'], $this->runs[0]['argv']);
        $this->assertNull($this->runs[0]['configuration'], 'the real configuration, not a copy');
    }

    public function testAWholeSuiteListRunsNothing(): void
    {
        $project = self::project();

        $tester = $this->affected($project, ['composer.json'], options: ['--list' => true]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('the whole suite reaches the change', $tester->getDisplay());
        $this->assertSame([], $this->runs);
    }

    public function testAWholeSuiteCoverageRunIsRefusedAsTheGatesJob(): void
    {
        $project = self::project();

        $tester = $this->affected($project, ['composer.json'], options: ['--coverage' => true]);

        $this->assertSame(Command::INVALID, $tester->getStatusCode());
        $this->assertStringContainsString('run composer coverage:check', $tester->getDisplay());
        $this->assertSame([], $this->runs);
    }

    /**
     * @param array<string, list<array{int, int}>> $files root-relative path => [line, count] stmt entries
     */
    private static function clover(TempProject $project, array $files): string
    {
        $xml = '<?xml version="1.0"?><coverage><project>';
        foreach ($files as $path => $lines) {
            $xml .= '<file name="'.\realpath($project->path($path)).'">';
            // A method entry with a zero count is not a missed line.
            $xml .= '<line num="1" type="method" count="0"/>';
            foreach ($lines as [$number, $count]) {
                $xml .= \sprintf('<line num="%d" type="stmt" count="%d"/>', $number, $count);
            }
            $xml .= '</file>';
        }

        return $project->write(TestAffectedCommand::CLOVER, $xml.'</project></coverage>');
    }

    public function testACoverageRunNamesEachChangedSourcesMissedLines(): void
    {
        $project = self::project();
        $project->write(TestAffectedCommand::CLOVER, 'stale');

        $tester = $this->affected(
            $project,
            ['plugins/git/src/Blame.php', 'src/Editor/Buffer.php', 'src/Editor/BufferView.php', 'tests/Unit/Editor/BufferTest.php'],
            options: ['--coverage' => true],
            during: static function () use ($project): void {
                self::assertFileDoesNotExist($project->path(TestAffectedCommand::CLOVER), 'a stale report is gone before the run');
                self::clover($project, ['src/Editor/Buffer.php' => [[3, 1], [4, 0], [7, 0]], 'src/Editor/BufferView.php' => [[3, 2]]]);
            },
        );

        $this->assertSame(Command::FAILURE, $tester->getStatusCode(), 'a missed line fails the run');
        $display = $tester->getDisplay();
        $this->assertStringContainsString('  plugins/git/src/Blame.php: no selected test loaded it', $display);
        $this->assertStringContainsString('  src/Editor/Buffer.php: lines 4, 7 not covered', $display);
        $this->assertStringContainsString('  src/Editor/BufferView.php: every line covered', $display);
        $this->assertStringContainsString('by the SELECTED tests only', $display);

        $argv = $this->runs[0]['argv'];
        $this->assertSame([\PHP_BINARY, '-d', 'pcov.enabled=1', '-d', 'pcov.directory=.', '-d', 'pcov.exclude=#/(vendor|tests)/#', 'vendor/bin/phpunit'], \array_slice($argv, offset: 0, length: 8));
        $this->assertSame(
            [
                'plugins/git/tests/BlameTest.php',
                'plugins/viz/tests/GraphTest.php',
                'tests/E2e/IdeEditingTest.php',
                'tests/Unit/Editor/BufferTest.php',
                'tests/Unit/Editor/BufferViewTest.php',
                '--coverage-filter',
                'plugins/git/src',
                '--coverage-filter',
                'src/Editor',
                '--coverage-clover',
                TestAffectedCommand::CLOVER,
            ],
            \array_slice($argv, offset: 8),
            'the selection, one filter per directory, and the report',
        );
        $this->assertSame(['PORTA_CONTAINER_CACHE' => 'on'], $this->runs[0]['env']);
    }

    public function testACoverageRunWithEveryLineCoveredPasses(): void
    {
        $project = self::project();

        $tester = $this->affected($project, ['src/Editor/Buffer.php'], options: ['--coverage' => true], during: static function () use ($project): void {
            self::clover($project, ['src/Editor/Buffer.php' => [[3, 1]]]);
        });

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    public function testACoverageRunsFailingTestsDecideTheExit(): void
    {
        $project = self::project();

        $tester = $this->affected($project, ['src/Editor/Buffer.php'], options: ['--coverage' => true], exit: 2, during: static function () use ($project): void {
            self::clover($project, ['src/Editor/Buffer.php' => [[3, 1]]]);
        });

        $this->assertSame(2, $tester->getStatusCode());
    }

    public function testACoverageRunWithNoChangedSourceGradesNothing(): void
    {
        $project = self::project();

        $tester = $this->affected($project, ['tests/Unit/Editor/BufferTest.php'], options: ['--coverage' => true], exit: 0);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('No changed source file under src/ or a plugin\'s src/ to grade.', $tester->getDisplay());
        $this->assertNotContains('--coverage-filter', $this->runs[0]['argv']);
        $this->assertStringNotContainsString('SELECTED tests only', $tester->getDisplay(), 'there is no report to read');
    }

    public function testACoverageRunThatWroteNoReportFails(): void
    {
        $project = self::project();

        $tester = $this->affected($project, ['src/Editor/Buffer.php'], options: ['--coverage' => true], during: static function () use ($project): void {
            $project->write(TestAffectedCommand::CLOVER, 'not xml');
        });

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString("test:affected: the run wrote no readable Clover report at coverage/affected.xml.\n", $tester->getDisplay());
        $this->assertFalse(\libxml_use_internal_errors(), 'libxml reports errors again afterwards');
        $this->assertSame([], \libxml_get_errors(), 'and keeps none of the report\'s');
    }

    public function testACoverageRunThatWroteNothingFails(): void
    {
        $project = self::project();

        $tester = $this->affected($project, ['src/Editor/Buffer.php'], options: ['--coverage' => true]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('wrote no readable Clover report', $tester->getDisplay());
    }

    public function testOneFailingGitCallIsAFailedDiff(): void
    {
        $project = self::project();

        $tester = new CommandTester(new TestAffectedCommand(
            $project->dir,
            static fn (array $args): ?string => 'diff' === $args[0] ? 'src/Editor/Buffer.php' : null,
            static fn (): int => 0,
        ));
        $tester->execute([]);

        $this->assertSame(Command::INVALID, $tester->getStatusCode());
    }

    public function testADeletedSourceIsNotGraded(): void
    {
        $project = self::project();

        $project->write('tests/Unit/Editor/RemovedTest.php', '<?php // the Removed class is gone');

        $tester = $this->affected($project, ['src/Editor/Buffer.php', 'src/Editor/Removed.php'], options: ['--coverage' => true], during: static function () use ($project): void {
            self::clover($project, ['src/Editor/Buffer.php' => [[3, 1]]]);
        });

        $this->assertStringNotContainsString('Removed.php', $tester->getDisplay());
        $this->assertNotContains('src/Editor/Removed.php', $this->runs[0]['argv']);
    }

    /**
     * The real git and the real process: a checkout with one modified and one
     * new class, run against a tree with no vendor/, so php exits on the
     * missing paratest — fast, and proof the output streams through and the
     * exit is the process's own.
     */
    public function testTheRealGitAndRunnerReadTheCheckoutAndStreamTheProcess(): void
    {
        $project = self::project();
        $project->initGit();
        $project->commitAll('init');
        $project->write('src/Editor/Buffer.php', '<?php final class Buffer { public int $x = 1; }');
        $project->write('src/Editor/BufferView.php', '<?php final class BufferView { public int $y = 1; }');
        $project->git('add', 'src/Editor/BufferView.php');
        $project->write('plugins/git/src/Blame.php', '<?php final class Blame { public int $z = 1; }');
        $project->write('src/Fresh.php', '<?php final class Fresh {}');
        $project->write('tests/Unit/NewTest.php', '<?php new Fresh();');

        $tester = new CommandTester(new TestAffectedCommand($project->dir));
        $tester->execute(['--processes' => '1']);

        $display = $tester->getDisplay();
        $this->assertNotSame(Command::SUCCESS, $tester->getStatusCode(), 'php could not open the missing paratest');
        $this->assertStringContainsString(
            "6 test file(s) reach the change:\n  plugins/git/tests/BlameTest.php\n  plugins/viz/tests/GraphTest.php\n  tests/E2e/IdeEditingTest.php\n  tests/Unit/Editor/BufferTest.php\n  tests/Unit/Editor/BufferViewTest.php\n  tests/Unit/NewTest.php",
            $display,
            'unstaged, staged and untracked changes, against HEAD',
        );
        $this->assertStringContainsString('vendor/bin/paratest', $display, 'the process\'s own complaint streams through');
    }

    public function testOutsideACheckoutGitsFailureIsReported(): void
    {
        $project = self::project();

        $tester = new CommandTester(new TestAffectedCommand($project->dir));
        $tester->execute(['--base' => 'HEAD']);

        $this->assertSame(Command::INVALID, $tester->getStatusCode());
        $this->assertStringContainsString('git could not diff '.$project->dir.' against HEAD.', $tester->getDisplay());
    }
}
