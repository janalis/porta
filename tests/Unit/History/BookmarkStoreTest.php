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
        unlink($project->path('src/Gone.php'));

        $this->assertSame([$project->path('src/A.php') => [1]], $store->load(), 'a deleted file cannot be jumped to');
    }

    public function testMalformedPayloadsLoadAsNothingRatherThanThrowing(): void
    {
        $project = new TempProject();
        $project->write('src/A.php', "<?php\n");
        $file = $project->path('.porta/bookmarks.json');
        mkdir(\dirname($file), permissions: 0o777, recursive: true);

        file_put_contents($file, data: '{"bookmarks":"nope"}');
        $this->assertSame([], new BookmarkStore($project->dir)->load(), 'bookmarks must be a map');

        file_put_contents($file, json_encode(['bookmarks' => [$project->path('src/A.php') => 'nope']]));
        $this->assertSame([], new BookmarkStore($project->dir)->load(), 'a file entry must be a list');

        file_put_contents($file, json_encode(['bookmarks' => [$project->path('src/A.php') => [-1, 'x', 2]]]));
        $this->assertSame([$project->path('src/A.php') => [2]], new BookmarkStore($project->dir)->load(), 'non-lines are dropped, the rest survives');

        file_put_contents($file, json_encode(['bookmarks' => [$project->path('src/A.php') => [-1]]]));
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
}
