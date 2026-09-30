<?php

declare(strict_types=1);

namespace Porta\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * The plugin coverage pass, run as several serial phpunit processes at once.
 *
 * Plugin code cannot be graded by the parallel `src` run: each paratest worker
 * serialises its coverage relative to its own longest common directory while
 * the merger keeps only the first worker's base path, so a worker that covered
 * a plugin file has every path resolve wrong and its whole contribution is
 * dropped — silently, and differently each run (`plugin-system.md`). The cure
 * was one serial run, because a serial run never merges.
 *
 * That property is per PROCESS, not per pass. N independent serial runs are N
 * merge-free reports, and `coverage:check` unions them. So the pass keeps its
 * guarantee and stops being one process on a sixteen-core box.
 *
 * The shard list is DERIVED, not written down: one shard per immediate
 * subdirectory of a plugin's `tests/`, plus one for the files directly in it.
 * That covers every `*Test.php` by construction — the loose files and, through
 * the subdirectories, everything else, recursively — which is the point. A
 * written-down list is a second place to add a plugin, and forgetting it would
 * stop running those tests while the gate stayed green on them; the coverage
 * threshold is only a partial backstop, since it catches a dropped shard only
 * when nothing else covers the same lines.
 */
#[AsCommand(
    name: 'coverage:plugins',
    description: 'Run the plugin coverage pass as concurrent serial shards',
    help: <<<'HELP'
        Runs each plugin's tests as its own serial phpunit process under pcov, several
        at a time, and writes one Clover report per shard into <info>--output</info>.
        Grade them with <info>coverage:check <output> 100 --only=/plugins/ --require=plugins</info>,
        which merges the reports per line.

          <info>coverage:plugins</info>                      the composer default worker count
          <info>coverage:plugins --processes=4</info>        a busier box
          <info>coverage:plugins --timeout=300</info>        call a shard wedged sooner

        Each shard is a whole phpunit run: no merge happens inside one, which is the
        property the serial pass existed for.
        HELP,
    usages: ['--processes=8', '--output=coverage/plugins'],
)]
final class CoveragePluginsCommand extends Command
{
    use CommandErrorStream;

    /** The exit code a shard gets when it outlives its budget, matching the `timeout` utility. */
    public const int WEDGED = 124;

    /**
     * Shards at once when `--processes` is omitted.
     *
     * The composer script passes the same half-the-cores expression the other
     * parallel gates use; this is the floor for a hand-run, chosen low because
     * a shard is a whole phpunit process and the box may be shared.
     */
    private const int DEFAULT_CONCURRENCY = 4;

    /** @var \Closure(list<string>, string): Process */
    private readonly \Closure $spawn;

    private readonly string $rootDir;

    /**
     * @param (\Closure(list<string> $paths, string $cloverPath): Process)|null $spawn
     *                                                                                the shard runner; null builds the real phpunit invocation
     *                                                                                (the {@see FlakeScanCommand} pin-injection pattern — but a
     *                                                                                FACTORY rather than a run-to-completion closure, because the
     *                                                                                pool is the thing that needs grading, and a closure that runs
     *                                                                                a shard to completion would leave the pool untested)
     * @param string|null                                                       $rootDir the tree to shard; null is this checkout
     */
    public function __construct(?\Closure $spawn = null, ?string $rootDir = null)
    {
        parent::__construct();
        $this->spawn = $spawn ?? $this->phpunit(...);
        $this->rootDir = $rootDir ?? \dirname(__DIR__, levels: 2);
    }

    public function __invoke(
        InputInterface $input,
        OutputInterface $output,
        #[Option('How many shards to run at once; omit for 4 (composer passes three quarters of the cores)')]
        ?int $processes = null,
        #[Option('Seconds before a shard is called wedged and killed')]
        int $timeout = 600,
        #[Option('Directory for the per-shard Clover reports')]
        string $outputDir = 'coverage/plugins',
    ): int {
        $io = new SymfonyStyle($input, $output);
        $errors = self::errorOutput($output);
        $concurrency = \max(1, $processes ?? self::DEFAULT_CONCURRENCY);

        $shards = $this->shards();
        if ([] === $shards) {
            $errors->writeln('coverage:plugins: no plugin tests found under '.$this->rootDir.'/plugins.');

            return Command::INVALID;
        }

        $io->title(\sprintf('Plugin coverage: %d shards, %d at a time', \count($shards), $concurrency));

        return $this->pool($io, $errors, $shards, $concurrency, $timeout, $outputDir);
    }

