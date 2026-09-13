<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Console;

use PHPUnit\Framework\TestCase;
use Porta\Console\FlakeScanCommand;
use Porta\Tests\Support\LeakLog;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\TempProject;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The flake instrument's own gate.
 *
 * A harness that cannot see a failure is worse than none: every later claim in
 * the flake work is graded by its output, so "it printed green" has to mean
 * something. These drive it with a scripted runner rather than the real suite —
 * the point is the reporting, and a command that runs the suite ten times is not
 * a unit test.
 */
final class FlakeScanCommandTest extends TestCase
{
    /**
     * A runner that writes a JUnit log naming $failuresPerRun[$run] as failed.
     *
     * @param list<list<string>> $failuresPerRun one entry per run, each a list of `Class::method`
     */
    private function tester(array $failuresPerRun, int $exit = 0): CommandTester
    {
        $run = 0;

        return new CommandTester(new FlakeScanCommand(
            static function (string $log, ?int $processes, ?string $testsuite, int $timeout) use (&$run, $failuresPerRun, $exit): int {
                $cases = '';
                foreach ($failuresPerRun[$run] ?? [] as $test) {
                    [$class, $method] = explode('::', $test);
                    $cases .= \sprintf('<testcase class="%s" name="%s"><failure>boom</failure></testcase>', $class, $method);
                }
                file_put_contents($log, '<?xml version="1.0"?><testsuites><testsuite>'.$cases.'</testsuite></testsuites>');
                ++$run;

                return [] === ($failuresPerRun[$run - 1] ?? []) ? $exit : 1;
            },
        ));
    }

    /**
     * A runner whose runs fail with the exact JUnit bodies given.
     *
     * @param list<array<string, string>> $bodiesPerRun one entry per run: `Class::method` => the case's failure body
     * @param string                      $element      `failure` or `error` — PHPUnit writes both, and a scan has to read both
     */
    private function testerWithBodies(array $bodiesPerRun, string $element = 'failure'): CommandTester
    {
        $run = 0;

        return new CommandTester(new FlakeScanCommand(
            static function (string $log, ?int $processes, ?string $testsuite, int $timeout) use (&$run, $bodiesPerRun, $element): int {
                $cases = '';
                foreach ($bodiesPerRun[$run] ?? [] as $test => $body) {
                    [$class, $method] = explode('::', $test);
                    $cases .= \sprintf(
                        '<testcase class="%s" name="%s"><%s>%s</%s></testcase>',
                        $class,
                        $method,
                        $element,
                        htmlspecialchars($body, \ENT_XML1),
                        $element,
                    );
                }
                file_put_contents($log, '<?xml version="1.0"?><testsuites><testsuite>'.$cases.'</testsuite></testsuites>');
                ++$run;

                return [] === ($bodiesPerRun[$run - 1] ?? []) ? 0 : 1;
            },
        ));
    }

    /**
     * The cell's composition, asserted where it can be seen.
     *
     * Not a detour into private state for its own sake: the rendered table
     * cannot answer this question. SymfonyStyle wraps a long cell at the
     * terminal width, so a cell built without the newline comes back wrapped at
     * nearly the same place, and every mutation of that expression survived
     * both a regex over the display and a "the reason's line does not carry the
     * name" assertion.
     */
    public function testTheCellPutsTheReasonOnItsOwnIndentedLineUnderTheName(): void
    {
        $cell = Probe::invokeStatic(FlakeScanCommand::class, 'testCell', 'A\Test::testOne', 'Timed out');

        $this->assertSame("A\Test::testOne\n  <comment>Timed out</comment>", $cell);
    }

    public function testTheCellSaysSoWhenThereIsNoReason(): void
    {
        $cell = Probe::invokeStatic(FlakeScanCommand::class, 'testCell', 'A\Test::testOne', null);

        $this->assertSame("A\Test::testOne\n  <comment>no message in the log</comment>", $cell);
    }

    /** The reason itself, trimmed line by line — the display's own padding hides this. */
    public function testTheReasonIsTrimmedOfTheWhitespacePhpunitIndentsItWith(): void
    {
        $case = new \SimpleXMLElement('<testcase><failure>A\Test::testOne'."\n   \tFailed asserting that 4 is less than 3   \n".'</failure></testcase>');

        $reason = Probe::invokeStatic(FlakeScanCommand::class, 'reason', $case);

        $this->assertSame('Failed asserting that 4 is less than 3', $reason);
    }

    /** The first display line holding $needle, or '' — the table's own padding included. */
    private function lineContaining(string $display, string $needle): string
    {
        foreach (explode("\n", $display) as $line) {
            if (str_contains($line, $needle)) {
                return $line;
            }
        }

        return '';
    }

