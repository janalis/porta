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
}
