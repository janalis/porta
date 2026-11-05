<?php

declare(strict_types=1);

namespace Porta\PhpUnit\Tests;

use PHPUnit\Framework\TestCase;
use Porta\PhpUnit\TestOutcome;
use Porta\PhpUnit\TestResults;
use Porta\Tests\Support\TempProject;

/** One run's outcomes as the dock, the marks and Rerun Failed read them. */
final class TestResultsTest extends TestCase
{
    private const string A = '/porta-no-such-root/tests/ATest.php';
    private const string B = '/porta-no-such-root/tests/BTest.php';

    private static function outcome(string $name, string $status, string $file = self::A, int $line = 4): TestOutcome
    {
        return new TestOutcome('App\ATest', \explode(' ', $name)[0], $name, $file, $line, $status, '', $line);
    }

    /** @param list<TestOutcome> $outcomes */
    private static function names(array $outcomes): array
    {
        return \array_map(static fn (TestOutcome $outcome): string => $outcome->name, $outcomes);
    }

    public function testFailuresLeadThenSkippedThenPassedEachInRunOrder(): void
    {
        $results = new TestResults([
            self::outcome('p1', TestOutcome::PASSED),
            self::outcome('f1', TestOutcome::FAILED),
            self::outcome('s1', TestOutcome::SKIPPED),
            self::outcome('e1', TestOutcome::ERRORED),
            self::outcome('p2', TestOutcome::PASSED),
            self::outcome('f2', TestOutcome::FAILED),
            self::outcome('s2', TestOutcome::SKIPPED),
        ]);

        $this->assertSame(['f1', 'e1', 'f2', 's1', 's2', 'p1', 'p2'], self::names($results->outcomes), 'a failure and an error rank alike');
        $this->assertSame(['f1', 'e1', 'f2'], self::names($results->broken()));
        $this->assertSame([], new TestResults()->outcomes);
    }

    public function testTheBrokenFilesEachOnce(): void
    {
        $results = new TestResults([
            self::outcome('f1', TestOutcome::FAILED, self::B),
            self::outcome('p1', TestOutcome::PASSED, self::A),
            self::outcome('f2', TestOutcome::FAILED, self::A),
            self::outcome('e1', TestOutcome::ERRORED, self::B),
        ]);

        $this->assertSame([self::B, self::A], $results->brokenFiles());
        $this->assertSame(['f2'], self::names($results->brokenIn(self::A)), 'only the broken, only in that file');
        $this->assertSame(['f1', 'e1'], self::names($results->brokenIn(self::B)));
    }

    public function testALineTakesTheWorstOfItsDataSets(): void
    {
        $results = new TestResults([
            self::outcome('provided with data set #0', TestOutcome::PASSED, line: 4),
            self::outcome('provided with data set #1', TestOutcome::FAILED, line: 4),
            self::outcome('provided with data set #2', TestOutcome::PASSED, line: 4),
            self::outcome('skips with data set #0', TestOutcome::PASSED, line: 9),
            self::outcome('skips with data set #1', TestOutcome::SKIPPED, line: 9),
            self::outcome('errs with data set #0', TestOutcome::FAILED, line: 12),
            self::outcome('errs with data set #1', TestOutcome::ERRORED, line: 12),
            self::outcome('passes', TestOutcome::PASSED, line: 15),
            self::outcome('elsewhere', TestOutcome::FAILED, self::B, line: 15),
        ]);

        $this->assertSame(
            [4 => TestOutcome::FAILED, 12 => TestOutcome::FAILED, 9 => TestOutcome::SKIPPED, 15 => TestOutcome::PASSED],
            $results->lines(self::A),
            'a failure outranks a pass after it, a skip a pass, and the first of two equal failures stays',
        );
        $this->assertSame([15 => TestOutcome::FAILED], $results->lines(self::B));
        $this->assertSame([], $results->lines(null), 'nothing open, nothing marked');
        $this->assertSame([], $results->lines('/porta-no-such-root/README.md'));
    }

    public function testAFileIsTheSameFileWhicheverWayItIsSpelled(): void
    {
        $project = new TempProject(['real/tests/ATest.php' => '<?php']);
        \symlink($project->path('real'), $project->path('link'));
        $results = new TestResults([self::outcome('f1', TestOutcome::FAILED, $project->path('link/tests/ATest.php'))]);
        $real = (string) \realpath($project->path('real/tests/ATest.php'));

        $this->assertSame(['f1'], self::names($results->brokenIn($real)));
        $this->assertSame([4 => TestOutcome::FAILED], $results->lines($real));
    }

    public function testEachStatusHasItsGlyphAndWord(): void
    {
        $this->assertSame(
            [['✓', 'test passed'], ['⊘', 'test skipped'], ['✗', 'test failed'], ['✗', 'test failed']],
            \array_map(
                static fn (string $status): array => [TestOutcome::glyph($status), TestOutcome::verdict($status)],
                [TestOutcome::PASSED, TestOutcome::SKIPPED, TestOutcome::FAILED, TestOutcome::ERRORED],
            ),
        );
    }
}
