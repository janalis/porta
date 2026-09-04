<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\History;

use PHPUnit\Framework\TestCase;
use Porta\History\RecentFilesStore;
use Porta\Tests\Support\TempProject;

/**
 * The list that outlives the tab: what the Ctrl+E switcher and Search
 * Everywhere's empty state both read for the files that are no longer open.
 */
final class RecentFilesStoreTest extends TestCase
{
    public function testRecordKeepsMostRecentFirstAndDeduplicates(): void
    {
        $project = new TempProject();
        $store = new RecentFilesStore($project->dir);
        $store->record('/a.php');
        $store->record('/b.php');
        $store->record('/a.php'); // revisited: back to the front, not twice

        $this->assertSame(['/a.php', '/b.php'], $store->all());
    }

    public function testRESHOWINGTheSameFileIsNotAVisit(): void
    {
        // BufferSwitched fires on every show — config re-apply, pane swaps,
        // focus churn — so the front of the list has to be idempotent, or the
        // one file you are looking at rewrites the JSON on every repaint.
        $project = new TempProject();
        $store = new RecentFilesStore($project->dir);
        $store->record('/a.php');
        $store->record('/b.php');

        $file = $project->dir.'/.porta/recent-files.json';
        $before = filemtime($file);
        clearstatcache();
        $store->record('/b.php');

        $this->assertSame(['/b.php', '/a.php'], $store->all());
        $this->assertSame($before, filemtime($file), 'no rewrite for a re-show');
    }

    public function testEmptyPathsAreIgnored(): void
    {
        $project = new TempProject();
        $store = new RecentFilesStore($project->dir);
        $store->record('');

        $this->assertSame([], $store->all());
    }

    public function testCapsAtThirtyTwoEntries(): void
    {
        $project = new TempProject();
        $store = new RecentFilesStore($project->dir);
        for ($i = 0; $i < 40; ++$i) {
            $store->record("/f{$i}.php");
        }

        $this->assertCount(32, $store->all());
        $this->assertSame('/f39.php', $store->all()[0], 'newest first');
        $this->assertSame('/f8.php', $store->all()[31], 'oldest of the kept 32');
    }

    public function testItSurvivesTheProcess(): void
    {
        $project = new TempProject();
        $first = new RecentFilesStore($project->dir);
        $first->record('/a.php');
        $first->record('/b.php');

        $this->assertSame(['/b.php', '/a.php'], new RecentFilesStore($project->dir)->all());
    }

    public function testAMissingOrJUNKFileReadsAsAnEmptyList(): void
    {
        $project = new TempProject();
        $this->assertSame([], new RecentFilesStore($project->dir)->all(), 'nothing written yet');

        $project->write('.porta/recent-files.json', '{"paths": "nope"}');
        $this->assertSame([], new RecentFilesStore($project->dir)->all(), 'wrong shape');

        $project->write('.porta/recent-files.json', '{"paths": ["/a.php", 7, "", null, "/b.php"]}');
        $this->assertSame(['/a.php', '/b.php'], new RecentFilesStore($project->dir)->all(), 'non-strings dropped');

        // A hand-edited object rather than an array: the keys go, the string
        // values stay. Deliberately tolerant — the alternative is throwing away
        // a whole list over one malformed edit, and every path is checked
        // against the disk before it is offered anyway.
        $project->write('.porta/recent-files.json', '{"paths": {"1": "/a.php", "2": "/b.php"}}');
        $this->assertSame(['/a.php', '/b.php'], new RecentFilesStore($project->dir)->all());
    }

    public function testALongSAVEDListIsCappedOnTheWayIn(): void
    {
        // A file hand-edited, or written by a future version with a deeper cap:
        // the cap is the store's promise, not the writer's.
        $project = new TempProject();
        $paths = array_map(static fn (int $i): string => "/f{$i}.php", range(start: 0, end: 49));
        $project->write('.porta/recent-files.json', json_encode(['paths' => $paths], flags: \JSON_THROW_ON_ERROR));

        $this->assertCount(32, new RecentFilesStore($project->dir)->all());
    }
}
