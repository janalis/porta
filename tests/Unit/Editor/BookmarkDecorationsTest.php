<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\TestCase;
use Porta\Editor\BookmarkDecorations;
use Porta\Editor\Bookmarks;
use Porta\Editor\Buffer;
use Porta\Editor\DiagnosticSeverity;
use Porta\Editor\Document;
use Porta\Event\BufferSwitched;
use Porta\Ui\Glyphs;
use Porta\Widget\CodeEditorWidget;
use Symfony\Component\Tui\Ansi\AnsiUtils;
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
        return \implode("\n", $editor->render(new RenderContext(40, 8)));
    }

    public function testApplyPaintsOnlyTheMarkedLine(): void
    {
        $editor = $this->editor();
        $bookmarks = new Bookmarks();
        $bookmarks->toggle('/p/a.php', 1);

        new BookmarkDecorations($bookmarks, $editor->decorations)->apply('/p/a.php');

        $lines = $editor->render(new RenderContext(40, 8));
        // The gutter alone: the stripe in the last column draws the diamond
        // too, on whichever row the line lands on in the file's overview.
        $gutter = static fn (string $row): string => AnsiUtils::stripAnsiCodes(AnsiUtils::sliceByColumn($row, 0, 8));
        $this->assertStringContainsString(Glyphs::BOOKMARK, $gutter($lines[1]), 'the marked line carries the diamond');
        $this->assertStringNotContainsString(Glyphs::BOOKMARK, $gutter($lines[2]), 'its neighbour does not');
    }

    public function testAMarkSURVIVESADiagnosticOnTheSameLine(): void
    {
        // The marker column shows one glyph, and the diagnostic used to outrank
        // the bookmark — so a single HINT, the weakest severity there is, made
        // the mark invisible. The bookmark wins because it is the only one of
        // the two with nowhere else to show: a diagnostic also colours the line
        // number, paints the error stripe, writes itself inline at the end of
        // the line and lists itself in the Problems panel.
        $editor = $this->editor();
        $bookmarks = new Bookmarks();
        $bookmarks->toggle('/p/a.php', 1);
        new BookmarkDecorations($bookmarks, $editor->decorations)->apply('/p/a.php');

        $editor->decorations->setDiagnostics([1 => [['col' => 0, 'endCol' => 3, 'message' => 'unused', 'severity' => DiagnosticSeverity::Hint]]]);

        $lines = $editor->render(new RenderContext(40, 8));
        $this->assertStringContainsString(Glyphs::BOOKMARK, $lines[1], 'the mark is still there under a hint');

        // And an ERROR does not take it either — the strongest severity is the
        // case someone would reach for to argue the other way.
        $editor->decorations->setDiagnostics([1 => [['col' => 0, 'endCol' => 3, 'message' => 'broken', 'severity' => DiagnosticSeverity::Error]]]);

        $this->assertStringContainsString(
            Glyphs::BOOKMARK,
            $editor->render(new RenderContext(40, 8))[1],
            'nor under an error',
        );
    }

    /** A conversation's glyph takes the marker column over a diagnostic, and yields it to a bookmark. */
    public function testACommentMarkSitsBetweenTheBookmarkAndTheDiagnostic(): void
    {
        $editor = $this->editor();
        $gutter = static fn (string $row): string => AnsiUtils::stripAnsiCodes(AnsiUtils::sliceByColumn($row, 0, 8));
        $editor->decorations->setCommentMarks([1 => 'alice: rename', 2 => 'bob: why?']);
        $editor->decorations->setDiagnostics([1 => [['col' => 0, 'endCol' => 3, 'message' => 'broken', 'severity' => DiagnosticSeverity::Error]]]);

        $lines = $editor->render(new RenderContext(40, 8));
        $this->assertStringContainsString(Glyphs::COMMENT, $gutter($lines[1]), 'over the error');
        $this->assertStringContainsString(Glyphs::COMMENT, $gutter($lines[2]));
        $this->assertStringNotContainsString(Glyphs::COMMENT, $gutter($lines[0]), 'not on an unmarked line');

        $bookmarks = new Bookmarks();
        $bookmarks->toggle('/p/a.php', 2);
        new BookmarkDecorations($bookmarks, $editor->decorations)->apply('/p/a.php');
        $this->assertStringContainsString(Glyphs::BOOKMARK, $gutter($editor->render(new RenderContext(40, 8))[2]), 'the bookmark outranks it');
    }

    public function testASwitchPaintsTheNewFilesOwnMarksAndNotTheOldFiles(): void
    {
        // The gutter is shared, so showing b.php has to CLEAR a.php's marks
        // rather than leave them standing over unrelated lines.
        $editor = $this->editor();
        $bookmarks = new Bookmarks();
        $bookmarks->toggle('/p/a.php', 1);
        $decorations = new BookmarkDecorations($bookmarks, $editor->decorations);

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
        $decorations = new BookmarkDecorations($bookmarks, $editor->decorations);
        $decorations->apply('/p/a.php');

        // A DIFFERENT path, so skipping and repainting are distinguishable:
        // were the viewer applied, it would CLEAR a.php's marks.
        $decorations->onBufferSwitched(new BufferSwitched(new Buffer('/p/graph.dot', new Document(''), paneId: 'graph')));

        $this->assertStringContainsString(Glyphs::BOOKMARK, $this->painted($editor), 'a viewer has no gutter to repaint, so it leaves the editor alone');
    }
}