    /**
     * The reason a test gave is reported beside it.
     *
     * The scan used to keep the name and delete the log, which is half an
     * instrument: a 2-in-6 intermittent says where to look and nothing about
     * what happened. In the session that added this, the next four suite runs
     * and a deliberate load experiment reproduced nothing at all — and the
     * answer had been in the log the scan deleted.
     */
    public function testItReportsWhyTheTestFailed(): void
    {
        $tester = $this->testerWithBodies([
            ['A\Test::testAwaits' => "A\Test::testAwaits\nTimed out after 5.0s awaiting chroma keyword coloring to land\n\n/app/tests/E2e/X.php:71"],
        ]);

        $tester->execute(['--runs' => 1]);

        $display = $tester->getDisplay();
        $this->assertStringContainsString('Timed out after 5.0s awaiting chroma keyword coloring to land', $display);
        $this->assertStringNotContainsString('/app/tests/E2e/X.php:71', $display, 'the frames belong to whoever reproduces it, not to a rate table');
        // Every column keeps its name. Dropping one shifts what the reader
        // thinks each column is — with `failed` gone, `shape` sits over the rate
        // — and the table still renders, so nothing else would notice.
        $header = $this->lineContaining($display, 'shape');
        $this->assertStringContainsString('failed', $header);
        $this->assertStringContainsString('test and the first reason it gave', $header, 'the column says what it holds');
    }

    public function testTheReasonSkipsThePhpunitHeaderLineThatOnlyRepeatsTheTestName(): void
    {
        // PHPUnit's message opens with `Class::method` (plus any data-set
        // suffix), which the row already says; the line after it is the message.
        $tester = $this->testerWithBodies([
            ['A\Test::testOne' => "A\Test::testOne with data set \"three rows\"\nFailed asserting that 4 is less than 3"],
        ]);

        $tester->execute(['--runs' => 1]);

        $this->assertStringContainsString('Failed asserting that 4 is less than 3', $tester->getDisplay());
    }

    public function testAnErrorIsReadTheSameWayAsAFailure(): void
    {
        $tester = $this->testerWithBodies([
            ['A\Test::testThrows' => "A\Test::testThrows\nRuntimeException: the pty went away"],
        ], element: 'error');

        $tester->execute(['--runs' => 1]);

        $this->assertStringContainsString('RuntimeException: the pty went away', $tester->getDisplay());
    }

    public function testACaseWithNoMessageSaysSoRatherThanShowingNothing(): void
    {
        $tester = $this->testerWithBodies([['A\Test::testMute' => '']]);

        $tester->execute(['--runs' => 1]);

        $this->assertStringContainsString('no message in the log', $tester->getDisplay());
    }

    public function testACaseWhoseMessageIsOnlyWhitespaceCountsAsNoMessage(): void
    {
        // A body of blank lines is what a crashed worker can leave behind, and
        // reporting it verbatim prints a row with an empty second line.
        $tester = $this->testerWithBodies([['A\Test::testBlank' => "  \n\t\n  "]]);

        $tester->execute(['--runs' => 1]);

        $this->assertStringContainsString('no message in the log', $tester->getDisplay());
    }

    public function testACaseWhoseWholeMessageLooksLikeATestNameIsStillShown(): void
    {
        // Every line contains `::`, so the skip rule would leave nothing. Saying
        // the raw body beats saying nothing about a failure that happened.
        $tester = $this->testerWithBodies([['A\Test::testOdd' => 'A\Test::testOdd']]);

        $tester->execute(['--runs' => 1]);

        $this->assertStringContainsString('A\Test::testOdd', $tester->getDisplay());
    }

    public function testTheFirstReasonIsKeptWhenAFlakeFailsTwoDifferentWays(): void
    {
        // Two shapes is two findings; the later run's message would hide the
        // first one, and the first is the one the scan can still be asked about.
        $tester = $this->testerWithBodies([
            ['A\Test::testTwoWays' => "A\Test::testTwoWays\nTimed out awaiting the frame"],
            ['A\Test::testTwoWays' => "A\Test::testTwoWays\nFailed asserting that '' contains 'fn main()'"],
        ]);

        $tester->execute(['--runs' => 2]);

        $display = $tester->getDisplay();
        $this->assertStringContainsString('Timed out awaiting the frame', $display);
        $this->assertStringNotContainsString('contains', $display, 'the first reason is the one kept');
    }

    public function testAGreenSuiteReportsEveryRunGreen(): void
    {
        $tester = $this->tester([[], [], []]);

        $status = $tester->execute(['--runs' => 3]);

        $this->assertSame(Command::SUCCESS, $status);
        $this->assertStringContainsString('3/3 runs green', $tester->getDisplay());
    }

