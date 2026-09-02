<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\TestCase;
use Porta\Editor\BookmarkDecorations;
use Porta\Editor\Bookmarks;
use Porta\Editor\Buffer;
use Porta\Editor\Document;
use Porta\Event\BufferSwitched;
use Porta\Ui\Glyphs;
use Porta\Widget\CodeEditorWidget;
use Symfony\Component\Tui\Render\RenderContext;

final class BookmarkDecorationsTest extends TestCase
{
    private function editor(): CodeEditorWidget
    {
        $editor = new CodeEditorWidget();
        $editor->showDocument('/p/a.php', new Document("<?php\n\$a = 1;\n\$b = 2;\n"));

        return $editor;
    }

    private function painted(CodeEditorWidget $editor): string
    {
        return implode("\n", $editor->render(new RenderContext(40, 8)));
    }

    public function testApplyPaintsOnlyTheMarkedLine(): void
    {
        $editor = $this->editor();
        $bookmarks = new Bookmarks();
        $bookmarks->toggle('/p/a.php', 1);

        new BookmarkDecorations($bookmarks, $editor)->apply('/p/a.php');

        $lines = $editor->render(new RenderContext(40, 8));
        $this->assertStringContainsString(Glyphs::BOOKMARK, $lines[1], 'the marked line carries the diamond');
        $this->assertStringNotContainsString(Glyphs::BOOKMARK, $lines[2], 'its neighbour does not');
    }

    public function testASwitchPaintsTheNewFilesOwnMarksAndNotTheOldFiles(): void
    {
        // The gutter is shared, so showing b.php has to CLEAR a.php's marks
        // rather than leave them standing over unrelated lines.
        $editor = $this->editor();
        $bookmarks = new Bookmarks();
        $bookmarks->toggle('/p/a.php', 1);
        $decorations = new BookmarkDecorations($bookmarks, $editor);

        $decorations->onBufferSwitched(new BufferSwitched(new Buffer('/p/a.php', new Document(''))));
        $this->assertStringContainsString(Glyphs::BOOKMARK, $this->painted($editor));

        $decorations->onBufferSwitched(new BufferSwitched(new Buffer('/p/b.php', new Document(''))));
        $this->assertStringNotContainsString(Glyphs::BOOKMARK, $this->painted($editor), 'an unbookmarked file shows a clean gutter');
    }

    public function testAViewerTabIsSkipped(): void
    {
        $editor = $this->editor();
        $bookmarks = new Bookmarks();
        $bookmarks->toggle('/p/a.php', 1);
        $decorations = new BookmarkDecorations($bookmarks, $editor);
        $decorations->apply('/p/a.php');

        // A DIFFERENT path, so skipping and repainting are distinguishable:
        // were the viewer applied, it would CLEAR a.php's marks.
        $decorations->onBufferSwitched(new BufferSwitched(new Buffer('/p/graph.dot', new Document(''), paneId: 'graph')));

        $this->assertStringContainsString(Glyphs::BOOKMARK, $this->painted($editor), 'a viewer has no gutter to repaint, so it leaves the editor alone');
    }
}
