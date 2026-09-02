<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\TestCase;
use Porta\Editor\BookmarkRows;
use Porta\Editor\Bookmarks;
use Porta\Editor\BufferManager;
use Porta\Support\ProjectPaths;
use Porta\Tests\Support\TempProject;

final class BookmarkRowsTest extends TestCase
{
    public function testReadsTheLineFromTheOpenBufferAndSortsByPathThenLine(): void
    {
        $buffers = new BufferManager();
        $buffers->open('/p/src/B.php', "<?php\nsecond file;\n");
        $buffers->open('/p/src/A.php', "<?php\n    indented line;\nthird line;\n");
        $bookmarks = new Bookmarks();
        $bookmarks->toggle('/p/src/B.php', 1);
        $bookmarks->toggle('/p/src/A.php', 2);
        $bookmarks->toggle('/p/src/A.php', 1);

        $rows = new BookmarkRows($bookmarks, $buffers, new ProjectPaths('/p'))->build();

        $this->assertSame(
            [
                ['path' => 'src/A.php', 'line' => 1, 'text' => 'indented line;'],
                ['path' => 'src/A.php', 'line' => 2, 'text' => 'third line;'],
                ['path' => 'src/B.php', 'line' => 1, 'text' => 'second file;'],
            ],
            $rows,
            'sorted by path then line, each row showing its own trimmed text',
        );
    }

    public function testAClosedFilesLineIsReadFromDisk(): void
    {
        // A bookmark outlives the buffer that made it, and a list of bare line
        // numbers with no text would be no help at all.
        $project = new TempProject();
        $project->write('src/A.php', "<?php\nremembered line;\n");
        $bookmarks = new Bookmarks();
        $bookmarks->toggle($project->path('src/A.php'), 1);

        $rows = new BookmarkRows($bookmarks, new BufferManager(), new ProjectPaths($project->dir))->build();

        $this->assertSame([['path' => 'src/A.php', 'line' => 1, 'text' => 'remembered line;']], $rows);
    }

    public function testAnUnreadableFileOrAnOutOfRangeLineStillYieldsAJumpableRow(): void
    {
        $bookmarks = new Bookmarks();
        $bookmarks->toggle('/p/src/Gone.php', 3);

        $rows = new BookmarkRows($bookmarks, new BufferManager(), new ProjectPaths('/p'))->build();

        $this->assertSame([['path' => 'src/Gone.php', 'line' => 3, 'text' => '']], $rows, 'no text to show, but the row is not dropped');
    }

    public function testNoBookmarksYieldNoRows(): void
    {
        $this->assertSame([], new BookmarkRows(new Bookmarks(), new BufferManager(), new ProjectPaths('/p'))->build());
    }
}
