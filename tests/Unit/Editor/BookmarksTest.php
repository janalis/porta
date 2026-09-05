<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\TestCase;
use Porta\Editor\Bookmarks;

final class BookmarksTest extends TestCase
{
    public function testToggleSetsAndClearsPerFile(): void
    {
        $marks = new Bookmarks();

        $this->assertTrue($marks->toggle('/p/a.php', 9));
        $this->assertTrue($marks->toggle('/p/a.php', 3));
        $this->assertTrue($marks->toggle('/p/b.php', 3));
        $this->assertSame([3, 9], $marks->linesFor('/p/a.php'), 'ascending, whatever order they were set in');
        $this->assertSame([3], $marks->linesFor('/p/b.php'), 'files are isolated');

        $this->assertFalse($marks->toggle('/p/a.php', 9), 'a second toggle clears');
        $this->assertSame([3], $marks->linesFor('/p/a.php'));
        $this->assertSame([], $marks->linesFor('/p/never-marked.php'));
    }

    public function testClearingAFilesLastMarkDropsTheFileEntirely(): void
    {
        $marks = new Bookmarks();
        $marks->toggle('/p/a.php', 0);
        $marks->toggle('/p/b.php', 1);

        $marks->toggle('/p/a.php', 0);

        $this->assertSame(['/p/b.php' => [1]], $marks->all(), 'an emptied file leaves no husk behind to persist or list');
    }

    public function testLineZeroIsMarkableLikeAnyOther(): void
    {
        // The set is keyed by line, so a falsy 0 must not read as absent.
        $marks = new Bookmarks();

        $this->assertTrue($marks->toggle('/p/a.php', 0));
        $this->assertSame([0], $marks->linesFor('/p/a.php'));
        $this->assertFalse($marks->toggle('/p/a.php', 0), 'and toggles off again rather than re-setting');
        $this->assertSame([], $marks->all());
    }

    public function testReplaceAllDiscardsWhatWasThere(): void
    {
        $marks = new Bookmarks();
        $marks->toggle('/p/stale.php', 4);

        $marks->replaceAll(['/p/a.php' => [7, 2]]);

        $this->assertSame(['/p/a.php' => [2, 7]], $marks->all(), 'the restored set replaces, and is sorted like any other');
        $this->assertSame([], $marks->linesFor('/p/stale.php'));
    }

    public function testRenameCarriesAFilesMarksToItsNewPath(): void
    {
        $marks = new Bookmarks();
        $marks->toggle('/p/Old.php', 3);
        $marks->toggle('/p/Old.php', 8);
        $marks->toggle('/p/Other.php', 1);

        $marks->rename('/p/Old.php', '/p/New.php');

        $this->assertSame([3, 8], $marks->linesFor('/p/New.php'), 'the marks came with it');
        $this->assertSame([], $marks->linesFor('/p/Old.php'), 'and nothing is left on the dead path');
        $this->assertSame([1], $marks->linesFor('/p/Other.php'), 'its neighbour is untouched');
    }

    public function testRenamingAnUnmarkedFileAddsNoEmptyEntry(): void
    {
        // Every rename in the project dispatches the fact; an unmarked file
        // must not leave a husk behind to persist or list.
        $marks = new Bookmarks();
        $marks->toggle('/p/Other.php', 1);

        $marks->rename('/p/Unmarked.php', '/p/Renamed.php');

        $this->assertSame(['/p/Other.php' => [1]], $marks->all());
    }

    public function testForgetDropsADeletedFilesMarks(): void
    {
        $marks = new Bookmarks();
        $marks->toggle('/p/gone.php', 2);
        $marks->toggle('/p/kept.php', 4);

        $marks->forget('/p/gone.php');

        $this->assertSame(['/p/kept.php' => [4]], $marks->all());
    }

    public function testForgetTakesEveryFileUNDERADeletedDirectory(): void
    {
        // The tree deletes a folder with one confirmation and the whole subtree
        // goes; the store is keyed by the FILES, so equality would keep every
        // one of them.
        // A survivor FIRST, on purpose: the walk has to keep going past a key
        // it does not match, and a fixture whose matches all come first cannot
        // tell that from stopping at the first one.
        $marks = new Bookmarks();
        $marks->toggle('/p/keep/z.php', 1);
        $marks->toggle('/p/old/a.php', 1);
        $marks->toggle('/p/old/deep/b.php', 1);
        $marks->toggle('/p/olden/c.php', 1);

        $marks->forget('/p/old');

        $this->assertSame(
            ['/p/keep/z.php' => [1], '/p/olden/c.php' => [1]],
            $marks->all(),
            'a sibling that merely SHARES A PREFIX with the deleted directory is a different directory',
        );
    }

    public function testForgettingAPathNothingIsMarkedUnderChangesNothing(): void
    {
        $marks = new Bookmarks();
        $marks->toggle('/p/a.php', 1);

        $marks->forget('/p/b.php');

        $this->assertSame(['/p/a.php' => [1]], $marks->all());
    }

    public function testRenamingADIRECTORYMovesTheMarksInsideIt(): void
    {
        // The tree offers Rename… on a folder, and the store is keyed by the
        // files in it — so an exact-match rename left every one of them on a
        // path that no longer exists.
        $marks = new Bookmarks();
        $marks->toggle('/p/keep/z.php', 0);
        $marks->toggle('/p/old/a.php', 1);
        $marks->toggle('/p/old/deep/b.php', 2);
        $marks->toggle('/p/olden/c.php', 3);

        $marks->rename('/p/old', '/p/new');

        $this->assertSame(
            [
                '/p/keep/z.php' => [0],
                '/p/olden/c.php' => [3],
                '/p/new/a.php' => [1],
                '/p/new/deep/b.php' => [2],
            ],
            $marks->all(),
            'the subtree moved; a prefix-sharing sibling did not',
        );
    }
}