    /**
     * The shards, keyed by the report name they write.
     *
     * @return array<string, list<string>> shard name => the paths phpunit is given
     */
    private function shards(): array
    {
        $shards = [];
        // One shard per test FILE. Measured against one per directory: 34s → 26s
        // at the same concurrency, because the pass is only as fast as its
        // longest shard and a directory hides its slowest file inside a bigger
        // one. The cost is a phpunit boot each (~0.7s), which the fan-out
        // absorbs; what it buys is a floor that is a single file.

        $testDirs = \glob($this->rootDir.'/plugins/*/tests', flags: \GLOB_ONLYDIR);
        foreach (\is_array($testDirs) ? $testDirs : [] as $testDir) {
            $plugin = \basename(\dirname($testDir));
            // The files directly in tests/ are their own shard: they are a
            // plugin's E2e as often as not, and they belong to no subdirectory.
            foreach (self::testFiles($testDir) as $file) {
                $shards[$plugin.'-'.self::slug(\substr($file, offset: \strlen($testDir) + 1))] = [$file];
            }
        }
        \ksort($shards);

        return $shards;
    }

    /**
     * A report name for a test file, from its path INSIDE `tests/`.
     *
     * The path, not the basename: two `FooTest.php` in different subdirectories
     * would share a name, and the second shard would overwrite the first's
     * report while its tests quietly stopped counting.
     */
    private static function slug(string $relativePath): string
    {
        return \strtolower(\str_replace(['/', 'test.php'], ['-', ''], \strtolower($relativePath)));
    }

    /**
     * The `*Test.php` files in $directory, which the callers always take from a
     * `GLOB_ONLYDIR` walk — so it exists, and Finder's own throw stays the
     * report for the case that cannot happen.
     *
     * @return list<string> absolute, sorted
     */
    private static function testFiles(string $directory): array
    {
        $finder = new Finder()
            ->files()
            ->in($directory)
            ->name('*Test.php');
        $files = \array_map(static fn (\SplFileInfo $file): string => $file->getPathname(), \iterator_to_array($finder, preserve_keys: false));
        \sort($files);

        return $files;
    }

    /**
     * Run the shards, at most $concurrency at once, and report.
     *
     * @param array<string, list<string>> $shards
     */
    private function pool(SymfonyStyle $io, OutputInterface $errors, array $shards, int $concurrency, int $timeout, string $outputDir): int
    {
        // @mago-expect lint:no-error-control-operator -- an unusable output path is reported below; the warning would arrive first and turn a clear message into a PHP notice.
        if (!\is_dir($outputDir) && !@\mkdir($outputDir, permissions: 0o777, recursive: true) && !\is_dir($outputDir)) {
            $errors->writeln('coverage:plugins: could not create '.$outputDir.'.');

            return Command::INVALID;
        }
        // A report left by an earlier run would be merged into this one, and a
        // shard that has since been renamed or removed would keep voting.
        foreach (\is_array($stale = \glob($outputDir.'/*.xml')) ? $stale : [] as $report) {
            \unlink($report);
        }

        $queue = $shards;
        /** @var array<string, array{process: Process, started: int}> $running */
        $running = [];
        /** @var array<string, array{seconds: float, exit: int}> $results */
        $results = [];
        $wall = \hrtime(as_number: true);
        while ([] !== $queue || [] !== $running) {
            while (\count($running) < $concurrency && [] !== $queue) {
                $name = \array_key_first($queue);
                $paths = $queue[$name];
                unset($queue[$name]);
                $process = ($this->spawn)($paths, $outputDir.'/'.$name.'.xml');
                // The budget belongs to the pool, not to the factory: a shard
                // that hangs holds a slot, and the injected factory in tests
                // has no business knowing what a reasonable one is.
                $process->setTimeout((float) $timeout);
                $process->start();
                $running[$name] = ['process' => $process, 'started' => \hrtime(as_number: true)];
            }
            \usleep(50_000);
            foreach ($running as $name => $shard) {
                $exit = self::settled($shard['process']);
                if (null === $exit) {
                    continue;
                }
                $results[$name] = ['seconds' => (\hrtime(as_number: true) - $shard['started']) / 1_000_000_000, 'exit' => $exit];
                unset($running[$name]);
            }
        }

        return $this->report($io, $errors, $results, (\hrtime(as_number: true) - $wall) / 1_000_000_000);
    }

