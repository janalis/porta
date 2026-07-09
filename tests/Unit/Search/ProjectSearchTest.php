<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Search;

use PHPUnit\Framework\TestCase;
use Porta\Search\ProjectSearch;
use Porta\Search\SearchOptions;
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
    private function await(ProjectSearch $search, string $query, bool $regex = false, bool $caseSensitive = false, bool $wholeWord = false, float $timeout = 3.0, string $scope = '.'): array
    {
        $results = null;
        $search->search(
            $query,
            function (array $r) use (&$results): void {
                $results = $r;
            },
            new SearchOptions($regex, $caseSensitive, $wholeWord),
            $scope,
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

    /** @return list<string> the sorted result paths */
    private function scopedPaths(bool $phpFallback): array
    {
        $project = new TempProject([
            'src/A.php' => "<?php\n// needle in src\n",
            'src/sub/B.php' => "<?php\n// needle deeper in src\n",
            'app/D.php' => "<?php\n// needle in app\n",
            'README.md' => "needle at the root\n",
        ]);
        if ($phpFallback) {
            putenv('PATH=/nonexistent');
        }

        $paths = array_column($this->await(new ProjectSearch($project->dir), 'needle', scope: 'src'), 'path');
        sort($paths);

        return $paths;
    }

    public function testGrepSearchScopedToAFolder(): void
    {
        // Only the src/ subtree is searched; app/ and the root README are excluded,
        // and the returned paths stay project-root-relative.
        $this->assertSame(['src/A.php', 'src/sub/B.php'], $this->scopedPaths(phpFallback: false));
    }

    public function testPhpFallbackScopedToAFolder(): void
    {
        // The Finder-based fallback prefixes the scope back on so paths match grep.
        $this->assertSame(['src/A.php', 'src/sub/B.php'], $this->scopedPaths(phpFallback: true));
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

    /** @return list<array{start: int, length: int}> the ranges of the sole result */
    private function rangesOf(bool $phpFallback, string $content, string $query, bool $regex = false): array
    {
        $project = new TempProject(['a.txt' => $content."\n"]);
        if ($phpFallback) {
            putenv('PATH=/nonexistent');
        }
        $results = $this->await(new ProjectSearch($project->dir), $query, regex: $regex);
        $this->assertCount(1, $results);

        return $results[0]['ranges'];
    }

    public function testGrepMatchRangesAreDisplayColumns(): void
    {
        // "the needle here" → 'needle' starts at column 4, length 6.
        $this->assertSame([['start' => 4, 'length' => 6]], $this->rangesOf(false, 'the needle here', 'needle'));
        // Regex matches map the same way.
        $this->assertSame([['start' => 4, 'length' => 6]], $this->rangesOf(false, 'the needle here', 'ne+dle', regex: true));
        // A multibyte prefix ("café " is 6 bytes but 5 display columns) → start 5.
        $this->assertSame([['start' => 5, 'length' => 6]], $this->rangesOf(false, 'café needle x', 'needle'));
    }

    public function testPhpFallbackMatchRangesMatchGrep(): void
    {
        $this->assertSame([['start' => 4, 'length' => 6]], $this->rangesOf(true, 'the needle here', 'needle'));
        $this->assertSame([['start' => 5, 'length' => 6]], $this->rangesOf(true, 'café needle x', 'needle'));
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
            $search->applyReplacement('take 5 bucks', '5 bucks', '$5.00', new SearchOptions()),
        );
        // Case-insensitive fixed: both casings are replaced.
        $this->assertSame('x x', $search->applyReplacement('ab AB', 'ab', 'x', new SearchOptions()));
        // Whole-word fixed: the standalone X is replaced, "Xy" is not.
        $this->assertSame('a Y b Xy', $search->applyReplacement('a X b Xy', 'X', 'Y', new SearchOptions(caseSensitive: true, wholeWord: true)));
        // Regex with a backreference.
        $this->assertSame('b=a', $search->applyReplacement('a=b', '(\w)=(\w)', '$2=$1', new SearchOptions(regex: true, caseSensitive: true)));
        // Bad regex leaves the content untouched.
        $this->assertSame('a=b', $search->applyReplacement('a=b', '(', 'X', new SearchOptions(regex: true)));
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

        $results = $parse->invoke($search, "src/A.php:3:a match here\ngarbage without line number\n\n./src/B.php:7:no hit\n", '/match/');

        $this->assertSame(
            [
                ['path' => 'src/A.php', 'line' => 2, 'text' => 'a match here', 'ranges' => [['start' => 2, 'length' => 5]]],
                ['path' => 'src/B.php', 'line' => 6, 'text' => 'no hit', 'ranges' => []],
            ],
            $results,
        );
    }

    public function testMatchRangesHandleEmptyLinesAndZeroWidthMatches(): void
    {
        $search = new ProjectSearch('/tmp');
        $ranges = new \ReflectionMethod($search, 'rangesFor');

        // An empty (whitespace-only, trimmed) line yields no ranges.
        $this->assertSame([], $ranges->invoke($search, '', '/x/'));
        // A pattern that can match empty (`l*`) skips its zero-width matches but
        // keeps the real one ("ll" at column 2).
        $this->assertSame([['start' => 2, 'length' => 2]], $ranges->invoke($search, 'hello', '/l*/'));
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
