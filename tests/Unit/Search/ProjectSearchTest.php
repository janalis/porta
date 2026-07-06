<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Search;

use PHPUnit\Framework\TestCase;
use Porta\Search\ProjectSearch;
use Porta\Tests\Support\TempProject;
use Revolt\EventLoop;

final class ProjectSearchTest extends TestCase
{
    private string $originalPath;

    protected function setUp(): void
    {
        $this->originalPath = (string) getenv('PATH');
    }

    protected function tearDown(): void
    {
        putenv('PATH='.$this->originalPath);
    }

    /** @return list<array{path: string, line: int, text: string}> */
    private function await(ProjectSearch $search, string $query, bool $regex = false, bool $caseSensitive = false, bool $wholeWord = false, float $timeout = 3.0): array
    {
        $results = null;
        $search->search(
            $query,
            function (array $r) use (&$results): void {
                $results = $r;
            },
            $regex,
            $caseSensitive,
            $wholeWord,
        );

        $elapsed = 0.0;
        while (null === $results && $elapsed < $timeout) {
            $suspension = EventLoop::getSuspension();
            EventLoop::delay(0.02, static fn () => $suspension->resume());
            $suspension->suspend();
            $elapsed += 0.02;
        }
        $this->assertNotNull($results, 'search completed');

        return $results;
    }

    private function fixtures(): TempProject
    {
        return new TempProject([
            'src/A.php' => "<?php\nclass A { public \$needle = 1; }\n",
            'src/B.php' => "<?php\n// needle here too\n",
            'vendor/lib/C.php' => "<?php\n// needle in vendor is excluded\n",
            'README.md' => "no match\n",
        ]);
    }

    public function testGrepSearchFindsMatchesAndExcludesVendor(): void
    {
        $project = $this->fixtures();
        $results = $this->await(new ProjectSearch($project->dir), 'needle');

        $paths = array_column($results, 'path');
        sort($paths);
        $this->assertSame(['src/A.php', 'src/B.php'], $paths);
        $this->assertSame(1, $results[0]['line'], '0-based line index');
    }

    public function testPhpFallbackWhenGrepUnavailable(): void
    {
        $project = $this->fixtures();
        // Also exercise the binary-file and oversized-file skips
        $project->write('src/bin.dat', "nee\0dle");
        $project->write('src/huge.txt', str_repeat('needle filler ', 80_000)); // > 1 MB, skipped
        putenv('PATH=/nonexistent');

        $results = $this->await(new ProjectSearch($project->dir), 'needle');

        $paths = array_column($results, 'path');
        sort($paths);
        $this->assertSame(['src/A.php', 'src/B.php'], $paths);
    }

    public function testEmptyQueryReturnsNothing(): void
    {
        $project = $this->fixtures();
        $results = $this->await(new ProjectSearch($project->dir), '   ');

        $this->assertSame([], $results);
    }

    public function testRegexAndOptionsWithGrep(): void
    {
        $project = new TempProject([
            'src/A.php' => "<?php\n\$needle = 1;\n\$NEEDLES = 2;\nneedless();\n",
        ]);
        $search = new ProjectSearch($project->dir);

        // Regex.
        $this->assertNotSame([], $this->await($search, 'ne+dle', regex: true));
        // Case-sensitive: NEEDLES does not match lowercase "needle".
        $lines = array_column($this->await($search, 'needle', caseSensitive: true), 'line');
        $this->assertNotContains(2, $lines, 'case-sensitive skips $NEEDLES');
        // Whole-word: "needle" must not match inside "needless".
        $wordLines = array_column($this->await($search, 'needle', wholeWord: true), 'line');
        $this->assertNotContains(3, $wordLines, 'whole-word skips needless()');
        $this->assertContains(1, $wordLines);
    }

    public function testRegexAndOptionsWithPhpFallback(): void
    {
        $project = new TempProject([
            'src/A.php' => "<?php\n\$needle = 1;\n\$NEEDLES = 2;\nneedless();\n",
        ]);
        putenv('PATH=/nonexistent');
        $search = new ProjectSearch($project->dir);

        $this->assertNotSame([], $this->await($search, 'ne+dle', regex: true));
        $this->assertNotContains(2, array_column($this->await($search, 'needle', caseSensitive: true), 'line'));
        $wordLines = array_column($this->await($search, 'needle', wholeWord: true), 'line');
        $this->assertNotContains(3, $wordLines);
        $this->assertContains(1, $wordLines);
    }

