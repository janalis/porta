<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Search;

use PHPUnit\Framework\TestCase;
use Porta\Editor\BufferManager;
use Porta\Filesystem\ProjectLocation;
use Porta\Filesystem\RemoteFilesystem;
use Porta\Filesystem\RemoteSpec;
use Porta\Filesystem\RemoteTransport;
use Porta\Filesystem\RemoteTransportSource;
use Porta\I18n\Translator;
use Porta\Search\FileIndex;
use Porta\Search\ProjectSearch;
use Porta\Search\SearchOptions;
use Porta\Tests\Support\FakeRemoteTransport;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\PumpsLoop;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\TestTui;
use Porta\Widget\StatusBarWidget;
use Porta\Workbench\Notifier;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class ProjectSearchTest extends TestCase
{
    use PreservesPath;
    use PumpsLoop;

    /** @return list<array{path: string, line: int, text: string, ranges: list<array{start: int, length: int}>}> */
    private function awaitSearch(
        ProjectSearch $search,
        string $query,
        SearchOptions $options = new SearchOptions(),
        float $timeout = 3.0,
        string|array $scope = '.',
        string $mask = '',
    ): array {
        $results = null;
        $search->search(
            $query,
            static function (array $r) use (&$results): void {
                $results = $r;
            },
            $options,
            $scope,
            $mask,
        );

        $this->await(
            static function () use (&$results): bool {
                return null !== $results;
            },
            $timeout,
            'the search to complete',
        );
        \assert(null !== $results, description: 'the search delivered results');

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

    /**
     * A remote project's cache holds only what was opened; its grep runs on
     * the server, so a file nobody fetched is found — with the same paths a
     * local search answers.
     */
    public function testARemoteProjectIsSearchedOnTheServer(): void
    {
        $server = new TempProject(['src/Deep.php' => "<?php\n// needle here\n", 'vendor/x.php' => 'needle']);
        $cache = new TempProject(['src/Deep.php' => '']);
        $transport = new FakeRemoteTransport($server->dir);
        $remote = $transport->filesystem($cache->dir);

        $results = $this->awaitSearch(new ProjectSearch($cache->dir, $remote), 'needle');

        $this->assertSame(
            [['path' => 'src/Deep.php', 'line' => 1]],
            \array_map(static fn (array $r): array => ['path' => $r['path'], 'line' => $r['line']], $results),
            'vendor/ is left out there as here',
        );
        $this->assertSame(['run grep'], $transport->calls);
        $this->assertNull(
            new RemoteFilesystem(
                new ProjectLocation(),
                $cache->dir,
                new readonly class implements RemoteTransportSource {
                    public function remoteTransport(RemoteSpec $spec): ?RemoteTransport
                    {
                        return null;
                    }
                },
                new Notifier(new StatusBarWidget(), TestTui::create()),
                new Translator('en'),
                new BufferManager(),
                new FileIndex($cache->dir),
                new EventDispatcher(),
            )->remoteCommand(['grep']),
            'a local project runs its commands here',
        );
    }

    public function testGrepSearchFindsMatchesAndExcludesVendor(): void
    {
        $project = $this->fixtures();
        $results = $this->awaitSearch(new ProjectSearch($project->dir), 'needle');

        $paths = \array_column($results, 'path');
        \sort($paths);
        $this->assertSame(['src/A.php', 'src/B.php'], $paths);
        $this->assertSame(1, $results[0]['line'], '0-based line index');
    }

    /** @return list<string> the sorted result paths */
    private function scopedPaths(SearchBackend $backend): array
    {
        $project = new TempProject([
            'src/A.php' => "<?php\n// needle in src\n",
            'src/sub/B.php' => "<?php\n// needle deeper in src\n",
            'app/D.php' => "<?php\n// needle in app\n",
            'README.md' => "needle at the root\n",
        ]);
        if (SearchBackend::PhpFallback === $backend) {
            \putenv('PATH=/nonexistent');
        }

        $paths = \array_column($this->awaitSearch(new ProjectSearch($project->dir), 'needle', scope: 'src'), 'path');
        \sort($paths);

        return $paths;
    }

    public function testGrepSearchScopedToAFolder(): void
    {
        // Only the src/ subtree is searched; app/ and the root README are excluded,
        // and the returned paths stay project-root-relative.
        $this->assertSame(['src/A.php', 'src/sub/B.php'], $this->scopedPaths(SearchBackend::Grep));
    }

    public function testPhpFallbackScopedToAFolder(): void
    {
        // The Finder-based fallback prefixes the scope back on so paths match grep.
        $this->assertSame(['src/A.php', 'src/sub/B.php'], $this->scopedPaths(SearchBackend::PhpFallback));
    }

    public function testMatchOffsetsReturnsByteOffsetsOfEachHit(): void
    {
        $tempProject = new TempProject();
        $search = new ProjectSearch($tempProject->dir);

        $this->assertSame([0, 12], $search->matchOffsets('needle here needle', 'needle', new SearchOptions()));
        $this->assertSame([], $search->matchOffsets('', 'needle', new SearchOptions()), 'an empty line has no offsets');
        // An invalid user regex makes preg_match_all fail → no offsets (no crash).
        $this->assertSame([], $search->matchOffsets('needle', '(', new SearchOptions(regex: true)), 'a malformed regex yields no offsets');
        // A pattern that also matches empty (`a*`) skips its zero-width hits and
        // keeps only the real one (the leading 'a' at offset 0).
        $this->assertSame([0], $search->matchOffsets('abc', 'a*', new SearchOptions(regex: true)), 'zero-width matches are skipped');
    }

    public function testMultilineScanSkipsFilesThatBlowThePcreBacktrackLimit(): void
    {
        // A catastrophic-backtracking regex that is valid against '' but exceeds
        // the PCRE backtrack limit on the file's long run: preg_match_all returns
        // false for that file, which the whole-file scan skips (no results).
        $project = new TempProject(['src/A.php' => \str_repeat('a', times: 30)."!\nz\n"]);
        $search = new ProjectSearch($project->dir);
        $results = $this->awaitSearch($search, "(a+)+\nz", options: new SearchOptions(regex: true));

        $this->assertSame([], $results);
        // And it SAYS so. This used to assert the empty list alone, which is
        // the same answer PCRE gives for "nothing here" — a project search
        // quietly returning fewer results is worse than the find bar doing it,
        // because a handful of skipped files among thousands is invisible.
        $this->assertTrue($search->gaveUp(), 'the engine gave up and the search admits it');

        // Skipped, that file — not the rest of the scan: a file after it still answers.
        $project->write('src/B.php', "a\nz\n");
        $after = $this->awaitSearch($search, "(a+)+\nz", options: new SearchOptions(regex: true), scope: ['src/A.php', 'src/B.php']);
        $this->assertSame(['src/B.php'], \array_column($after, 'path'));
    }

    /** The PHP walk passes over a directory nobody may list, as grep does, and finds the rest. */
    public function testThePhpWalkPassesOverAnUnreadableDirectory(): void
    {
        \putenv('PATH=/nonexistent');
        $project = $this->fixtures();
        if (!$project->lock('docker/db')) {
            $this->markTestSkipped('this user reads every directory');
        }

        $paths = \array_column($this->awaitSearch(new ProjectSearch($project->dir), 'needle'), 'path');
        \sort($paths);

        $this->assertSame(['src/A.php', 'src/B.php'], $paths);
    }

    /**
     * grep lists only the lines the pattern that highlights and replaces them
     * matches: in a UTF-8 locale it folded `É` to `é` under -i, and replace
     * skipped the line it had listed. Both read bytes now.
     */
    public function testGrepFoldsCaseAsThePatternDoes(): void
    {
        $locale = \getenv('LC_ALL');
        \putenv('LC_ALL=en_US.UTF-8');
        try {
            $project = new TempProject(['a.txt' => "café\n", 'b.txt' => "CAFE\n"]);
            $results = $this->awaitSearch(new ProjectSearch($project->dir), 'cafÉ', new SearchOptions(caseSensitive: false));
        } finally {
            \putenv(false === $locale ? 'LC_ALL' : 'LC_ALL='.$locale);
        }

        $this->assertSame([], $results, 'É is not é to the highlighter, so it is not to grep');
        $this->assertSame(
            ['b.txt'],
            \array_column($this->awaitSearch(new ProjectSearch($project->dir), 'cafe', new SearchOptions(caseSensitive: false)), 'path'),
            'the premise: an ASCII fold still finds',
        );
    }

    /**
     * Every file the walk passes over — off the mask, past the size cap,
     * binary, or seen in an earlier scope — still comes through, as null: a
     * slice pauses on it. Turned away inside Finder, a tree of them passed in
     * one step, past every deadline.
     */
    public function testThePhpWalkPausesOnEveryFileItPassesOver(): void
    {
        $project = new TempProject([
            'src/A.php' => "<?php\n// needle\n",
            'src/page.html' => 'needle',
            'src/big.php' => \str_repeat('x', times: 1_048_577),
            'src/blob.php' => "needle\0",
        ]);

        $walked = [];
        foreach (Probe::invoke(new ProjectSearch($project->dir), 'texts', ['src', 'src/A.php'], '*.php') as $rel => $content) {
            $walked[] = [$rel, null !== $content];
        }
        \sort($walked);

        $this->assertSame(
            [
                ['src/A.php',     false],
                ['src/A.php',     true],
                ['src/big.php',   false],
                ['src/blob.php',  false],
                ['src/page.html', false],
            ],
            $walked,
        );
    }

    /** The PHP walk shows a hit as grep's results are shown: without its indentation. */
    public function testThePhpWalkShowsAHitTrimmed(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\n    \$needle = 1;  \n"]);

        $results = $this->awaitSearch(new ProjectSearch($project->dir), 'needle');

        $this->assertSame(['$needle = 1;'], \array_column($results, 'text'));
    }

    /**
     * An ordinary search does not claim the engine gave up — including one that
     * FOLLOWS a search that did.
     *
     * One controller holds one ProjectSearch for the session, so the reuse case
     * is the only one that happens. A flag set on a bad pattern and never
     * cleared would warn about every search afterwards, which is the same lie
     * in the other direction.
     */
    public function testTheGiveUpFlagDoesNotOutliveTheSearchThatSetIt(): void
    {
        // The a-run followed by `z` is what drives the pattern into the
        // expensive scan; `needle` is there for the ordinary search after it.
        $project = new TempProject(['src/A.php' => \str_repeat('a', times: 30)."!\nz\nneedle\n"]);
        $search = new ProjectSearch($project->dir);

        $this->awaitSearch($search, "(a+)+\nz", options: new SearchOptions(regex: true));
        $this->assertTrue($search->gaveUp(), 'the first search gave up');

        $this->awaitSearch($search, 'needle');
        $this->assertFalse($search->gaveUp(), 'and the next one does not inherit it');
    }

    public function testMultilineQueryMatchesAcrossLinesWithAPreview(): void
    {
        $project = new TempProject([
            'src/A.php' => "<?php\nfunction foo()\n{\n    return 1;\n}\n",
            'src/B.php' => "<?php\nfunction foo() { return 2; }\n", // one line → no cross-line match
        ]);
        $results = $this->awaitSearch(new ProjectSearch($project->dir), "foo()\n{");

        $this->assertCount(1, $results, 'only the file whose foo() spans lines matches');
        $this->assertSame('src/A.php', $results[0]['path']);
        $this->assertSame(1, $results[0]['line'], '0-based start line of the match');
        $this->assertSame('foo() ⏎…', $results[0]['text'], 'the preview shows the first line + a continuation marker');
        $this->assertSame([['start' => 0, 'length' => 5]], $results[0]['ranges'], 'the first-line portion is highlighted');
    }

    public function testMultilineScanDeduplicatesSkipsBinaryAndCaps(): void
    {
        $project = new TempProject([
            'src/A.php' => "x\nfoo\nbar\ny\n",
            'src/bin.dat' => "foo\nbar\n\0\n", // contains a NUL → skipped as binary
        ]);
        // Overlapping scopes (the file and its directory) must still match A.php once.
        $results = $this->awaitSearch(new ProjectSearch($project->dir), "foo\nbar", scope: ['src/A.php', 'src']);
        $paths = \array_column($results, 'path');
        $this->assertSame(['src/A.php'], $paths, 'deduplicated across overlapping scopes; the binary file is skipped');

        // The result cap applies to the whole-file scan too.
        $many = new TempProject(['big.txt' => \str_repeat("A\nB\n", ProjectSearch::MAX_RESULTS + 5)]);
        $capped = $this->awaitSearch(new ProjectSearch($many->dir), "A\nB");
        $this->assertCount(ProjectSearch::MAX_RESULTS, $capped);
    }

    public function testEmptyScopeListMatchesNothing(): void
    {
        // An empty scope (e.g. Open Files with nothing open) short-circuits to no results.
        $project = new TempProject(['src/A.php' => "<?php\n// needle\n"]);
        $this->assertSame([], $this->awaitSearch(new ProjectSearch($project->dir), 'needle', scope: []));
    }

    public function testScopeListOfExplicitFilesAndDirsDeduplicates(): void
    {
        \putenv('PATH=/nonexistent'); // force the PHP walk so scopeFiles is exercised
        $project = new TempProject([
            'src/A.php' => "<?php\n// needle in A\n",
            'src/B.php' => "<?php\n// needle in B\n",
            'other/C.php' => "<?php\n// needle in C\n",
        ]);
        // 'src/A.php' (a file) overlaps 'src' (a dir); A.php is matched once, C.php stays out of scope.
        $paths = \array_column($this->awaitSearch(new ProjectSearch($project->dir), 'needle', scope: ['src/A.php', 'src']), 'path');
        \sort($paths);
        $this->assertSame(['src/A.php', 'src/B.php'], $paths);
    }

    public function testPhpFallbackWhenGrepUnavailable(): void
    {
        $project = $this->fixtures();
        // Also exercise the binary-file and oversized-file skips
        $project->write('src/bin.dat', "nee\0dle");
        $project->write('src/huge.txt', \str_repeat('needle filler ', times: 80_000)); // > 1 MB, skipped
        \putenv('PATH=/nonexistent');

        $results = $this->awaitSearch(new ProjectSearch($project->dir), 'needle');

        $paths = \array_column($results, 'path');
        \sort($paths);
        $this->assertSame(['src/A.php', 'src/B.php'], $paths);
    }

    /** @return list<array{start: int, length: int}> the ranges of the sole result */
    private function rangesOf(SearchBackend $backend, string $content, string $query, SearchOptions $options = new SearchOptions()): array
    {
        $project = new TempProject(['a.txt' => $content."\n"]);
        if (SearchBackend::PhpFallback === $backend) {
            \putenv('PATH=/nonexistent');
        }
        $results = $this->awaitSearch(new ProjectSearch($project->dir), $query, options: $options);
        $this->assertCount(1, $results);

        return $results[0]['ranges'];
    }

    public function testGrepMatchRangesAreDisplayColumns(): void
    {
        // "the needle here" → 'needle' starts at column 4, length 6.
        $this->assertSame([['start' => 4, 'length' => 6]], $this->rangesOf(SearchBackend::Grep, 'the needle here', 'needle'));
        // Regex matches map the same way.
        $this->assertSame([['start' => 4, 'length' => 6]], $this->rangesOf(SearchBackend::Grep, 'the needle here', 'ne+dle', options: new SearchOptions(regex: true)));
        // A multibyte prefix ("café " is 6 bytes but 5 display columns) → start 5.
        $this->assertSame([['start' => 5, 'length' => 6]], $this->rangesOf(SearchBackend::Grep, 'café needle x', 'needle'));
        // The same word written e + U+0301 is still five columns as the panel
        // draws it; summing code points put the highlight one column late.
        $this->assertSame([['start' => 5, 'length' => 6]], $this->rangesOf(SearchBackend::Grep, "cafe\u{0301} needle x", 'needle'));
    }

    public function testPhpFallbackMatchRangesMatchGrep(): void
    {
        $this->assertSame([['start' => 4, 'length' => 6]], $this->rangesOf(SearchBackend::PhpFallback, 'the needle here', 'needle'));
        $this->assertSame([['start' => 5, 'length' => 6]], $this->rangesOf(SearchBackend::PhpFallback, 'café needle x', 'needle'));
        $this->assertSame([['start' => 5, 'length' => 6]], $this->rangesOf(SearchBackend::PhpFallback, "cafe\u{0301} needle x", 'needle'));
    }

    /** @return list<string> sorted result paths for a mask over a fixed fixture */
    private function maskedPaths(SearchBackend $backend, string $mask): array
    {
        $project = new TempProject([
            'src/A.php' => "<?php\n// needle\n",
            'src/B.js' => "// needle\n",
            'src/C.twig' => "{# needle #}\n",
        ]);
        if (SearchBackend::PhpFallback === $backend) {
            \putenv('PATH=/nonexistent');
        }
        $paths = \array_column($this->awaitSearch(new ProjectSearch($project->dir), 'needle', mask: $mask), 'path');
        \sort($paths);

        return $paths;
    }

    public function testFileMaskRestrictsMatchedFilesWithGrep(): void
    {
        $this->assertSame(['src/A.php'], $this->maskedPaths(SearchBackend::Grep, '*.php'));
        $this->assertSame(['src/A.php', 'src/B.js'], $this->maskedPaths(SearchBackend::Grep, '*.{php,js}'), 'brace mask');
        $this->assertSame(['src/A.php', 'src/C.twig'], $this->maskedPaths(SearchBackend::Grep, '*.php, *.twig'), 'comma mask');
    }

    public function testFileMaskRestrictsMatchedFilesWithPhpFallback(): void
    {
        $this->assertSame(['src/A.php'], $this->maskedPaths(SearchBackend::PhpFallback, '*.php'));
        $this->assertSame(['src/A.php', 'src/B.js'], $this->maskedPaths(SearchBackend::PhpFallback, '*.{php,js}'), 'brace mask via Finder');
    }

    /**
     * A scope entry that names one FILE rather than a directory, under a mask.
     *
     * @return list<string> the result paths
     */
    private function fileScopePaths(SearchBackend $backend, string $scope, string $mask): array
    {
        $project = new TempProject([
            'src/A.php' => "<?php\n// needle\n",
            'src/B.js' => "// needle\n",
        ]);
        if (SearchBackend::PhpFallback === $backend) {
            \putenv('PATH=/nonexistent');
        }

        return \array_column($this->awaitSearch(new ProjectSearch($project->dir), 'needle', scope: $scope, mask: $mask), 'path');
    }

    /**
     * The two engines used to disagree here: grep's `--include` filters every
     * candidate including one named on the command line, while the fallback
     * yielded a named file whatever the mask said. Same fixture, same scope,
     * same mask, both engines — asserted together so the next divergence fails
     * rather than being discovered by a user whose PATH has no grep on it.
     */
    public function testAMaskNarrowsAnExplicitFileScopeInBothEngines(): void
    {
        foreach ([SearchBackend::Grep, SearchBackend::PhpFallback] as $backend) {
            $name = $backend->name;
            $this->assertSame(['src/A.php'], $this->fileScopePaths($backend, 'src/A.php', '*.php'), $name.': the mask admits the named file');
            $this->assertSame([], $this->fileScopePaths($backend, 'src/B.js', '*.php'), $name.': the mask excludes the named file');
            $this->assertSame(['src/B.js'], $this->fileScopePaths($backend, 'src/B.js', ''), $name.': no mask, so the named file is searched');
            $this->assertSame(['src/B.js'], $this->fileScopePaths($backend, 'src/B.js', '*.{php,js}'), $name.': a later glob in the mask admits it');
        }
    }

    public function testEmptyQueryReturnsNothing(): void
    {
        $project = $this->fixtures();
        $results = $this->awaitSearch(new ProjectSearch($project->dir), '   ');

        $this->assertSame([], $results);
    }

    public function testRegexAndOptionsWithGrep(): void
    {
        $project = new TempProject([
            'src/A.php' => "<?php\n\$needle = 1;\n\$NEEDLES = 2;\nneedless();\n",
        ]);
        $search = new ProjectSearch($project->dir);

        // Regex.
        $this->assertNotSame([], $this->awaitSearch($search, 'ne+dle', options: new SearchOptions(regex: true)));
        // Case-sensitive: NEEDLES does not match lowercase "needle".
        $lines = \array_column($this->awaitSearch($search, 'needle', options: new SearchOptions(caseSensitive: true)), 'line');
        $this->assertNotContains(2, $lines, 'case-sensitive skips $NEEDLES');
        // Whole-word: "needle" must not match inside "needless".
        $wordLines = \array_column($this->awaitSearch($search, 'needle', options: new SearchOptions(wholeWord: true)), 'line');
        $this->assertNotContains(3, $wordLines, 'whole-word skips needless()');
        $this->assertContains(1, $wordLines);
    }

    public function testRegexAndOptionsWithPhpFallback(): void
    {
        $project = new TempProject([
            'src/A.php' => "<?php\n\$needle = 1;\n\$NEEDLES = 2;\nneedless();\n",
        ]);
        \putenv('PATH=/nonexistent');
        $search = new ProjectSearch($project->dir);

        $this->assertNotSame([], $this->awaitSearch($search, 'ne+dle', options: new SearchOptions(regex: true)));
        $this->assertNotContains(2, \array_column($this->awaitSearch($search, 'needle', options: new SearchOptions(caseSensitive: true)), 'line'));
        $wordLines = \array_column($this->awaitSearch($search, 'needle', options: new SearchOptions(wholeWord: true)), 'line');
        $this->assertNotContains(3, $wordLines);
        $this->assertContains(1, $wordLines);
    }

    public function testInvalidRegexYieldsNoResults(): void
    {
        $project = $this->fixtures();
        // Unbalanced group is not a valid pattern.
        $this->assertSame([], $this->awaitSearch(new ProjectSearch($project->dir), 'needle(', options: new SearchOptions(regex: true)));
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

    public function testApplyReplacementPreservesCase(): void
    {
        $search = new ProjectSearch('/tmp');
        $preserve = new SearchOptions(preserveCase: true);

        // Each occurrence's casing is mapped onto the replacement, overriding the
        // typed casing: lowercase→lower, Title→Title, UPPER→UPPER.
        $this->assertSame(
            'newname Newname NEWNAME',
            $search->applyReplacement('oldname Oldname OLDNAME', 'oldname', 'newName', $preserve),
        );
        // A mixed-case match keeps the replacement verbatim.
        $this->assertSame('newName', $search->applyReplacement('oLdNaMe', 'oldname', 'newName', $preserve));
        // An empty replacement deletes the match (nothing to recase).
        $this->assertSame('', $search->applyReplacement('oldname', 'oldname', '', $preserve));
        // Multibyte title-case (case-sensitive match; PCRE `i` is not UTF-8-aware here).
        $this->assertSame('Éclair', $search->applyReplacement('Ötür', 'Ötür', 'éclair', new SearchOptions(preserveCase: true, caseSensitive: true)));
        // Preserve-case is inert in regex mode (falls back to the plain replacement).
        $this->assertSame('newName', $search->applyReplacement('OLDNAME', 'oldname', 'newName', new SearchOptions(regex: true, preserveCase: true)));
    }

    public function testResultCapWithGrep(): void
    {
        $project = new TempProject([
            'big.txt' => \str_repeat("needle\n", ProjectSearch::MAX_RESULTS + 200),
        ]);

        $results = $this->awaitSearch(new ProjectSearch($project->dir), 'needle', timeout: 5.0);

        $this->assertCount(ProjectSearch::MAX_RESULTS, $results);
    }

    public function testResultCapWithPhpFallback(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject([
            'big.txt' => \str_repeat("needle\n", ProjectSearch::MAX_RESULTS + 200),
        ]);

        $results = $this->awaitSearch(new ProjectSearch($project->dir), 'needle', timeout: 5.0);

        $this->assertCount(ProjectSearch::MAX_RESULTS, $results);
    }

    /**
     * A grep on PATH that cannot run answers like no grep at all: the built-in
     * search. macOS spawns the dead binary and learns from its exit code; Linux
     * refuses the spawn outright. Both used to differ, and macOS showed nothing.
     */
    public function testABrokenGrepBinaryFallsBackToTheBuiltInSearch(): void
    {
        $project = $this->fixtures();
        $noGrep = new TempProject([], 'porta-nogrep-');
        \putenv('PATH='.$noGrep->dir);
        $builtIn = $this->awaitSearch(new ProjectSearch($project->dir), 'needle');
        $this->assertNotSame([], $builtIn, 'the premise: the built-in search finds the needle');

        $tools = new TempProject([], 'porta-badgrep-');
        $badGrep = $tools->write('grep', "\x00\x01 not an executable format");
        \chmod($badGrep, permissions: 0o755);
        \putenv('PATH='.$tools->dir);

        $this->assertSame($builtIn, $this->awaitSearch(new ProjectSearch($project->dir), 'needle'));
        $regex = new SearchOptions(regex: true);
        $this->assertSame($builtIn, $this->awaitSearch(new ProjectSearch($project->dir), 'need+le', $regex), 'nor does asking it whether it reads PCRE');
    }

    /**
     * grep picks the lines, so it reads a regex as the find bar does: PCRE
     * (`-P`) where this grep takes it — GNU's ERE reads `\d` as a `d` — asked
     * once; ERE where it does not, and on a server, whose grep nobody asked.
     */
    public function testARegexIsReadAsPcreWhereGrepCanReadIt(): void
    {
        $project = $this->fixtures();
        $tools = new TempProject([], 'porta-pcregrep-');
        $log = $tools->path('argv.log');
        $grep = static function (int $probe) use ($tools, $log): void {
            // The probe answers $probe; a search says what it was asked, and finds nothing.
            $tools->write('grep', "#!/bin/sh\nif [ \"\$1\" = -qP ]; then echo probe >> '{$log}'; exit {$probe}; fi\nprintf '%s\\n' \"\$*\" >> '{$log}'\nexit 1\n");
            \chmod($tools->path('grep'), permissions: 0o755);
            \file_put_contents($log, data: '');
        };
        $asked = static fn (): array => \array_values(\array_filter(\explode("\n", (string) \file_get_contents($log))));
        $tail = static fn (string $line): string => \substr($line, (int) \strrpos($line, needle: ' -- ') - 2);
        $regex = new SearchOptions(regex: true, caseSensitive: true);
        \putenv('PATH='.$tools->dir.':/bin'); // sh for the fake server's command; grep is the stub

        $grep(1);
        $search = new ProjectSearch($project->dir);
        $this->awaitSearch($search, '\d+', $regex);
        $this->awaitSearch($search, '\d+', $regex);
        $this->assertSame(['probe', '-P -- \d+ .', '-P -- \d+ .'], [$asked()[0] ?? null, ...\array_map($tail, \array_slice($asked(), offset: 1))], 'PCRE, and grep asked once');

        $grep(1);
        $this->awaitSearch(new ProjectSearch($project->dir), 'needle');
        $this->assertSame(['-F -- needle .'], \array_map($tail, $asked()), 'a literal asks nothing');

        $grep(2);
        $this->awaitSearch(new ProjectSearch($project->dir), '\d+', $regex);
        $this->assertSame(['probe', '-E -- \d+ .'], [$asked()[0] ?? null, ...\array_map($tail, \array_slice($asked(), offset: 1))], 'no -P: ERE');

        $grep(1);
        $cache = new TempProject([]);
        $this->awaitSearch(new ProjectSearch($cache->dir, new FakeRemoteTransport($project->dir)->filesystem($cache->dir)), '\d+', $regex);
        $this->assertSame(['-E -- \d+ .'], \array_map($tail, $asked()), "the server's grep is not this one");
    }

    /**
     * The PHP walk holds the loop a slice at a time — a large project used to
     * freeze the UI for the whole scan (12 s over this repository) — and a
     * new search or a cancel drops the rest of the old one.
     */
    public function testThePhpWalkAnswersBetweenFramesAndStopsWhenCancelled(): void
    {
        $scan = static function (): \Generator {
            for ($file = 0; $file < 10; ++$file) {
                \usleep(3000); // a file that takes 3 ms: ten of them outlast a slice
                yield;
            }

            return [['path' => 'a.php', 'line' => 0, 'text' => 'done', 'ranges' => []]];
        };
        $search = new ProjectSearch('/tmp');
        $results = null;
        $onDone = static function (array $r) use (&$results): void {
            $results = $r;
        };

        Probe::invoke($search, 'sliced', $scan(), $onDone);
        $this->assertNull($results, 'the scan yields the loop before it is through');
        $this->await(static function () use (&$results): bool {
            return null !== $results;
        }, what: 'the rest of the scan, on later turns');
        $this->assertSame('done', $results[0]['text'] ?? null);

        $results = null;
        Probe::invoke($search, 'sliced', $scan(), $onDone);
        $search->cancel();
        $this->pump(0.1);
        $this->assertNull($results, 'cancelled: the rest never runs');
    }

    public function testParseSkipsMalformedGrepLines(): void
    {
        $search = new ProjectSearch('/tmp');
        $results = Probe::invoke($search, 'parse', "src/A.php:3:a match here\ngarbage without line number\n\n./src/B.php:7:no hit\n", '/match/');

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
        // An empty (whitespace-only, trimmed) line yields no ranges.
        $this->assertSame([], Probe::invoke($search, 'rangesFor', '', '/x/'));
        // A pattern that can match empty (`l*`) skips its zero-width matches but
        // keeps the real one ("ll" at column 2).
        $this->assertSame([['start' => 2, 'length' => 2]], Probe::invoke($search, 'rangesFor', 'hello', '/l*/'));
        // A malformed pattern makes preg_match_all fail → no ranges.
        $this->assertSame([], Probe::invoke($search, 'rangesFor', 'hello', '/(/'));
    }

    public function testCancelStopsInFlightSearch(): void
    {
        $project = $this->fixtures();
        $search = new ProjectSearch($project->dir);

        $called = false;
        $search->search('needle', static function () use (&$called): void {
            $called = true;
        });
        $search->cancel();

        // Give the (cancelled) watcher time to have fired if cancel failed
        $this->pump(0.3);

        $this->assertFalse($called);
    }

    public function testNewSearchCancelsPrevious(): void
    {
        $project = $this->fixtures();
        $search = new ProjectSearch($project->dir);

        $firstCompleted = false;
        $search->search('needle', static function () use (&$firstCompleted): void {
            $firstCompleted = true;
        });
        $results = $this->awaitSearch($search, 'needle');

        $this->assertFalse($firstCompleted, 'first search superseded');
        $this->assertNotEmpty($results);
    }
}

/** Which search backend a helper exercises: the grep path or the PHP Finder fallback. */
// @mago-expect lint:single-class-per-file -- test-local helper enum, kept beside the test that uses it
enum SearchBackend
{
    case Grep;
    case PhpFallback;
}
