<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Console;

use PHPUnit\Framework\TestCase;
use Porta\Console\CoveragePluginsCommand;
use Porta\Tests\Support\TempProject;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Process\Process;

/**
 * The sharded plugin coverage pass: which shards it derives, that it really
 * runs them concurrently, and what it does with one that fails or hangs.
 *
 * The shards are driven through the injected process FACTORY, so the pool —
 * the part that is new — runs for real against `php -r` stand-ins rather than
 * being mocked away.
 */
final class CoveragePluginsCommandTest extends TestCase
{
    /** A tree with two plugins: loose tests, a subdirectory, and a directory of doubles. */
    private function project(): TempProject
    {
        return new TempProject([
            'plugins/alpha/tests/FooTest.php' => "<?php\n",
            'plugins/alpha/tests/Sub/BarTest.php' => "<?php\n",
            'plugins/beta/tests/BazTest.php' => "<?php\n",
            // No *Test.php: a shard here would be an empty suite, and
            // failOnEmptyTestSuite turns that into a red gate.
            'plugins/beta/tests/Doubles/Helper.php' => "<?php\n",
        ]);
    }

    /**
     * A factory whose processes exit with $exit, recording the paths each shard
     * was given.
     *
     * @param array<string, list<string>> $seen shard report path => paths, by reference
     */
    private function spawning(array &$seen, int $exit = 0, string $script = ''): \Closure
    {
        return static function (array $paths, string $cloverPath) use (&$seen, $exit, $script): Process {
            $seen[basename($cloverPath)] = $paths;

            return new Process([\PHP_BINARY, '-r', ('' === $script ? '' : $script).' exit('.$exit.');']);
        };
    }

    private function tester(TempProject $project, \Closure $spawn): CommandTester
    {
        return new CommandTester(new CoveragePluginsCommand($spawn, $project->path()));
    }

    public function testItDerivesOneShardPerSubdirectoryPlusTheLooseFiles(): void
    {
        $project = $this->project();
        $seen = [];
        $tester = $this->tester($project, $this->spawning($seen));

        $status = $tester->execute(['--output-dir' => $project->path('out')]);
        $display = $tester->getDisplay();

        $this->assertSame(Command::SUCCESS, $status, $display);
        $this->assertSame(['alpha-foo.xml', 'alpha-sub-bar.xml', 'beta-baz.xml'], array_keys($seen), 'one shard per test file, named from its path inside tests/');
        $this->assertSame([$project->path('plugins/alpha/tests/FooTest.php')], $seen['alpha-foo.xml'], 'a shard is one file, so the pass is only as slow as its slowest FILE');
        $this->assertSame([$project->path('plugins/alpha/tests/Sub/BarTest.php')], $seen['alpha-sub-bar.xml']);
        $this->assertArrayNotHasKey('beta-doubles.xml', $seen, 'a file that is not a *Test.php is not a shard');
        $this->assertStringContainsString('3 shards', $display);
        $this->assertStringContainsString('coverage:plugins: OK', $display);
    }

    public function testEveryShardWritesItsOwnReportBesideTheOthers(): void
    {
        $project = $this->project();
        $seen = [];
        $spawn = static function (array $paths, string $cloverPath) use (&$seen): Process {
            $seen[basename($cloverPath)] = $paths;

            return new Process([\PHP_BINARY, '-r', 'file_put_contents($argv[1], "<coverage/>"); exit(0);', $cloverPath]);
        };

        $tester = $this->tester($project, $spawn);
        $tester->execute(['--output-dir' => $project->path('out')]);

        $written = glob($project->path('out').'/*.xml');
        $this->assertSame(
            ['alpha-foo.xml', 'alpha-sub-bar.xml', 'beta-baz.xml'],
            array_map(basename(...), \is_array($written) ? $written : []),
            'one report per shard, in one directory — which is what coverage:check grades as a union',
        );
    }

    public function testAReportLeftByAnEarlierRunIsRemovedFirst(): void
    {
        $project = $this->project();
        $project->write('out/gone.xml', '<coverage/>');
        $seen = [];

        $this->tester($project, $this->spawning($seen))->execute(['--output-dir' => $project->path('out')]);

        $this->assertFileDoesNotExist($project->path('out/gone.xml'), 'a shard that has been renamed away would otherwise keep voting in the union');
    }

    public function testAFailingShardFailsThePassAndIsNamed(): void
    {
        $project = $this->project();
        $seen = [];
        $tester = $this->tester($project, $this->spawning($seen, exit: 2));

        $status = $tester->execute(['--output-dir' => $project->path('out')]);
        $display = $tester->getDisplay();

        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString('coverage:plugins: FAILED', $display);
        $this->assertStringContainsString('alpha-foo (exit 2)', $display, 'the shard is named, or nobody knows which file to run by hand');
    }