    public function testInvalidRegexYieldsNoResults(): void
    {
        $project = $this->fixtures();
        // Unbalanced group is not a valid pattern.
        $this->assertSame([], $this->await(new ProjectSearch($project->dir), 'needle(', regex: true));
    }

    public function testApplyReplacement(): void
    {
        $search = new ProjectSearch('/tmp');

        // Fixed, literal replacement: $ and \ in the replacement stay literal.
        $this->assertSame(
            'take $5.00',
            $search->applyReplacement('take 5 bucks', '5 bucks', '$5.00', false, false, false),
        );
        // Case-insensitive fixed: both casings are replaced.
        $this->assertSame('x x', $search->applyReplacement('ab AB', 'ab', 'x', false, false, false));
        // Whole-word fixed: the standalone X is replaced, "Xy" is not.
        $this->assertSame('a Y b Xy', $search->applyReplacement('a X b Xy', 'X', 'Y', false, true, true));
        // Regex with a backreference.
        $this->assertSame('b=a', $search->applyReplacement('a=b', '(\w)=(\w)', '$2=$1', true, true, false));
        // Bad regex leaves the content untouched.
        $this->assertSame('a=b', $search->applyReplacement('a=b', '(', 'X', true, false, false));
    }

    public function testResultCapWithGrep(): void
    {
        $project = new TempProject([
            'big.txt' => str_repeat("needle\n", ProjectSearch::MAX_RESULTS + 200),
        ]);

        $results = $this->await(new ProjectSearch($project->dir), 'needle', timeout: 5.0);

        $this->assertCount(ProjectSearch::MAX_RESULTS, $results);
    }

    public function testResultCapWithPhpFallback(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject([
            'big.txt' => str_repeat("needle\n", ProjectSearch::MAX_RESULTS + 200),
        ]);

        $results = $this->await(new ProjectSearch($project->dir), 'needle', timeout: 5.0);

        $this->assertCount(ProjectSearch::MAX_RESULTS, $results);
    }

    public function testBrokenGrepBinaryYieldsEmptyResultsWithoutCrashing(): void
    {
        $tools = new TempProject([], 'porta-badgrep-');
        $badGrep = $tools->write('grep', "\x00\x01 not an executable format");
        chmod($badGrep, 0755);
        putenv('PATH='.$tools->dir);

        $project = $this->fixtures();
        $results = $this->await(new ProjectSearch($project->dir), 'needle');

        $this->assertSame([], $results, 'spawn succeeds but the dead grep produces no output');
    }

    public function testParseSkipsMalformedGrepLines(): void
    {
        $search = new ProjectSearch('/tmp');
        $parse = new \ReflectionMethod($search, 'parse');

        $results = $parse->invoke($search, "src/A.php:3:match\ngarbage without line number\n\n./src/B.php:7:other\n");

        $this->assertSame(
            [['path' => 'src/A.php', 'line' => 2, 'text' => 'match'], ['path' => 'src/B.php', 'line' => 6, 'text' => 'other']],
            $results,
        );
    }

    public function testCancelStopsInFlightSearch(): void
    {
        $project = $this->fixtures();
        $search = new ProjectSearch($project->dir);

        $called = false;
        $search->search('needle', function () use (&$called): void {
            $called = true;
        });
        $search->cancel();

        // Give the (cancelled) watcher time to have fired if cancel failed
        $suspension = EventLoop::getSuspension();
        EventLoop::delay(0.3, static fn () => $suspension->resume());
        $suspension->suspend();

        $this->assertFalse($called);
    }

    public function testNewSearchCancelsPrevious(): void
    {
        $project = $this->fixtures();
        $search = new ProjectSearch($project->dir);

        $firstCompleted = false;
        $search->search('needle', function () use (&$firstCompleted): void {
            $firstCompleted = true;
        });
        $results = $this->await($search, 'needle');

        $this->assertFalse($firstCompleted, 'first search superseded');
        $this->assertNotEmpty($results);
    }
}
