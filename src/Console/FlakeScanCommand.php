<?php

declare(strict_types=1);

namespace Porta\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The flakiness instrument: run the suite N times and report which tests fail
 * how often, and how long each run took.
 *
 * A flaky suite cannot be reasoned about from one red run — the whole
 * signature of load-induced failure is that a DIFFERENT handful fails each
 * time, and every one passes in isolation, which is exactly what makes it
 * indistinguishable from a regression by eye. `docs/internals/testing.md` gives
 * the diagnostic order for a single occurrence; this gives the rate.
 *
 * It exists to make the flake work measurable rather than argued. Every change
 * that claims to reduce flakiness is graded by running this before and after at
 * the same worker count, and a change that does not move the number does not
 * land.
 *
 * Reads the JUnit log rather than parsing progress output: paratest's dots say
 * nothing about WHICH test failed, and its worker STDERR is swallowed
 * (see LoopIsolationExtension's docblock).
 */
#[AsCommand(
    name: 'flake:scan',
    description: 'Run the suite repeatedly and report per-test failure rates',
    help: <<<'HELP'
        Runs the test suite <info>--runs</info> times and reports every test that failed at
        least once, with the count, plus per-run wall time.

          <info>flake:scan</info>                          5 runs at the composer default
          <info>flake:scan --runs=10 --processes=8</info>  10 runs, 8 paratest workers
          <info>flake:scan --testsuite=E2e --runs=6</info> only the E2e suite

        A test failing on SOME runs is the load signature; failing on every run is a
        regression. Compare rates before and after a change at the SAME worker count —
        a rate measured at a different --processes is not comparable.
        HELP,
    usages: ['--runs=10 --processes=8', '--testsuite=E2e'],
)]
final class FlakeScanCommand extends Command
{
    use CommandErrorStream;

    /**
     * The exit code a run gets when it outlives its budget, matching the
     * `timeout` utility's convention.
     */
    public const int WEDGED = 124;

    /** @var \Closure(string, ?int, ?string, int): int */
    private readonly \Closure $runner;

    /**
     * @param (\Closure(string $junitLog, ?int $processes, ?string $testsuite, int $timeout): int)|null $runner
     *                                                                                             the suite runner; null uses the real paratest invocation
     *                                                                                             (the ArchitectureCheckCommand pin-injection pattern, so the
     *                                                                                             harness's own reporting can be graded without running the
     *                                                                                             suite it is built to run)
     */
    public function __construct(?\Closure $runner = null)
    {
        parent::__construct();
        $this->runner = $runner ?? $this->paratest(...);
    }

    public function __invoke(
        InputInterface $input,
        OutputInterface $output,
        #[Option('How many times to run the suite')]
        int $runs = 5,
        #[Option('paratest worker count; omit to use the composer default')]
        ?int $processes = null,
        #[Option('Limit to one suite (Unit, Integration, E2e, Plugins)')]
        ?string $testsuite = null,
        #[Option('Seconds before a run is called wedged and killed')]
        int $timeout = 600,
    ): int {
        $io = new SymfonyStyle($input, $output);
        $errors = self::errorOutput($output);
        if ($runs < 1) {
            $errors->writeln('flake:scan: --runs must be at least 1.');

            return Command::INVALID;
        }

        $io->title(\sprintf('Flake scan: %d runs%s', $runs, null !== $processes ? \sprintf(' at %d workers', $processes) : ''));

        // Leak reports accumulate in one file across every worker, and no
        // worker can know whether it is the first — so the delta over a run is
        // the only readable measure. See LeakLog.
        $configuredLeakLog = getenv('PORTA_LEAK_LOG');
        $leakLog = \is_string($configuredLeakLog) && '' !== $configuredLeakLog
            ? $configuredLeakLog
            : \dirname(__DIR__, levels: 2).'/.phpunit.cache/leaks.log';
        $leaks = 0;

        /** @var array<string, int> $failures test id => how many runs it failed in */
        $failures = [];
        /** @var list<float> $durations */
        $durations = [];
        $red = 0;

        for ($run = 1; $run <= $runs; ++$run) {
            $log = (string) tempnam(sys_get_temp_dir(), prefix: 'porta-flake-').'.xml';
            $leaksBefore = is_file($leakLog) ? (int) filesize($leakLog) : 0;
            $started = microtime(as_float: true);
            $exit = ($this->runner)($log, $processes, $testsuite, $timeout);
            $durations[] = microtime(as_float: true) - $started;

            $failed = $this->failedTests($log);
            $leaks += $this->leaksSince($leakLog, $leaksBefore);
            if (is_file($log)) {
                unlink($log);
            }
            if ([] !== $failed || 0 !== $exit) {
                ++$red;
            }
            foreach ($failed as $test) {
                $failures[$test] = ($failures[$test] ?? 0) + 1;
            }

            $io->writeln(\sprintf(
                '  run %d/%d — %s in %.0fs%s',
                $run,
                $runs,
                match (true) {
                    self::WEDGED === $exit => '<comment>WEDGED</comment>',
                    [] === $failed && 0 === $exit => '<info>green</info>',
                    default => \sprintf('<comment>%d failed</comment>', \count($failed)),
                },
                end($durations),
                match (true) {
                    self::WEDGED === $exit => ' <comment>(killed at the budget — see the wedge section of testing.md)</comment>',
                    [] === $failed && 0 !== $exit => ' <comment>(non-zero exit with no failing test — a crash or wedge)</comment>',
                    default => '',
                },
            ));
        }

        $this->report($io, $failures, $durations, $runs, $red, $leaks, $leakLog);

        // Always 0: this is an instrument, not a gate. A rate is a measurement
        // to compare, and failing the command would make it unusable as the
        // "before" half of a before/after pair.
        return Command::SUCCESS;
    }

