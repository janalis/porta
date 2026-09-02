<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\TestCase;
use Porta\Editor\BufferManager;
use Porta\Editor\JumpHistory;
use Porta\Editor\LineTextReader;
use Porta\Editor\RecentLocationRows;
use Porta\Support\ProjectPaths;
use Porta\Tests\Support\TempProject;

final class RecentLocationsTest extends TestCase
{
    public function testEntriesReadNewestFirstWhileNavigateBackStillPopsTheNewest(): void
    {
        $history = new JumpHistory();
        $history->push('/p/a.php', 1, 0);
        $history->push('/p/b.php', 2, 0);

        $this->assertSame(
            [['path' => '/p/b.php', 'line' => 2, 'col' => 0], ['path' => '/p/a.php', 'line' => 1, 'col' => 0]],
            $history->entries(),
            'the list reads newest first',
        );
        $this->assertSame(['path' => '/p/b.php', 'line' => 2, 'col' => 0], $history->pop(), 'and the stack still pops the newest');
        $this->assertNull(new JumpHistory()->pop(), 'an empty history pops nothing');
    }

    public function testTheStackIsCappedByDroppingTheOldest(): void
    {
        $history = new JumpHistory();
        for ($i = 0; $i < 55; ++$i) {
            $history->push('/p/a.php', $i, 0);
        }

        $entries = $history->entries();
        $this->assertCount(50, $entries);
        $this->assertSame(54, $entries[0]['line'], 'newest kept');
        $this->assertSame(5, $entries[49]['line'], 'the five oldest fell off the bottom');
    }

    public function testRowsShowEachPlacesTextNewestFirstAndAreNotReSorted(): void
    {
        $buffers = new BufferManager();
        // Indented on purpose: the row text is trimmed, like every other list tool's.
        $buffers->open('/p/src/A.php', "<?php\n        first place;\n");
        $buffers->open('/p/src/B.php', "<?php\n    second place;\n");
        $history = new JumpHistory();
        $history->push('/p/src/B.php', 1, 0);
        $history->push('/p/src/A.php', 1, 0);

        $rows = new RecentLocationRows($history, new LineTextReader($buffers), new ProjectPaths('/p'))->build();

        $this->assertSame(
            [
                ['path' => 'src/A.php', 'line' => 1, 'text' => 'first place;'],
                ['path' => 'src/B.php', 'line' => 1, 'text' => 'second place;'],
            ],
            $rows,
            'recency order, not path order — that is the whole point of the list',
        );
    }

    public function testTheSamePlaceTwiceRunningCollapsesToOneRow(): void
    {
        // Go-to-definition twice on one line records it twice; a second row
        // saying the same thing tells you nothing the first did not.
        $history = new JumpHistory();
        // The tail entry is what proves the duplicate is SKIPPED rather than
        // ending the list: with a break there, line 5 would never be reached.
        $history->push('/p/a.php', 5, 0);
        $history->push('/p/a.php', 3, 0);
        $history->push('/p/a.php', 3, 9); // same line, different column
        $history->push('/p/a.php', 7, 0);

        $rows = new RecentLocationRows($history, new LineTextReader(new BufferManager()), new ProjectPaths('/p'))->build();

        $this->assertSame(
            [['line' => 7], ['line' => 3], ['line' => 5]],
            array_map(static fn (array $r): array => ['line' => $r['line']], $rows),
            'the repeat collapses, and everything older than it still shows',
        );
    }

    public function testAClosedFilesLineIsStillReadFromDisk(): void
    {
        $project = new TempProject();
        $project->write('src/A.php', "<?php\nleft from here;\n");
        $history = new JumpHistory();
        $history->push($project->path('src/A.php'), 1, 0);

        $rows = new RecentLocationRows($history, new LineTextReader(new BufferManager()), new ProjectPaths($project->dir))->build();

        $this->assertSame([['path' => 'src/A.php', 'line' => 1, 'text' => 'left from here;']], $rows);
    }

    public function testAnEmptyHistoryYieldsNoRows(): void
    {
        $this->assertSame([], new RecentLocationRows(new JumpHistory(), new LineTextReader(new BufferManager()), new ProjectPaths('/p'))->build());
    }
}
