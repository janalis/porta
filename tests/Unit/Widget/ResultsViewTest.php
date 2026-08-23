<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget;

use PHPUnit\Framework\TestCase;
use Porta\Widget\ResultsView;
use Porta\Widget\Support\ResultGrouping;
use Porta\Widget\Support\ResultLayout;

final class ResultsViewTest extends TestCase
{
    /** @return array{path: string, line: int, text: string, ranges: list<array{start: int, length: int}>} */
    private function hit(string $path, int $line): array
    {
        return ['path' => $path, 'line' => $line, 'text' => 'x', 'ranges' => []];
    }

    public function testFlatModeIsOneMatchRowPerResult(): void
    {
        $rows = ResultsView::build([$this->hit('a.php', 0), $this->hit('b.php', 1)], [], grouped: ResultLayout::Flat);

        $this->assertCount(2, $rows);
        $this->assertNull($rows[0]->filePath, 'flat rows are all matches');
        $this->assertSame(0, $rows[0]->matchIndex);
        $this->assertSame(1, $rows[1]->matchIndex);
    }

    public function testGroupedModeInsertsFileHeadersWithCounts(): void
    {
        $results = [$this->hit('a.php', 0), $this->hit('a.php', 5), $this->hit('b.php', 2)];
        $rows = ResultsView::build($results, [], grouped: ResultLayout::Grouped);

        // file a (2) · match0 · match1 · file b (1) · match2
        $this->assertCount(5, $rows);
        $this->assertSame('a.php', $rows[0]->filePath);
        $this->assertSame(2, $rows[0]->count);
        $this->assertFalse($rows[0]->collapsed);
        $this->assertSame(0, $rows[1]->matchIndex);
        $this->assertSame(1, $rows[2]->matchIndex);
        $this->assertSame('b.php', $rows[3]->filePath);
        $this->assertSame(1, $rows[3]->count);
        $this->assertSame(2, $rows[4]->matchIndex);
    }

    public function testFilterNarrowsFlatResultsByTextOrPathCaseInsensitively(): void
    {
        $results = [
            ['path' => 'src/Api.php', 'line' => 0, 'text' => 'alpha', 'ranges' => []],
            ['path' => 'src/Web.php', 'line' => 1, 'text' => 'beta', 'ranges' => []],
        ];

        // text match
        $byText = ResultsView::build($results, [], grouped: ResultLayout::Flat, filter: 'alpha');
        $this->assertCount(1, $byText);
        $this->assertSame(0, $byText[0]->matchIndex);

        // path match, case-insensitive
        $byPath = ResultsView::build($results, [], grouped: ResultLayout::Flat, filter: 'web');
        $this->assertCount(1, $byPath);
        $this->assertSame(1, $byPath[0]->matchIndex);

        // empty filter keeps everything
        $this->assertCount(2, ResultsView::build($results, [], grouped: ResultLayout::Flat, filter: ''));
    }

    public function testFilterDropsEmptyGroupsAndRecountsGrouped(): void
    {
        $results = [
            ['path' => 'src/A.php', 'line' => 0, 'text' => 'keep me', 'ranges' => []],
            ['path' => 'src/A.php', 'line' => 4, 'text' => 'drop me', 'ranges' => []],
            ['path' => 'src/B.php', 'line' => 2, 'text' => 'drop me', 'ranges' => []],
        ];
        $rows = ResultsView::build($results, [], grouped: ResultLayout::Grouped, filter: 'keep');

        // Only src/A.php retains a match → header(1) + its one match; src/B.php is gone.
        $this->assertCount(2, $rows);
        $this->assertSame('src/A.php', $rows[0]->filePath);
        $this->assertSame(1, $rows[0]->count, 'count reflects the filtered matches');
        $this->assertSame(0, $rows[1]->matchIndex);
    }

    public function testNestedGroupingFiltersOutNonMatchingResults(): void
    {
        $results = [
            ['path' => 'src/A.php', 'line' => 0, 'text' => 'keep me', 'ranges' => []],
            ['path' => 'src/B.php', 'line' => 2, 'text' => 'drop me', 'ranges' => []],
        ];
        $rows = ResultsView::build($results, [], grouped: ResultLayout::Grouped, filter: 'keep', grouping: ResultGrouping::Nested);

        // Only src/ (dir header) + src/A.php (file header) + its match survive;
        // the filtered-out B.php never reaches a row.
        $this->assertCount(3, $rows);
        $filePaths = array_values(array_filter(array_map(static fn ($r) => $r->filePath, $rows)));
        $this->assertContains('src/A.php', $filePaths);
        $this->assertNotContains('src/B.php', $filePaths);
    }

