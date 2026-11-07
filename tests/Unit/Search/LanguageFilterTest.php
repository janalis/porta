<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Search;

use PHPUnit\Framework\TestCase;
use Porta\Search\LanguageFilter;
use Porta\Search\ProjectSearch;
use Porta\Search\SearchOptions;
use Porta\Search\TokenClassifier;
use Porta\Search\TokenScope;
use Porta\Support\ProjectPaths;
use Porta\Tests\Support\TempProject;

final class LanguageFilterTest extends TestCase
{
    private function filter(TempProject $project): LanguageFilter
    {
        return new LanguageFilter(new TokenClassifier(), new ProjectSearch($project->dir), new ProjectPaths($project->dir));
    }

    /**
     * The three matching lines of `needle` in a.php: code, string, comment.
     *
     * @return list<array{path: string, line: int, text: string, ranges: list<array{start: int, length: int}>}>
     */
    private function results(): array
    {
        return [
            ['path' => 'a.php', 'line' => 1, 'text' => 'needle();', 'ranges' => []],
            ['path' => 'a.php', 'line' => 2, 'text' => "\$s = 'needle';", 'ranges' => []],
            ['path' => 'a.php', 'line' => 3, 'text' => '// needle', 'ranges' => []],
        ];
    }

    private function project(): TempProject
    {
        return new TempProject(['a.php' => "<?php\nneedle();\n\$s = 'needle';\n// needle\n"]);
    }

    public function testInCommentsStringsKeepsOnlyCommentAndStringMatches(): void
    {
        $project = $this->project();
        $kept = $this->filter($project)->apply($this->results(), 'needle', new SearchOptions(tokenScope: TokenScope::InCommentsStrings));

        $this->assertSame([2, 3], \array_column($kept, 'line'), 'only the string and comment lines survive');
    }

    public function testExceptCommentsStringsKeepsOnlyCodeMatches(): void
    {
        $project = $this->project();
        $kept = $this->filter($project)->apply($this->results(), 'needle', new SearchOptions(tokenScope: TokenScope::ExceptCommentsStrings));

        $this->assertSame([1], \array_column($kept, 'line'), 'only the code line survives');
    }

    public function testAnyScopeReturnsEverythingUnfiltered(): void
    {
        $project = $this->project();
        $results = $this->results();
        $this->assertSame($results, $this->filter($project)->apply($results, 'needle', new SearchOptions()));
    }

    public function testUnknownLanguageIsANoOp(): void
    {
        $project = new TempProject(['notes.unknownext' => "needle\n// needle\n"]);
        $results = [
            ['path' => 'notes.unknownext', 'line' => 0, 'text' => 'needle', 'ranges' => []],
            ['path' => 'notes.unknownext', 'line' => 1, 'text' => '// needle', 'ranges' => []],
        ];

        $kept = $this->filter($project)->apply($results, 'needle', new SearchOptions(tokenScope: TokenScope::InCommentsStrings));
        $this->assertCount(2, $kept, 'a file with no known language is left untouched');
    }

    public function testUnreadableFileIsANoOp(): void
    {
        $project = new TempProject([]); // the .php file never existed on disk
        $results = [['path' => 'gone.php', 'line' => 0, 'text' => 'needle', 'ranges' => []]];

        $kept = $this->filter($project)->apply($results, 'needle', new SearchOptions(tokenScope: TokenScope::InCommentsStrings));
        $this->assertCount(1, $kept, 'an unreadable file is left untouched');
    }

    /**
     * The finer question a REPLACE needs, and the reason this method exists.
     *
     * `apply()` answers per LINE — keep it or drop it — and keeps a line when
     * AT LEAST ONE occurrence falls in the requested class. So a line can be
     * kept for its string and then rewritten through its variable, which is
     * exactly what the project-wide replace did: `$foo = 'foo';` under "in
     * strings" came back as `$bar = 'bar';`.
     */
    public function testApprovedSpansNarrowToTheOccurrencesTheScopeAllows(): void
    {
        $project = new TempProject(['a.php' => "<?php\n\$foo = 'foo';\n"]);
        $filter = $this->filter($project);

        $inStrings = $filter->approvedSpans('a.php', "<?php\n\$foo = 'foo';\n", 'foo', new SearchOptions(tokenScope: TokenScope::InCommentsStrings));
        $this->assertNotNull($inStrings);
        // Line 1 is `$foo = 'foo';` — the variable at 1, the string body at 8.
        $this->assertSame([['start' => 8, 'length' => 3]], $inStrings[1] ?? [], 'only the occurrence inside the string');

        $except = $filter->approvedSpans('a.php', "<?php\n\$foo = 'foo';\n", 'foo', new SearchOptions(tokenScope: TokenScope::ExceptCommentsStrings));
        $this->assertNotNull($except);
        $this->assertSame([['start' => 1, 'length' => 3]], $except[1] ?? [], 'only the occurrence outside it');
    }

    /**
     * Null means "do not narrow", and the caller then replaces as it always
     * did — the same cases where `apply()` keeps everything, so the two stay in
     * step rather than disagreeing about what the scope meant.
     */
    public function testApprovedSpansNarrowsNothingWhenItCannotJudge(): void
    {
        $project = new TempProject(['a.php' => "<?php\n\$foo = 'foo';\n", 'notes.xyz' => "foo\n"]);
        $filter = $this->filter($project);

        $this->assertNull($filter->approvedSpans('a.php', "<?php\n\$foo = 'foo';\n", 'foo', new SearchOptions(tokenScope: TokenScope::Any)), 'no scope to apply');
        $this->assertNull($filter->approvedSpans('notes.xyz', "foo\n", 'foo', new SearchOptions(tokenScope: TokenScope::InCommentsStrings)), 'unknown language');
        $this->assertNull($filter->approvedSpans('gone.php', '', 'foo', new SearchOptions(tokenScope: TokenScope::InCommentsStrings)), 'no content: a file gone or unreadable');
    }
}