    public function testAShardThatOutlivesItsBudgetIsKilledAndReportedAsWedged(): void
    {
        $project = $this->project();
        $seen = [];
        $tester = $this->tester($project, $this->spawning($seen, script: 'usleep(5000000);'));

        $status = $tester->execute(['--output-dir' => $project->path('out'), '--timeout' => 1]);
        $display = $tester->getDisplay();

        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString(', wedged', $display, 'a hung shard holds a slot forever otherwise');
        $this->assertStringContainsString('exit '.CoveragePluginsCommand::WEDGED, $display);
    }

    public function testTheConcurrencyLimitIsReal(): void
    {
        // Each stand-in marks its own start and end in one file, so the marks
        // interleave exactly when two shards were running at once. `--processes=1`
        // must produce none of that, and the default must produce some — an
        // assertion no amount of pool bookkeeping can fake.
        $project = $this->project();
        $log = $project->path('marks.txt');
        $spawn = static fn (array $paths, string $cloverPath): Process => new Process([
            \PHP_BINARY,
            '-r',
            'file_put_contents($argv[1], "+", FILE_APPEND); usleep(300000); file_put_contents($argv[1], "-", FILE_APPEND); exit(0);',
            $log,
        ]);

        $this->tester($project, $spawn)->execute(['--output-dir' => $project->path('out'), '--processes' => 1]);
        $this->assertSame('+-+-+-', file_get_contents($log), 'at one process the shards must not overlap');

        unlink($log);
        $this->tester($project, $spawn)->execute(['--output-dir' => $project->path('out'), '--processes' => 3]);
        $this->assertStringContainsString('++', (string) file_get_contents($log), 'at three processes they must');
    }

    public function testATreeWithNoPluginTestsIsInvalidInput(): void
    {
        $project = new TempProject(['plugins/alpha/src/Thing.php' => "<?php\n"]);
        $seen = [];
        $tester = $this->tester($project, $this->spawning($seen));

        $status = $tester->execute(['--output-dir' => $project->path('out')]);

        $this->assertSame(Command::INVALID, $status);
        $this->assertStringContainsString('no plugin tests found', $tester->getDisplay(), 'a pass that runs nothing must not report OK');
    }

    public function testAnUncreatableOutputDirectoryIsInvalidInput(): void
    {
        $project = $this->project();
        $seen = [];
        $tester = $this->tester($project, $this->spawning($seen));

        // A file where the directory should be: mkdir cannot win this.
        $project->write('blocked', '');
        $status = $tester->execute(['--output-dir' => $project->path('blocked/out')]);

        $this->assertSame(Command::INVALID, $status);
        $this->assertStringContainsString('could not create', $tester->getDisplay());
    }

    public function testTheSlowestShardIsNamedBecauseItIsTheFloor(): void
    {
        $project = $this->project();
        $spawn = static fn (array $paths, string $cloverPath): Process => new Process([
            \PHP_BINARY,
            '-r',
            'usleep(str_contains($argv[1], "beta") ? 400000 : 10000); exit(0);',
            $cloverPath,
        ]);

        $tester = $this->tester($project, $spawn);
        $tester->execute(['--output-dir' => $project->path('out'), '--processes' => 3]);
        $display = $tester->getDisplay();

        $this->assertStringContainsString('the slowest was beta-baz at', $display, 'a fan-out is only as fast as its longest shard, so the report says which one that is');
    }

    public function testTheRealShardRunsPhpunitUnderPcovWithTheContainerCacheOn(): void
    {
        // The default factory is the one thing the pool never exercises, and it
        // carries three decisions: pcov's flags do not cross a process
        // boundary, the filter is the whole plugins tree (a per-plugin filter
        // would drop files no plugin's own tests load), and the container cache
        // stays on because a shard neither merges nor grades src.
        $command = new CoveragePluginsCommand();
        $spawn = new \ReflectionProperty($command, 'spawn')->getValue($command);
        self::assertInstanceOf(\Closure::class, $spawn);
        $clover = 'coverage/plugins/git.xml';
        $process = $spawn(['plugins/git/tests'], $clover);
        self::assertInstanceOf(Process::class, $process);

        $line = $process->getCommandLine();
        $this->assertStringContainsString('pcov.enabled=1', $line);
        $this->assertStringContainsString('vendor/bin/phpunit', $line);
        $this->assertStringContainsString("'--coverage-filter' 'plugins'", $line);
        $this->assertStringContainsString('plugins/git/tests', $line);
        $this->assertStringContainsString($clover, $line);
        $this->assertSame('on', $process->getEnv()['PORTA_CONTAINER_CACHE'] ?? null);
    }

    public function testTwoTestFilesWithTheSameNameGetTwoShards(): void
    {
        // The shard name comes from the path inside tests/, not the basename:
        // two FooTest.php would otherwise share a report, and the second run
        // would overwrite the first while its tests quietly stopped counting.
        $project = new TempProject([
            'plugins/alpha/tests/View/FooTest.php' => "<?php\n",
            'plugins/alpha/tests/Widget/FooTest.php' => "<?php\n",
        ]);
        $seen = [];

        $this->tester($project, $this->spawning($seen))->execute(['--output-dir' => $project->path('out')]);

        $this->assertSame(['alpha-view-foo.xml', 'alpha-widget-foo.xml'], array_keys($seen));
    }
}