    /**
     * A finished shard's exit code, or null while it is still running.
     *
     * A shard that outlives its budget is killed and reported as {@see WEDGED}
     * rather than left to hold a slot: paratest's 99% wedge has a phpunit-level
     * cousin, and a pass that hangs forever is worse than one that fails.
     */
    private static function settled(Process $process): ?int
    {
        try {
            // checkTimeout() explicitly: Symfony enforces the budget from
            // wait(), and a pool never waits — it polls.
            $process->checkTimeout();
            if ($process->isRunning()) {
                return null;
            }
        } catch (ProcessTimedOutException) {
            $process->stop(timeout: 5);

            return self::WEDGED;
        }

        return $process->getExitCode() ?? self::WEDGED;
    }

    /**
     * @param array<string, array{seconds: float, exit: int}> $results
     */
    private function report(SymfonyStyle $io, OutputInterface $errors, array $results, float $wall): int
    {
        \uasort($results, static fn (array $a, array $b): int => $b['seconds'] <=> $a['seconds']);
        $io->table(['Shard', 'Seconds', 'Exit'], \array_map(
            static fn (string $name, array $result): array => [$name, \sprintf('%.1f', $result['seconds']), 0 === $result['exit'] ? 'ok' : (string) $result['exit']],
            \array_keys($results),
            \array_values($results),
        ));

        $names = \array_keys($results);
        $sorted = \array_values($results);
        $io->writeln(\sprintf(
            '  %d shards in %.1fs; the slowest was %s at %.1fs — that is the floor.',
            \count($results),
            $wall,
            $names[0] ?? '(none)',
            $sorted[0]['seconds'] ?? 0.0,
        ));

        $failed = \array_filter($results, static fn (array $result): bool => 0 !== $result['exit']);
        if ([] === $failed) {
            $io->writeln('');
            $io->writeln('coverage:plugins: OK');

            return Command::SUCCESS;
        }

        $errors->writeln('');
        $errors->writeln('coverage:plugins: FAILED — these shards did not pass:');
        foreach ($failed as $name => $result) {
            $errors->writeln(\sprintf('  %s (exit %d%s)', $name, $result['exit'], self::WEDGED === $result['exit'] ? ', wedged' : ''));
        }

        return Command::FAILURE;
    }

    /**
     * The real shard: one phpunit run under pcov, filtered to `plugins/`.
     *
     * pcov's flags are built here rather than inherited — this command runs
     * without pcov, and `-d` settings do not cross a process boundary. The
     * container cache is left ON: the reasons `tests/bootstrap.php` turns it
     * off are the parallel `src` run's, and a shard is neither parallel nor
     * grading `src`.
     *
     * @param list<string> $paths
     */
    private function phpunit(array $paths, string $cloverPath): Process
    {
        return new Process(
            [
                \PHP_BINARY,
                '-d',
                'pcov.enabled=1',
                '-d',
                'pcov.directory=.',
                '-d',
                'pcov.exclude=#/(vendor|tests)/#',
                'vendor/bin/phpunit',
                ...$paths,
                '--coverage-filter',
                'plugins',
                '--coverage-clover',
                $cloverPath,
            ],
            $this->rootDir,
            ['PORTA_CONTAINER_CACHE' => 'on'],
        );
    }
}