    public function testFilterUsesMultibyteLowercasingNotByteWiseLowercasing(): void
    {
        // 'É' (U+00C9) only matches 'é' under mb_strtolower; strtolower() (byte-wise,
        // locale-dependent) leaves the multibyte sequence untouched.
        $results = [['path' => 'src/Ébène.php', 'line' => 0, 'text' => 'x', 'ranges' => []]];
        $rows = ResultsView::build($results, [], grouped: ResultLayout::Flat, filter: 'ébène');

        $this->assertCount(1, $rows, 'the multibyte accented filter must match via mb_strtolower');
    }

    public function testAFailingFilterDoesNotAbortLaterMatchesInGroupedMode(): void
    {
        $results = [
            ['path' => 'src/A.php', 'line' => 0, 'text' => 'drop me', 'ranges' => []],
            ['path' => 'src/B.php', 'line' => 1, 'text' => 'keep me', 'ranges' => []],
        ];
        $rows = ResultsView::build($results, [], grouped: ResultLayout::Grouped, filter: 'keep');

        // The failing first result must be skipped (continue), not abort the whole scan.
        $this->assertCount(2, $rows, 'header(1) + the one surviving match for src/B.php');
        $this->assertSame('src/B.php', $rows[0]->filePath);
    }

    public function testAFailingFilterDoesNotAbortLaterMatchesInNestedMode(): void
    {
        $results = [
            ['path' => 'src/A.php', 'line' => 0, 'text' => 'drop me', 'ranges' => []],
            ['path' => 'src/B.php', 'line' => 1, 'text' => 'keep me', 'ranges' => []],
        ];
        $rows = ResultsView::build($results, [], grouped: ResultLayout::Grouped, filter: 'keep', grouping: ResultGrouping::Nested);

        $filePaths = array_values(array_filter(array_map(static fn ($r) => $r->filePath, $rows)));
        $this->assertContains('src/B.php', $filePaths, 'the later matching result must still be reached');
    }

    public function testNestedDirectoryHeaderReflectsItsOwnCollapsedState(): void
    {
        $results = [$this->hit('src/A.php', 0)];
        $rows = ResultsView::build($results, ['src' => true], grouped: ResultLayout::Grouped, grouping: ResultGrouping::Nested);

        $this->assertTrue($rows[0]->collapsed, 'a collapsed directory must render Collapsed, not Expanded');
    }

    public function testNestedFileHeaderReflectsItsOwnCollapsedState(): void
    {
        $results = [$this->hit('src/A.php', 0)];
        $rows = ResultsView::build($results, ['src/A.php' => true], grouped: ResultLayout::Grouped, grouping: ResultGrouping::Nested);

        // rows: dir header (not collapsed) · file header (collapsed)
        $this->assertFalse($rows[0]->collapsed);
        $this->assertTrue($rows[1]->collapsed, 'a collapsed file must render Collapsed, not Expanded');
    }

    public function testNestedCollapsedFileDoesNotAbortLaterFilesInTheSameDirectory(): void
    {
        $results = [$this->hit('src/A.php', 0), $this->hit('src/B.php', 1)];
        $rows = ResultsView::build($results, ['src/A.php' => true], grouped: ResultLayout::Grouped, grouping: ResultGrouping::Nested);

        // dir header · A.php header (collapsed, no match row) · B.php header · its match
        $filePaths = array_values(array_filter(array_map(static fn ($r) => $r->filePath, $rows)));
        $this->assertContains('src/B.php', $filePaths, 'a collapsed sibling file must not stop the scan of later files');
        $this->assertSame(1, $rows[\count($rows) - 1]->matchIndex, 'B.php\'s match row was appended');
    }

    public function testFilterMatchesOnMultibyteTextIndependentlyOfPath(): void
    {
        $results = [['path' => 'src/A.php', 'line' => 0, 'text' => 'ÉCHO', 'ranges' => []]];
        $rows = ResultsView::build($results, [], grouped: ResultLayout::Flat, filter: 'écho');

        $this->assertCount(1, $rows, 'the multibyte text match must use mb_strtolower, not the path\'s');
    }

    public function testCollapsedFileHidesItsMatchesButKeepsTheHeader(): void
    {
        $results = [$this->hit('a.php', 0), $this->hit('a.php', 5), $this->hit('b.php', 2)];
        $rows = ResultsView::build($results, ['a.php' => true], grouped: ResultLayout::Grouped);

        // file a (collapsed) · file b · match2
        $this->assertCount(3, $rows);
        $this->assertSame('a.php', $rows[0]->filePath);
        $this->assertTrue($rows[0]->collapsed);
        $this->assertSame(2, $rows[0]->count, 'the count survives collapse');
        $this->assertSame('b.php', $rows[1]->filePath);
        $this->assertSame(2, $rows[2]->matchIndex);
    }
}