    /**
     * Run the suite once, writing JUnit to $log; returns the process exit code,
     * or {@see WEDGED} when it outlived its budget.
     *
     * The budget is not a nicety: paratest can reach ~99% and hang forever when
     * a worker exits without writing its result (testing.md documents the
     * signature), and an instrument that blocks on the first wedge cannot
     * measure how often wedges happen. A killed run is reported as one, not
     * silently dropped.
     */
    private function paratest(string $log, ?int $processes, ?string $testsuite, int $timeout): int
    {
        $argv = [\PHP_BINARY, 'vendor/bin/paratest', '--functional', '--log-junit='.$log];
        if (null !== $processes) {
            $argv[] = '--processes='.$processes;
        }
        if (null !== $testsuite) {
            $argv[] = '--testsuite='.$testsuite;
        }

        // Output is discarded: the JUnit log is the record, and a suite's worth
        // of progress dots per run would bury the summary this command is for.
        $command = implode(' ', array_map(escapeshellarg(...), $argv)).' >/dev/null 2>&1';
        $descriptors = [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']];
        $pipes = [];
        $process = proc_open($command, $descriptors, $pipes);

        // A failed proc_open and an overrun share the wedged exit below rather
        // than getting a return each: they mean the same thing to a scan (this
        // run produced no result), and the alternative is a branch no test can
        // reach short of exhausting the process table.
        if (\is_resource($process)) {
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            $exit = $this->awaitExit($process, $timeout);
            if (null !== $exit) {
                return $exit;
            }
            // Kill the tree: paratest's workers do not die with it, and orphans
            // would poison every later run in the scan.
            proc_terminate($process, signal: 9);
            proc_close($process);
        }

        return self::WEDGED;
    }

    /**
     * The exit code once the run finishes, or null when it outlives $timeout.
     *
     * @param resource $process
     */
    private function awaitExit($process, int $timeout): ?int
    {
        $deadline = microtime(as_float: true) + $timeout;
        while (microtime(as_float: true) < $deadline) {
            $status = proc_get_status($process);
            if (!$status['running']) {
                proc_close($process);

                return (int) $status['exitcode'];
            }
            usleep(microseconds: 200_000);
        }

        return null;
    }

    /**
     * How many `[loop-leak]`/`[env-leak]` lines this run appended.
     *
     * A rising count is the leading indicator of the failure this instrument
     * exists for: a test that walked out mid-flight leaves state behind, and
     * the damage lands in whatever runs next. Reported beside the flake rate
     * because the two move together.
     */
    private function leaksSince(string $path, int $offset): int
    {
        if (!is_file($path)) {
            return 0;
        }
        // Read from the offset in one call rather than fopen/fseek: the same
        // read without a failure branch no test can reach, since the file's
        // existence is already established above.
        $added = (string) file_get_contents($path, offset: $offset);

        return substr_count($added, needle: "\n");
    }

    /**
     * The ids of tests that failed or errored in a JUnit log.
     *
     * @return list<string>
     */
    private function failedTests(string $log): array
    {
        if (!is_file($log)) {
            return [];
        }
        // Internal errors, not `@`: a malformed log is an expected input here
        // (a crashed run writes a truncated one), and it must degrade to
        // "named nobody" rather than emit a warning that PHPUnit turns into a
        // risky-test failure.
        $previous = libxml_use_internal_errors(use_errors: true);
        $xml = simplexml_load_file($log);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (false === $xml) {
            return [];
        }
        $failed = [];
        // xpath() answers false on a bad expression and null on some libxml
        // failures; both mean "no cases", and neither may reach foreach.
        $cases = $xml->xpath('//testcase[failure or error]');
        foreach (\is_array($cases) ? $cases : [] as $case) {
            $class = (string) ($case['class'] ?? '');
            $name = (string) ($case['name'] ?? '');
            $failed[] = '' === $class ? $name : $class.'::'.$name;
        }

        return array_values(array_unique($failed));
    }

    /**
     * @param array<string, int> $failures
     * @param list<float>        $durations
     */
    private function report(SymfonyStyle $io, array $failures, array $durations, int $runs, int $red, int $leaks, string $leakLog): void
    {
        $io->newLine();
        // Keyed on RED RUNS, not on named failures. A wedged or crashed run
        // names nobody, and banner-ing that as green is the same defect this
        // instrument exists to catch in the suite.
        if (0 === $red) {
            $io->success(\sprintf('%d/%d runs green.', $runs, $runs));
        }
        if ([] !== $failures) {
            arsort($failures);
            $rows = [];
            foreach ($failures as $test => $count) {
                $rows[] = [
                    \sprintf('%d/%d', $count, $runs),
                    // The distinction the doctrine draws: every run is a
                    // regression, some runs is load.
                    $count === $runs ? 'every run' : 'intermittent',
                    $test,
                ];
            }
            $io->table(['failed', 'shape', 'test'], $rows);
        }

        sort($durations);
        $io->writeln(\sprintf(
            '  <info>%d/%d</info> runs green   wall time min <info>%.0fs</info> / median <info>%.0fs</info> / max <info>%.0fs</info>',
            $runs - $red,
            $runs,
            $durations[0],
            $durations[intdiv(\count($durations), num2: 2)],
            $durations[\count($durations) - 1],
        ));
        $io->writeln(\sprintf(
            '  leak reports across all runs: <info>%d</info>   (%s)',
            $leaks,
            0 === $leaks ? 'clean' : 'read them in '.$leakLog,
        ));
        $io->writeln('  <comment>Compare rates only against a scan at the same --processes.</comment>');
    }
}
