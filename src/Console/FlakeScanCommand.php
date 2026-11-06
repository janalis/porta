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

          <info>flake:scan</info>                          5 runs at <info>PORTA_TEST_PROCESSES</info> workers
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
        #[Option('paratest worker count; omit to read PORTA_TEST_PROCESSES, else paratest\'s own default')]
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

        $processes = self::resolveProcesses($processes);
        // The count is ALWAYS named, because a rate measured at a different one
        // is not comparable and a header that omits it invites that mistake.
        $io->title(\sprintf(
            'Flake scan: %d runs at %s',
            $runs,
            null !== $processes ? \sprintf('%d workers', $processes) : "paratest's own default (every core)",
        ));

        // Leak reports accumulate in one file across every worker, and no
        // worker can know whether it is the first — so the delta over a run is
        // the only readable measure. See LeakLog.
        $configuredLeakLog = \getenv('PORTA_LEAK_LOG');
        $leakLog = \is_string($configuredLeakLog) && '' !== $configuredLeakLog
            ? $configuredLeakLog
            : \dirname(__DIR__, levels: 2).'/.phpunit.cache/leaks.log';
        $leaks = 0;

        /** @var array<string, int> $failures test id => how many runs it failed in */
        $failures = [];
        /** @var array<string, string> $reasons test id => the first reason it gave */
        $reasons = [];
        /** @var list<float> $durations */
        $durations = [];
        $red = 0;

        for ($run = 1; $run <= $runs; ++$run) {
            // tempnam() makes the name it hands out — the reserved, empty file
            // goes with the log, or every run left one behind for good.
            $name = (string) \tempnam(\sys_get_temp_dir(), prefix: 'porta-flake-');
            $log = $name.'.xml';
            $leaksBefore = \is_file($leakLog) ? (int) \filesize($leakLog) : 0;
            $started = \hrtime(as_number: true);
            $exit = ($this->runner)($log, $processes, $testsuite, $timeout);
            $durations[] = (\hrtime(as_number: true) - $started) / 1_000_000_000;

            $failed = $this->failedTests($log);
            $leaks += $this->leaksSince($leakLog, $leaksBefore);
            foreach ([$log, $name] as $file) {
                if (!\is_file($file)) {
                    continue;
                }
                \unlink($file);
            }
            if ([] !== $failed || 0 !== $exit) {
                ++$red;
            }
            foreach ($failed as $test => $reason) {
                $failures[$test] = ($failures[$test] ?? 0) + 1;
                // The FIRST reason a test gave, kept rather than overwritten: a
                // flake that fails two different ways is two findings, and the
                // later run's message would hide the earlier one.
                $reasons[$test] ??= $reason;
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
                \end($durations),
                match (true) {
                    self::WEDGED === $exit => ' <comment>(killed at the budget — see the wedge section of testing.md)</comment>',
                    [] === $failed && 0 !== $exit => ' <comment>(non-zero exit with no failing test — a crash or wedge)</comment>',
                    default => '',
                },
            ));
        }

        $this->report($io, $failures, $reasons, $durations, $runs, $red, $leaks, $leakLog);

        // Always 0: this is an instrument, not a gate. A rate is a measurement
        // to compare, and failing the command would make it unusable as the
        // "before" half of a before/after pair.
        return Command::SUCCESS;
    }

    /**
     * The worker count a run will actually use.
     *
     * `--processes` first, then `PORTA_TEST_PROCESSES` — the variable every
     * composer test script reads — and only then paratest's own default, which
     * is `auto`, i.e. every core. Omitting the option used to fall straight
     * through to that while the help text called it "the composer default",
     * which is half the cores: a bare `flake:scan` ran at twice the
     * parallelism it claimed, on a box the doctrine assumes is shared, and
     * a rate is only comparable against another at the SAME count.
     *
     * A non-numeric value (`auto`) resolves to null on purpose: that is what
     * the variable means, and it is what paratest does with no flag.
     */
    private static function resolveProcesses(?int $processes): ?int
    {
        if (null !== $processes) {
            return $processes;
        }
        $env = \getenv('PORTA_TEST_PROCESSES');

        return \is_numeric($env) ? (int) $env : null;
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
        $command = \implode(' ', \array_map(escapeshellarg(...), $argv)).' >/dev/null 2>&1';
        $descriptors = [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']];
        $pipes = [];
        $process = \proc_open($command, $descriptors, $pipes);

        // A failed proc_open and an overrun share the wedged exit below rather
        // than getting a return each: they mean the same thing to a scan (this
        // run produced no result), and the alternative is a branch no test can
        // reach short of exhausting the process table.
        if (\is_resource($process)) {
            foreach ($pipes as $pipe) {
                \fclose($pipe);
            }
            $exit = $this->awaitExit($process, $timeout);
            if (null !== $exit) {
                return $exit;
            }
            // Kill the tree: paratest's workers do not die with it, and orphans
            // would poison every later run in the scan. The handle is the shell
            // the command line went through, so killing only it left paratest
            // itself running the suite on — every descendant goes, then it.
            \array_map(static fn (int $pid): bool => \posix_kill($pid, signal: 9), self::descendants(\proc_get_status($process)['pid']));
            \proc_terminate($process, signal: 9);
            \proc_close($process);
        }

        return self::WEDGED;
    }

    /**
     * Every process below $root, as `ps` lists them now: children, theirs,
     * and so on down. Read whole before anything is killed, since a killed
     * parent's children are handed to init and no longer look like its own.
     *
     * @return list<int>
     */
    private static function descendants(int $root): array
    {
        $children = [];
        foreach (\explode("\n", (string) \shell_exec('ps -A -o pid= -o ppid=')) as $row) {
            $pair = \preg_split('/\s+/', \trim($row));
            if (false !== $pair && 2 === \count($pair)) {
                $children[(int) $pair[1]][] = (int) $pair[0];
            }
        }
        $found = [];
        for ($queue = [$root]; [] !== $queue;) {
            foreach ($children[\array_shift($queue)] ?? [] as $child) {
                $found[] = $child;
                $queue[] = $child;
            }
        }

        return $found;
    }

    /**
     * The exit code once the run finishes, or null when it outlives $timeout.
     *
     * @param resource $process
     */
    private function awaitExit($process, int $timeout): ?int
    {
        // Monotonic, like every other budget in this tree: a suspended laptop
        // advances the wall clock while the child was not running at all, and
        // the run would come back "wedged" for having been asleep.
        $deadline = \hrtime(as_number: true) + ($timeout * 1_000_000_000);
        while (\hrtime(as_number: true) < $deadline) {
            $status = \proc_get_status($process);
            if (!$status['running']) {
                \proc_close($process);

                return (int) $status['exitcode'];
            }
            \usleep(microseconds: 200_000);
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
        if (!\is_file($path)) {
            return 0;
        }
        // Read from the offset in one call rather than fopen/fseek: the same
        // read without a failure branch no test can reach, since the file's
        // existence is already established above.
        $added = (string) \file_get_contents($path, offset: $offset);

        return \substr_count($added, needle: "\n");
    }

    /**
     * The tests that failed or errored in a JUnit log, each with the reason it
     * gave.
     *
     * Collected as pairs and keyed in a second pass, which is not ceremony: keyed
     * directly from the `'' === $class ? … : …` expression, the analyzer widens
     * the key to `non-negative-int|string` (PHP coerces a numeric-looking string
     * key to an int, and it cannot rule that out of a ternary). Keying off the
     * destructured variable, whose type it has already settled, says the same
     * thing in a way it can follow.
     *
     * @return array<string, string>
     */
    private function failedTests(string $log): array
    {
        $xml = false;
        if (\is_file($log)) {
            // Internal errors, not `@`: a malformed log is an expected input here
            // (a crashed run writes a truncated one), and it must degrade to
            // "named nobody" rather than emit a warning that PHPUnit turns into a
            // risky-test failure.
            $previous = \libxml_use_internal_errors(use_errors: true);
            $xml = \simplexml_load_file($log);
            \libxml_clear_errors();
            \libxml_use_internal_errors($previous);
        }
        $failed = [];
        // xpath() answers false on a bad expression and null on some libxml
        // failures; both mean "no cases", and neither may reach foreach. A
        // missing or unreadable log has no cases either.
        $cases = false === $xml ? [] : $xml->xpath('//testcase[failure or error]');
        foreach (\is_array($cases) ? $cases : [] as $case) {
            $class = (string) ($case['class'] ?? '');
            $name = (string) ($case['name'] ?? '');
            $failed[] = ['' === $class ? $name : $class.'::'.$name, self::reason($case)];
        }
        $out = [];
        foreach ($failed as [$id, $reason]) {
            $out[$id] = $reason;
        }

        return $out;
    }

    /**
     * One failing test's cell: its id, and BENEATH it the reason it gave.
     *
     * Its own method because the rendered table cannot be asserted on this
     * shape. `SymfonyStyle` wraps a long cell at the terminal width, so a cell
     * with the newline taken out comes back wrapped at very nearly the same
     * place — measured: every concat mutation of this expression survived a
     * regex over the rendered output, and one survived an assertion that the
     * reason's line does not also carry the test's name. The composition is the
     * contract; the wrap is a coincidence that cannot be asked about.
     */
    private static function testCell(string $test, ?string $reason): string
    {
        return $test."\n  <comment>".($reason ?? 'no message in the log').'</comment>';
    }

    /**
     * PHPUnit's opening line: the whole line is a test id, with the data-set
     * suffix it prints for a provider case.
     *
     * Anchored at both ends, and that is the point. The first version of this
     * skipped any line CONTAINING `::`, which is how the first flake it caught
     * for real reported a stack frame instead of a message: the message was
     *
     *     Revolt\EventLoop\UncaughtThrowable: Uncaught RuntimeException … thrown
     *     in event loop error handler Porta\Tests\Support\LoopErrors::{closure…}
     *
     * and a rule that skips every `::` skips that, then lands on the first
     * frame — which has no `::` in it and says nothing.
     */
    private const string TEST_ID_LINE = '/^[\w\\\\]+::\w+(?: with data set .*)?$/';

    /**
     * The first meaningful line of a failed case's message.
     *
     * The scan used to keep the NAME and delete the log, which is half an
     * instrument: a 2-in-6 intermittent tells you where to look and nothing
     * about what happened, and the next thing anyone does is try to reproduce it
     * — four suite runs and a load experiment, in the session that added this,
     * none of which reproduced anything. The reason was in the log the scan had
     * already thrown away.
     *
     * Trimmed to one line because the shape is what matters at this level: a
     * timeout, an assertion on a screen, a subprocess that never answered. The
     * frames belong to whoever reproduces it next.
     */
    private static function reason(\SimpleXMLElement $case): string
    {
        // xpath rather than `$case->failure`: a SimpleXMLElement's children are
        // dynamic properties, which the analyzer can only call ambiguous, and
        // one expression reads both element names PHPUnit uses anyway.
        $nodes = $case->xpath('failure|error');
        // @noinspection ProperNullCoalescingOperatorUsageInspection -- the element or nothing, both cast to the text
        $body = \trim((string) (\is_array($nodes) ? $nodes[0] ?? '' : ''));
        foreach (\explode("\n", $body) as $line) {
            $line = \trim($line);
            if ('' !== $line && 1 !== \preg_match(self::TEST_ID_LINE, $line)) {
                return $line;
            }
        }

        return '' === $body ? 'no message in the log' : $body;
    }

    /**
     * @param array<string, int>    $failures
     * @param array<string, string> $reasons
     * @param list<float>           $durations
     */
    private function report(SymfonyStyle $io, array $failures, array $reasons, array $durations, int $runs, int $red, int $leaks, string $leakLog): void
    {
        $io->newLine();
        // Keyed on RED RUNS, not on named failures. A wedged or crashed run
        // names nobody, and banner-ing that as green is the same defect this
        // instrument exists to catch in the suite.
        if (0 === $red) {
            $io->success(\sprintf('%d/%d runs green.', $runs, $runs));
        }
        if ([] !== $failures) {
            \arsort($failures);
            $rows = [];
            foreach ($failures as $test => $count) {
                $rows[] = [
                    \sprintf('%d/%d', $count, $runs),
                    // The distinction the doctrine draws: every run is a
                    // regression, some runs is load.
                    $count === $runs ? 'every run' : 'intermittent',
                    self::testCell($test, $reasons[$test] ?? null),
                ];
            }
            $io->table(['failed', 'shape', 'test and the first reason it gave'], $rows);
        }

        \sort($durations);
        $io->writeln(\sprintf(
            '  <info>%d/%d</info> runs green   wall time min <info>%.0fs</info> / median <info>%.0fs</info> / max <info>%.0fs</info>',
            $runs - $red,
            $runs,
            $durations[0],
            $durations[\intdiv(\count($durations), num2: 2)],
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
