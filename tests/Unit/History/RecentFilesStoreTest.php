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
        touch($file, mtime: 1_000_000_000); // backdated: mtime resolves to the second
        clearstatcache();
        $store->record('/b.php');

        $this->assertSame(['/b.php', '/a.php'], $store->all());
        $this->assertSame(1_000_000_000, filemtime($file), 'no rewrite for a re-show');
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

    public function testARenamedFileIsFOLLOWEDNotLost(): void
    {
        // Otherwise the old path stops existing on disk and the switcher drops
        // it silently — the file you renamed a minute ago being exactly the one
        // you are most likely to want back.
        $project = new TempProject();
        $store = new RecentFilesStore($project->dir);
        $store->record('/a.php');
        $store->record('/b.php');

        $store->rename('/a.php', '/renamed.php');

        self::assertSame(['/b.php', '/renamed.php'], $store->all(), 'in place, not moved to the front');
        self::assertSame(['/b.php', '/renamed.php'], new RecentFilesStore($project->dir)->all(), 'and written down');
    }

    public function testRenamingONTOAPathAlreadyListedLeavesOneEntry(): void
    {
        // They are the same file now, and the better of the two positions is
        // the one to keep.
        $project = new TempProject();
        $store = new RecentFilesStore($project->dir);
        $store->record('/old.php');
        $store->record('/target.php');

        $store->rename('/old.php', '/target.php');

        self::assertSame(['/target.php'], $store->all());
    }

    public function testRenamingAFileTheListNeverSawChangesNothing(): void
    {
        $project = new TempProject();
        $store = new RecentFilesStore($project->dir);
        $store->record('/a.php');

        // Backdated rather than compared against "now": two writes inside one
        // second share an mtime, so a same-second rewrite would slip past.
        $file = $project->dir.'/.porta/recent-files.json';
        $backdated = time() - 3600;
        touch($file, $backdated);
        clearstatcache();

        $store->rename('/elsewhere.php', '/other.php');

        self::assertSame(['/a.php'], $store->all());
        clearstatcache();
        self::assertSame($backdated, filemtime($file), 'and nothing was rewritten');
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

    public function testForgetDropsADeletedFileAndPersistsTheChange(): void
    {
        // The deleted file is in the MIDDLE, which is what makes the list a
        // list: filtering preserves keys, and dropping the last entry alone
        // would leave 0..n-1 intact and hide a missing re-index completely.
        $project = new TempProject();
        $store = new RecentFilesStore($project->dir);
        $store->record('/p/oldest.php');
        $store->record('/p/gone.php');
        $store->record('/p/newest.php');

        $store->forget('/p/gone.php');

        $this->assertSame(['/p/newest.php', '/p/oldest.php'], $store->all());
        $this->assertSame(['/p/newest.php', '/p/oldest.php'], new RecentFilesStore($project->dir)->all(), 'and the next session agrees');
    }

    public function testForgetTakesEveryFileUnderADeletedDirectory(): void
    {
        $project = new TempProject();
        $store = new RecentFilesStore($project->dir);
        $store->record('/p/old/a.php');
        $store->record('/p/olden/b.php');

        $store->forget('/p/old');

        $this->assertSame(['/p/olden/b.php'], $store->all());
    }

    public function testForgettingAPathThatIsNotListedDoesNotRewriteTheFile(): void
    {
        // Every delete anywhere in the project reaches this listener, and most
        // of them touch nothing here.
        $project = new TempProject();
        $store = new RecentFilesStore($project->dir);
        $store->record('/p/a.php');
        // Backdated rather than read as-is: mtime has one-second resolution, so
        // a rewrite in the same second as the setup is invisible to a plain
        // before/after comparison.
        $file = $project->dir.'/.porta/recent-files.json';
        touch($file, mtime: 1_000_000_000);
        clearstatcache();

        $store->forget('/p/b.php');

        $this->assertSame(['/p/a.php'], $store->all());
        $this->assertSame(1_000_000_000, filemtime($file), 'no write for a delete that changed nothing');
    }

    public function testRenamingADirectoryMovesTheEntriesInsideIt(): void
    {
        $project = new TempProject();
        $store = new RecentFilesStore($project->dir);
        $store->record('/p/olden/b.php');
        $store->record('/p/old/a.php');

        $store->rename('/p/old', '/p/new');

        $this->assertSame(['/p/new/a.php', '/p/olden/b.php'], $store->all());
    }

    public function testARenameThatMovesNothingHereDoesNotRewriteTheFile(): void
    {
        // Every rename anywhere in the project reaches this listener.
        $project = new TempProject();
        $store = new RecentFilesStore($project->dir);
        $store->record('/p/a.php');
        $file = $project->dir.'/.porta/recent-files.json';
        touch($file, mtime: 1_000_000_000); // backdated: mtime resolves to the second
        clearstatcache();

        $store->rename('/p/elsewhere.php', '/p/moved.php');

        $this->assertSame(['/p/a.php'], $store->all());
        $this->assertSame(1_000_000_000, filemtime($file), 'no write for a rename that changed nothing');
    }
}
