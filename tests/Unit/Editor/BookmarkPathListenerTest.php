<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\TestCase;
use Porta\Editor\BookmarkDecorations;
use Porta\Editor\BookmarkPathListener;
use Porta\Editor\Bookmarks;
use Porta\Event\FileDeleted;
use Porta\Widget\CodeEditorWidget;

final class BookmarkPathListenerTest extends TestCase
{
    public function testADeletedFilesBookmarksAreRetired(): void
    {
        $bookmarks = new Bookmarks();
        $bookmarks->toggle('/p/gone.php', 3);
        $bookmarks->toggle('/p/kept.php', 1);
        $editor = new CodeEditorWidget();

        new BookmarkPathListener($bookmarks, new BookmarkDecorations($bookmarks, $editor->decorations))
            ->onFileDeleted(new FileDeleted('/p/gone.php'));

        $this->assertSame(['/p/kept.php' => [1]], $bookmarks->all());
    }
}
