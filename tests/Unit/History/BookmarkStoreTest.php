<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\History;

use PHPUnit\Framework\TestCase;
use Porta\Editor\Bookmarks;
use Porta\History\BookmarkPersistence;
use Porta\History\BookmarkStore;
use Porta\Tests\Support\TempProject;

final class BookmarkStoreTest extends TestCase
{
    public function testRoundTripsThroughTheProjectFile(): void
    {
        $project = new TempProject();
        $project->write('src/A.php', "<?php\n");
        $store = new BookmarkStore($project->dir);
        $marks = [$project->path('src/A.php') => [2, 40]];

        $this->assertTrue($store->save($marks));
        $this->assertFileExists($project->path('.porta/bookmarks.json'));
        $this->assertSame($marks, $store->load());
    }

    public function testAnEmptySetRemovesTheFileRatherThanLeavingAnEmptyOne(): void
    {
        // Otherwise clearing your last bookmark still leaves .porta/bookmarks.json
        // sitting in the project, which reads as state that is no longer there.
        $project = new TempProject();
        $project->write('src/A.php', "<?php\n");
        $store = new BookmarkStore($project->dir);
        $store->save([$project->path('src/A.php') => [1]]);

        $this->assertTrue($store->save([]));
        $this->assertFileDoesNotExist($project->path('.porta/bookmarks.json'));
        $this->assertSame([], $store->load(), 'and a missing file loads as no bookmarks, not an error');
    }

    public function testMarksOnAVanishedFileAreDroppedOnLoad(): void
    {
        $project = new TempProject();
        $project->write('src/A.php', "<?php\n");
        $project->write('src/Gone.php', "<?php\n");
        $store = new BookmarkStore($project->dir);
        $store->save([$project->path('src/A.php') => [1], $project->path('src/Gone.php') => [3]]);
        \unlink($project->path('src/Gone.php'));

        $this->assertSame([$project->path('src/A.php') => [1]], $store->load(), 'a deleted file cannot be jumped to');
    }

    public function testMalformedPayloadsLoadAsNothingRatherThanThrowing(): void
    {
        $project = new TempProject();
        $project->write('src/A.php', "<?php\n");
        $file = $project->path('.porta/bookmarks.json');
        \mkdir(\dirname($file), permissions: 0o777, recursive: true);

        \file_put_contents($file, data: '{"bookmarks":"nope"}');
        $this->assertSame([], new BookmarkStore($project->dir)->load(), 'bookmarks must be a map');

        \file_put_contents($file, \json_encode(['bookmarks' => [$project->path('src/A.php') => 'nope']], \JSON_THROW_ON_ERROR));
        $this->assertSame([], new BookmarkStore($project->dir)->load(), 'a file entry must be a list');

        \file_put_contents($file, \json_encode(['bookmarks' => [$project->path('src/A.php') => [-1, 'x', 2]]], \JSON_THROW_ON_ERROR));
        $this->assertSame([$project->path('src/A.php') => [2]], new BookmarkStore($project->dir)->load(), 'non-lines are dropped, the rest survives');

        \file_put_contents($file, \json_encode(['bookmarks' => [$project->path('src/A.php') => [-1]]], \JSON_THROW_ON_ERROR));
        $this->assertSame([], new BookmarkStore($project->dir)->load(), 'a file left with no usable line is dropped whole');
    }

    public function testPersistenceRestoresIntoTheLiveSetAndSavesItBack(): void
    {
        $project = new TempProject();
        $project->write('src/A.php', "<?php\n");
        $store = new BookmarkStore($project->dir);
        $store->save([$project->path('src/A.php') => [5]]);

        $bookmarks = new Bookmarks();
        $persistence = new BookmarkPersistence($store, $bookmarks);
        $persistence->restore();
        $this->assertSame([5], $bookmarks->linesFor($project->path('src/A.php')));

        $bookmarks->toggle($project->path('src/A.php'), 8);
        $persistence->save();

        $this->assertSame([$project->path('src/A.php') => [5, 8]], $store->load(), 'the session boundary wrote what the session held');
    }

    /**
     * Two windows on one project: each loaded the file once, each writes on
     * quit. The second to quit keeps what the first wrote — an idle one by
     * writing nothing, a busy one by putting in only its own changes.
     */
    public function testTwoWindowsKeepEachOthersBookmarks(): void
    {
        $project = new TempProject();
        $a = $project->write('src/A.php', "<?php\n");
        $b = $project->write('src/B.php', "<?php\n");
        $store = new BookmarkStore($project->dir);
        $store->save([$a => [1]]);
        [$first, $firstMarks] = [new BookmarkPersistence($store, $m1 = new Bookmarks()), $m1];
        [$second, $secondMarks] = [new BookmarkPersistence($store, $m2 = new Bookmarks()), $m2];
        $first->restore();
        $second->restore();

        $firstMarks->toggle($a, 5);
        $first->save();
        $second->save();
        $this->assertSame([$a => [1, 5]], $store->load(), 'the idle window took nothing away');

        $secondMarks->toggle($b, 2);
        $secondMarks->toggle($a, 1); // removes the one it loaded
        $second->save();
        $this->assertSame([$a => [5], $b => [2]], $store->load(), 'its own changes, over the other\'s');
    }

    /**
     * Line 0 is a real bookmark — the first line of a file. Rejecting it here
     * would silently lose a mark that {@see \Porta\Editor\Bookmarks} was happy
     * to set, and only for that one line.
     */
    public function testLineZeroSurvivesTheRoundTrip(): void
    {
        $project = new TempProject();
        $project->write('src/A.php', "<?php\n");
        $store = new BookmarkStore($project->dir);
        $store->save([$project->path('src/A.php') => [0, 4]]);

        $this->assertSame([$project->path('src/A.php') => [0, 4]], $store->load());
    }

    /**
     * A vanished file is SKIPPED, not the end of the load: with the files in
     * this order, stopping at the first one would drop every mark behind it.
     */
    public function testAVanishedFileDoesNotTakeTheFilesAfterItWithIt(): void
    {
        $project = new TempProject();
        $project->write('a-gone.php', "<?php\n");
        $project->write('b-kept.php', "<?php\n");
        $project->write('c-kept.php', "<?php\n");
        $store = new BookmarkStore($project->dir);
        $store->save([
            $project->path('a-gone.php') => [1],
            $project->path('b-kept.php') => [2],
            $project->path('c-kept.php') => [3],
        ]);
        \unlink($project->path('a-gone.php'));

        $this->assertSame(
            [$project->path('b-kept.php') => [2], $project->path('c-kept.php') => [3]],
            $store->load(),
            'both survivors, not just the first',
        );
    }

    /** A file entry keyed like an object still reads as a list of lines. */
    public function testAnObjectShapedLineListIsStillRead(): void
    {
        $project = new TempProject();
        $project->write('src/A.php', "<?php\n");
        $file = $project->path('.porta/bookmarks.json');
        \mkdir(\dirname($file), permissions: 0o777, recursive: true);
        \file_put_contents($file, (string) \json_encode(['bookmarks' => [$project->path('src/A.php') => ['1' => 7, '0' => 2]]], \JSON_THROW_ON_ERROR));

        $this->assertSame([$project->path('src/A.php') => [7, 2]], new BookmarkStore($project->dir)->load());
    }
}