    /**
     * The distinction the whole instrument exists to draw, and the doctrine's
     * own words: a different handful each run is load, the same test every run
     * is a regression.
     */
    public function testItSeparatesAnIntermittentFailureFromAConsistentOne(): void
    {
        $tester = $this->tester([
            ['A\\Test::testAlways', 'B\\Test::testSometimes'],
            ['A\\Test::testAlways'],
            ['A\\Test::testAlways'],
        ]);

        $tester->execute(['--runs' => 3]);
        $display = $tester->getDisplay();

        $this->assertMatchesRegularExpression('/3\/3\s+every run\s+A\\\\Test::testAlways/', $display);
        $this->assertMatchesRegularExpression('/1\/3\s+intermittent\s+B\\\\Test::testSometimes/', $display);
    }

    /** A failing run must not read as green, which is the one lie that matters. */
    public function testAFailingRunIsCountedAgainstTheGreenTally(): void
    {
        $tester = $this->tester([[], ['A\\Test::testOne'], []]);

        $tester->execute(['--runs' => 3]);

        $this->assertStringContainsString('2/3', $tester->getDisplay(), 'one of the three runs was red');
    }

    /**
     * A non-zero exit with no failing test is the wedge signature — the suite
     * died or hung rather than failing an assertion — and it must not be
     * silently counted as green just because the log named nobody.
     */
    public function testANonZeroExitWithNoFailingTestIsFlagged(): void
    {
        $tester = $this->tester([[]], exit: 255);

        $tester->execute(['--runs' => 1]);
        $display = $tester->getDisplay();

        $this->assertStringContainsString('a crash or wedge', $display);
        $this->assertStringContainsString('0/1 runs green', $display);
    }

    public function testItRefusesANonsensicalRunCount(): void
    {
        $tester = $this->tester([[]]);

        $this->assertSame(Command::INVALID, $tester->execute(['--runs' => 0]));
    }

    /** An unreadable or absent log names nobody rather than exploding. */
    public function testAnUnusableJunitLogDoesNotCrashTheScan(): void
    {
        $tester = new CommandTester(new FlakeScanCommand(
            static function (string $log, ?int $processes, ?string $testsuite, int $timeout): int {
                file_put_contents($log, data: 'not xml at all');

                return 1;
            },
        ));

        $status = $tester->execute(['--runs' => 1]);

        $this->assertSame(Command::SUCCESS, $status);
        $this->assertStringContainsString('a crash or wedge', $tester->getDisplay(), 'the run still counts as red');
    }

    /**
     * The real paratest invocation, exercised without running the suite.
     *
     * Every other test here scripts the runner, which leaves the one part that
     * actually shells out ungraded — and a harness whose real invocation is
     * malformed would report a clean green forever. Naming a suite that does
     * not exist makes paratest parse the argv, reject it, and exit in about a
     * second: enough to prove the command line is well-formed and that a run
     * producing no JUnit log is counted as red rather than green.
     */
    public function testTheRealRunnerBuildsAnInvocationParatestUnderstands(): void
    {
        $tester = new CommandTester(new FlakeScanCommand());

        $status = $tester->execute(['--runs' => 1, '--processes' => 2, '--testsuite' => 'NoSuchSuiteExists']);

        $this->assertSame(Command::SUCCESS, $status);
        $this->assertStringContainsString('a crash or wedge', $tester->getDisplay(), 'a run that produced no log is red, not green');
    }

    /**
     * The leak count is read from the file the isolation extensions append to,
     * and honours the same override they use — so a scan can be pointed at a
     * scratch log instead of the shared one.
     *
     * A path that does not exist yet is the ordinary first-run case, and must
     * read as zero leaks rather than failing the scan.
     */
    public function testItReportsLeaksFromTheOverriddenLogAndToleratesItsAbsence(): void
    {
        $project = new TempProject([]);
        $log = $project->path('scratch-leaks.log');
        $previous = getenv(LeakLog::PATH_ENV);
        putenv(LeakLog::PATH_ENV.'='.$log);

        try {
            $tester = new CommandTester(new FlakeScanCommand(
                static function (string $junit, ?int $processes, ?string $testsuite, int $timeout) use ($log): int {
                    file_put_contents($junit, data: '<?xml version="1.0"?><testsuites/>');
                    // What a leaking test would have appended during the run.
                    LeakLog::append("[loop-leak] A\\Test::testOne: 1 identifier(s) leaked\n");
                    LeakLog::append("[env-leak] A\\Test::testOne: PATH\n");

                    return 0;
                },
            ));

            $tester->execute(['--runs' => 1]);

            $this->assertStringContainsString('leak reports across all runs: 2', $tester->getDisplay());
            $this->assertStringContainsString($log, $tester->getDisplay(), 'and says where to read them');
        } finally {
            putenv(\is_string($previous) ? LeakLog::PATH_ENV.'='.$previous : LeakLog::PATH_ENV);
        }
    }

