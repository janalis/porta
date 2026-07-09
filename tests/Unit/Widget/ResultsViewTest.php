<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget;

use PHPUnit\Framework\TestCase;
use Porta\Widget\ResultsView;

final class ResultsViewTest extends TestCase
{
    /** @return array{path: string, line: int, text: string, ranges: list<array{start: int, length: int}>} */
    private function hit(string $path, int $line): array
    {
        return ['path' => $path, 'line' => $line, 'text' => 'x', 'ranges' => []];
    }

    public function testFlatModeIsOneMatchRowPerResult(): void
    {
        $rows = ResultsView::build([$this->hit('a.php', 0), $this->hit('b.php', 1)], [], grouped: false);

        $this->assertCount(2, $rows);
        $this->assertNull($rows[0]->filePath, 'flat rows are all matches');
        $this->assertSame(0, $rows[0]->matchIndex);
        $this->assertSame(1, $rows[1]->matchIndex);
    }

    public function testGroupedModeInsertsFileHeadersWithCounts(): void
    {
        $results = [$this->hit('a.php', 0), $this->hit('a.php', 5), $this->hit('b.php', 2)];
        $rows = ResultsView::build($results, [], grouped: true);

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

    public function testCollapsedFileHidesItsMatchesButKeepsTheHeader(): void
    {
        $results = [$this->hit('a.php', 0), $this->hit('a.php', 5), $this->hit('b.php', 2)];
        $rows = ResultsView::build($results, ['a.php' => true], grouped: true);

        // file a (collapsed) · file b · match2
        $this->assertCount(3, $rows);
        $this->assertSame('a.php', $rows[0]->filePath);
        $this->assertTrue($rows[0]->collapsed);
        $this->assertSame(2, $rows[0]->count, 'the count survives collapse');
        $this->assertSame('b.php', $rows[1]->filePath);
        $this->assertSame(2, $rows[2]->matchIndex);
    }
}