    /**
     * A run in which nothing leaked never creates the log at all, which is the
     * ordinary case on a clean checkout and must read as zero rather than as a
     * missing-file error.
     */
    public function testALogThatWasNeverWrittenReadsAsZeroLeaks(): void
    {
        $project = new TempProject([]);
        $previous = getenv(LeakLog::PATH_ENV);
        putenv(LeakLog::PATH_ENV.'='.$project->path('never-written.log'));

        try {
            $tester = new CommandTester(new FlakeScanCommand(
                static function (string $junit, ?int $processes, ?string $testsuite, int $timeout): int {
                    file_put_contents($junit, data: '<?xml version="1.0"?><testsuites/>');

                    return 0;
                },
            ));

            $tester->execute(['--runs' => 1]);

            $this->assertStringContainsString('leak reports across all runs: 0', $tester->getDisplay());
            $this->assertStringContainsString('clean', $tester->getDisplay());
        } finally {
            putenv(\is_string($previous) ? LeakLog::PATH_ENV.'='.$previous : LeakLog::PATH_ENV);
        }
    }

    /**
     * A run that outlives its budget is reported as wedged, and the scan goes
     * on to the next one.
     *
     * An instrument that blocks on the first wedge cannot measure how often
     * wedges happen — and that is not hypothetical: diagnosing the wedge
     * documented in testing.md cost 36 minutes of a scan that could not move
     * past it.
     */
    public function testAWedgedRunIsReportedAndDoesNotStopTheScan(): void
    {
        $seen = 0;
        $tester = new CommandTester(new FlakeScanCommand(
            static function (string $junit, ?int $processes, ?string $testsuite, int $timeout) use (&$seen): int {
                ++$seen;

                return 1 === $seen ? FlakeScanCommand::WEDGED : 0;
            },
        ));

        $tester->execute(['--runs' => 2]);
        $display = $tester->getDisplay();

        $this->assertSame(2, $seen, 'the scan continued past the wedge');
        $this->assertStringContainsString('WEDGED', $display);
        $this->assertStringContainsString('killed at the budget', $display);
        $this->assertStringContainsString('1/2 runs green', $display);
        // A wedge names no failing test, so a banner keyed on named failures
        // would call this run green — which is the lie the instrument exists
        // to catch elsewhere.
        $this->assertStringNotContainsString('[OK]', $display, 'a wedged run is not a green banner');
    }

    /** The budget reaches the runner, so --timeout is not decorative. */
    public function testTheTimeoutBudgetIsHandedToTheRunner(): void
    {
        $received = null;
        $tester = new CommandTester(new FlakeScanCommand(
            static function (string $junit, ?int $processes, ?string $testsuite, int $timeout) use (&$received): int {
                $received = $timeout;
                file_put_contents($junit, data: '<?xml version="1.0"?><testsuites/>');

                return 0;
            },
        ));

        $tester->execute(['--runs' => 1, '--timeout' => 42]);

        $this->assertSame(42, $received);
    }

    /**
     * The real runner's kill path, exercised for real.
     *
     * A one-second budget cannot outlast paratest's own startup, so this drives
     * the branch that matters — deadline passes, the process tree is killed,
     * WEDGED is reported — without waiting for a genuine 36-minute hang. The
     * scripted tests above cannot reach it: they never start a process.
     */
    public function testTheRealRunnerKillsARunThatOutlivesItsBudget(): void
    {
        $tester = new CommandTester(new FlakeScanCommand());

        $tester->execute(['--runs' => 1, '--processes' => 2, '--testsuite' => 'Integration', '--timeout' => 1]);
        $display = $tester->getDisplay();

        $this->assertStringContainsString('WEDGED', $display);
        $this->assertStringContainsString('killed at the budget', $display);
        $this->assertStringNotContainsString('[OK]', $display);
    }

    /** The worker count is echoed, because a rate is meaningless without it. */
    public function testTheHeaderNamesTheWorkerCountTheRateWasMeasuredAt(): void
    {
        $tester = $this->tester([[]]);

        $tester->execute(['--runs' => 1, '--processes' => 8]);

        $this->assertStringContainsString('at 8 workers', $tester->getDisplay());
    }
}
